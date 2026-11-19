<?php
require_once __DIR__ . '/../../libs/AdminTools.php';

const POLYGON_USDC_CHAIN_ID = 137;
const POLYGON_USDC_CONTRACT = '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359';
const POLYGON_AMOY_CHAIN_ID = 80002;
const POLYGON_AMOY_USDC_CONTRACT = '0x41E94Eb019C0762f9Bfcf9Fb1E58725BfB0e7582';
const POLYGON_USDC_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

function polygonUsdcCoinPackages(): array
{
    return [100 => '4.99', 500 => '19.99', 1200 => '39.99', 3000 => '89.99'];
}

function polygonUsdcSettings(?PDO $db = null, ?string $networkOverride = null): array
{
    $envNetworkKey = strtolower(trim((string)getenv('POLYGON_USDC_NETWORK')));
    $networkKey = $networkOverride ?? (
        $db === null
            ? $envNetworkKey
            : (string)(adminSettingValue($db, 'crypto_polygon_active_network', $envNetworkKey) ?? '')
    );
    $networkKey = strtolower(trim($networkKey));
    $networks = [
        'mainnet' => [
            'chain_id' => POLYGON_USDC_CHAIN_ID,
            'network' => 'Polygon PoS',
            'token_contract' => POLYGON_USDC_CONTRACT,
        ],
        'amoy' => [
            'chain_id' => POLYGON_AMOY_CHAIN_ID,
            'network' => 'Polygon PoS Amoy (testnet)',
            'token_contract' => POLYGON_AMOY_USDC_CONTRACT,
        ],
    ];
    $network = $networks[$networkKey] ?? null;
    $profilePrefix = 'crypto_polygon_' . $networkKey;
    $useLegacyEnvironment = $networkKey === $envNetworkKey;
    $enabledValue = $db === null
        ? getenv('POLYGON_USDC_ENABLED')
        : adminSettingValue(
            $db,
            $profilePrefix . '_enabled',
            $useLegacyEnvironment ? (string)getenv('POLYGON_USDC_ENABLED') : '0'
        );
    $enabledFlag = in_array(
        strtolower(trim((string)$enabledValue)),
        ['1', 'true'],
        true
    );
    $rpcUrl = trim((string)($db === null
        ? getenv('POLYGON_RPC_URL')
        : adminSettingValue(
            $db,
            $profilePrefix . '_rpc_url',
            $useLegacyEnvironment ? (string)getenv('POLYGON_RPC_URL') : ''
        )));
    $recipient = trim((string)($db === null
        ? getenv('POLYGON_USDC_RECEIVER_ADDRESS')
        : adminSettingValue(
            $db,
            $profilePrefix . '_recipient_address',
            $useLegacyEnvironment ? (string)getenv('POLYGON_USDC_RECEIVER_ADDRESS') : ''
        )));
    $confirmations = filter_var(
        getenv('POLYGON_USDC_MIN_CONFIRMATIONS') ?: '64',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 64, 'max_range' => 10000]]
    );

    $rpcConfigured = filter_var($rpcUrl, FILTER_VALIDATE_URL) !== false
        && strtolower((string)parse_url($rpcUrl, PHP_URL_SCHEME)) === 'https';
    $recipientConfigured = preg_match('/^0x[a-fA-F0-9]{40}$/D', $recipient) === 1
        && strtolower($recipient) !== '0x' . str_repeat('0', 40);
    $configured = is_array($network)
        && function_exists('curl_init')
        && $rpcConfigured
        && $recipientConfigured
        && $confirmations !== false;

    return [
        'enabled' => $enabledFlag && $configured,
        'configured' => $configured,
        'network_key' => $networkKey,
        'rpc_url' => $rpcConfigured ? $rpcUrl : null,
        'recipient_address' => $recipientConfigured ? strtolower($recipient) : null,
        'chain_id' => $network['chain_id'] ?? null,
        'network' => $network['network'] ?? 'Polygon PoS',
        'token_contract' => $network['token_contract'] ?? null,
        'min_confirmations' => $confirmations !== false ? $confirmations : null,
    ];
}

function ensurePolygonUsdcPaymentSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS crypto_checkout_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        checkout_id CHAR(36) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        chain_id INT NOT NULL,
        token_contract CHAR(42) NOT NULL,
        recipient_address CHAR(42) NOT NULL,
        amount_usdc DECIMAL(12,6) NOT NULL,
        coins INT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        transaction_hash CHAR(66) NULL UNIQUE,
        transfer_log_index INT NULL,
        confirmed_block BIGINT UNSIGNED NULL,
        transaction_id INT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        KEY idx_crypto_checkout_user (user_id, created_at),
        KEY idx_crypto_checkout_status_coins (status, coins),
        KEY idx_crypto_checkout_status_amount (status, amount_usdc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS crypto_payment_allocator (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('INSERT IGNORE INTO crypto_payment_allocator (id) VALUES (1)');

    if (!adminTableExists($db, 'transactions')) {
        $db->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount_usd DECIMAL(10,2) NOT NULL,
            coins INT NOT NULL,
            gateway VARCHAR(50) DEFAULT 'Crypto',
            status VARCHAR(50) DEFAULT 'completed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    ensureVpcLedgerTable($db);
}

function polygonUsdcCheckoutId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function polygonUsdcAmountToBaseUnits(string $amount): string
{
    if (!preg_match('/^(0|[1-9][0-9]*)\\.([0-9]{1,6})$/D', $amount, $parts)) {
        throw new InvalidArgumentException('Invalid USDC package amount.');
    }

    $fraction = str_pad($parts[2], 6, '0');
    return ltrim($parts[1] . $fraction, '0') ?: '0';
}

function polygonUsdcFormatBaseUnits(int $baseUnits): string
{
    $whole = intdiv($baseUnits, 1000000);
    $fraction = str_pad((string)($baseUnits % 1000000), 6, '0', STR_PAD_LEFT);
    return $whole . '.' . $fraction;
}

function polygonUsdcRpc(array $settings, string $method, array $params = []): mixed
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for blockchain payments.');
    }

    $curl = curl_init($settings['rpc_url']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlErrorNumber = curl_errno($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        error_log('Polygon RPC transport failed (cURL ' . $curlErrorNumber . '): ' . $curlError);
        throw new RuntimeException('Could not connect to the Polygon network. Please retry.');
    }
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded) || array_key_exists('error', $decoded)) {
        $rpcCode = is_array($decoded) && is_array($decoded['error'] ?? null)
            ? (string)($decoded['error']['code'] ?? 'unknown')
            : 'unknown';
        error_log('Polygon RPC request failed: method ' . $method . ', HTTP ' . $status . ', code ' . $rpcCode);
        throw new RuntimeException('Polygon network verification is temporarily unavailable. Please retry.');
    }

    return $decoded['result'] ?? null;
}

function assertPolygonUsdcNetwork(array $settings): void
{
    $chainId = polygonUsdcRpc($settings, 'eth_chainId');
    $latestBlock = polygonUsdcRpc($settings, 'eth_blockNumber');
    if (
        !is_string($chainId)
        || hexdec($chainId) !== $settings['chain_id']
        || !is_string($latestBlock)
        || !preg_match('/^0x[0-9a-f]+$/iD', $latestBlock)
    ) {
        throw new RuntimeException('The configured RPC endpoint is not responding on the selected Polygon network.');
    }
}

function verifyPolygonUsdcTransfer(
    array $receipt,
    string $transactionHash,
    string $recipientAddress,
    string $amount,
    string $tokenContract
): array {
    if (
        !preg_match('/^0x[a-f0-9]{64}$/iD', $transactionHash)
        || !is_string($receipt['transactionHash'] ?? null)
        || strcasecmp($receipt['transactionHash'], $transactionHash) !== 0
        || ($receipt['status'] ?? null) !== '0x1'
    ) {
        throw new DomainException('The Polygon transaction is not successful.');
    }

    $logs = $receipt['logs'] ?? null;
    if (!is_array($logs)) {
        throw new DomainException('The Polygon transaction has no verifiable token transfers.');
    }

    $recipientTopic = '0x' . str_pad(strtolower(substr($recipientAddress, 2)), 64, '0', STR_PAD_LEFT);
    $expectedBaseUnits = polygonUsdcAmountToBaseUnits($amount);
    $expectedHexAmount = strtolower(dechex((int)$expectedBaseUnits));
    $matches = [];

    foreach ($logs as $index => $log) {
        if (!is_array($log) || ($log['removed'] ?? false) === true) {
            continue;
        }
        $topics = $log['topics'] ?? null;
        if (
            !is_array($topics)
            || count($topics) !== 3
            || !is_string($topics[0])
            || !is_string($topics[1])
            || !preg_match('/^0x[0-9a-f]{64}$/iD', $topics[1])
            || !is_string($topics[2])
            || strcasecmp((string)($log['address'] ?? ''), $tokenContract) !== 0
            || strcasecmp($topics[0], POLYGON_USDC_TRANSFER_TOPIC) !== 0
            || strcasecmp($topics[2], $recipientTopic) !== 0
        ) {
            continue;
        }

        $data = $log['data'] ?? null;
        $logIndex = $log['logIndex'] ?? dechex($index);
        if (
            !is_string($data)
            || !preg_match('/^0x[0-9a-f]{1,64}$/iD', $data)
            || !is_string($logIndex)
            || !preg_match('/^(?:0x)?[0-9a-f]{1,8}$/iD', $logIndex)
        ) {
            continue;
        }
        $actualHexAmount = ltrim(strtolower(substr($data, 2)), '0') ?: '0';
        if ($actualHexAmount === $expectedHexAmount) {
            $matches[] = [
                'log_index' => hexdec(str_starts_with(strtolower($logIndex), '0x')
                    ? substr($logIndex, 2)
                    : $logIndex),
            ];
        }
    }

    if (count($matches) !== 1) {
        throw new DomainException(
            'No unique USDC transfer matching this order was found. Check the network, token, recipient, and amount.'
        );
    }
    return $matches[0];
}

function verifyPolygonUsdcPayment(
    array $settings,
    string $transactionHash,
    string $recipientAddress,
    string $amount,
    string $tokenContract,
    int $createdAt,
    int $expiresAt
): array {
    assertPolygonUsdcNetwork($settings);

    $receipt = polygonUsdcRpc($settings, 'eth_getTransactionReceipt', [$transactionHash]);
    if ($receipt === null) {
        return ['status' => 'pending'];
    }
    if (
        !is_array($receipt)
        || !is_string($receipt['blockNumber'] ?? null)
        || !preg_match('/^0x[0-9a-f]+$/iD', $receipt['blockNumber'])
    ) {
        throw new RuntimeException('Polygon returned an invalid transaction receipt.');
    }

    $transfer = verifyPolygonUsdcTransfer(
        $receipt,
        $transactionHash,
        $recipientAddress,
        $amount,
        $tokenContract
    );
    $block = polygonUsdcRpc($settings, 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
    $blockTimestamp = is_array($block) ? ($block['timestamp'] ?? null) : null;
    if (!is_string($blockTimestamp) || !preg_match('/^0x[0-9a-f]+$/iD', $blockTimestamp)) {
        throw new RuntimeException('Polygon did not return the transaction block timestamp.');
    }
    $paidAt = hexdec($blockTimestamp);
    if ($paidAt < $createdAt || $paidAt >= $expiresAt) {
        throw new DomainException('This payment was made outside its checkout validity period.');
    }

    $latestBlock = polygonUsdcRpc($settings, 'eth_blockNumber');
    if (!is_string($latestBlock) || !preg_match('/^0x[0-9a-f]+$/iD', $latestBlock)) {
        throw new RuntimeException('Polygon did not return the latest block number.');
    }
    $confirmations = hexdec($latestBlock) - hexdec($receipt['blockNumber']) + 1;
    if ($confirmations < $settings['min_confirmations']) {
        return ['status' => 'confirming', 'confirmations' => max(0, $confirmations)];
    }

    return [
        'status' => 'completed',
        'confirmations' => $confirmations,
        'block_number' => hexdec($receipt['blockNumber']),
        'log_index' => $transfer['log_index'],
    ];
}
