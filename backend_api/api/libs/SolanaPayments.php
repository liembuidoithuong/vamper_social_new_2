<?php
require_once __DIR__ . '/../../libs/AdminTools.php';
require_once __DIR__ . '/PolygonUsdcPayments.php';

const SOLANA_MAINNET_GENESIS_HASH = '5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d';
const SOLANA_DEVNET_GENESIS_HASH = 'EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG';
const SOLANA_MAINNET_USDC_MINT = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
const SOLANA_DEVNET_USDC_MINT = '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU';
const SOLANA_MAINNET_USDT_MINT = 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB';

function solanaPaymentSettings(): array
{
    $networkKey = strtolower(trim((string)getenv('SOLANA_NETWORK')));
    $networks = [
        'mainnet-beta' => [
            'network' => 'Solana Mainnet',
            'genesis_hash' => SOLANA_MAINNET_GENESIS_HASH,
            'usdc_mint' => SOLANA_MAINNET_USDC_MINT,
            'usdt_mint' => SOLANA_MAINNET_USDT_MINT,
        ],
        'devnet' => [
            'network' => 'Solana Devnet (testnet)',
            'genesis_hash' => SOLANA_DEVNET_GENESIS_HASH,
            'usdc_mint' => SOLANA_DEVNET_USDC_MINT,
            'usdt_mint' => trim((string)getenv('SOLANA_USDT_MINT')),
        ],
    ];
    $network = $networks[$networkKey] ?? null;
    $enabledFlag = in_array(
        strtolower(trim((string)getenv('SOLANA_ENABLED'))),
        ['1', 'true'],
        true
    );
    $rpcUrl = trim((string)getenv('SOLANA_RPC_URL'));
    $recipient = trim((string)getenv('SOLANA_RECEIVER_ADDRESS'));
    $configured = $enabledFlag
        && is_array($network)
        && function_exists('curl_init')
        && filter_var($rpcUrl, FILTER_VALIDATE_URL) !== false
        && strtolower((string)parse_url($rpcUrl, PHP_URL_SCHEME)) === 'https'
        && preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $recipient) === 1;

    $usdcMint = $network['usdc_mint'] ?? null;
    $usdtMint = $network['usdt_mint'] ?? null;
    return [
        'enabled' => $configured,
        'network_key' => $networkKey,
        'network' => $network['network'] ?? 'Solana',
        'genesis_hash' => $network['genesis_hash'] ?? null,
        'rpc_url' => $configured ? $rpcUrl : null,
        'recipient_address' => $configured ? $recipient : null,
        'usdc_mint' => is_string($usdcMint)
            && preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $usdcMint) === 1
                ? $usdcMint
                : null,
        'usdt_mint' => is_string($usdtMint)
            && preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $usdtMint) === 1
                ? $usdtMint
                : null,
    ];
}

function ensureSolanaPaymentSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS solana_checkout_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        checkout_id CHAR(36) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        network VARCHAR(32) NOT NULL,
        asset VARCHAR(8) NOT NULL,
        token_mint VARCHAR(44) NULL,
        recipient_address VARCHAR(44) NOT NULL,
        amount_base_units BIGINT UNSIGNED NOT NULL,
        amount_display VARCHAR(32) NOT NULL,
        amount_usd DECIMAL(10,2) NOT NULL,
        sol_price_micro_usd BIGINT UNSIGNED NULL,
        coins INT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        transaction_signature VARCHAR(100) NULL UNIQUE,
        slot BIGINT UNSIGNED NULL,
        transaction_id INT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        KEY idx_solana_checkout_user (user_id, created_at),
        KEY idx_solana_checkout_asset_amount (asset, amount_base_units, status),
        KEY idx_solana_checkout_status (status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS solana_payment_allocator (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('INSERT IGNORE INTO solana_payment_allocator (id) VALUES (1)');

    $db->exec("CREATE TABLE IF NOT EXISTS solana_price_cache (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        price_micro_usd BIGINT UNSIGNED NOT NULL,
        fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('INSERT IGNORE INTO solana_price_cache (id, price_micro_usd) VALUES (1, 0)');

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

function solanaRpc(array $settings, string $method, array $params = []): mixed
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Solana payments.');
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
        error_log('Solana RPC transport failed (cURL ' . $curlErrorNumber . '): ' . $curlError);
        throw new RuntimeException('Could not connect to the Solana network. Please retry.');
    }
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded) || array_key_exists('error', $decoded)) {
        $rpcCode = is_array($decoded) && is_array($decoded['error'] ?? null)
            ? (string)($decoded['error']['code'] ?? 'unknown')
            : 'unknown';
        error_log('Solana RPC request failed: method ' . $method . ', HTTP ' . $status . ', code ' . $rpcCode);
        throw new RuntimeException('Solana verification is temporarily unavailable. Please retry.');
    }

    return $decoded['result'] ?? null;
}

function assertSolanaNetwork(array $settings): void
{
    $genesisHash = solanaRpc($settings, 'getGenesisHash');
    if (!is_string($genesisHash) || !hash_equals($settings['genesis_hash'], $genesisHash)) {
        throw new RuntimeException('The configured RPC endpoint is not on the selected Solana network.');
    }
    $health = solanaRpc($settings, 'getHealth');
    if ($health !== 'ok') {
        throw new RuntimeException('The selected Solana RPC endpoint is not healthy.');
    }
    $balance = solanaRpc($settings, 'getBalance', [
        $settings['recipient_address'],
        ['commitment' => 'finalized'],
    ]);
    if (!is_array($balance) || !is_int($balance['value'] ?? null)) {
        throw new RuntimeException('The configured Solana receiving address is invalid.');
    }
}

function assertSolanaStablecoinMint(array $settings, string $mint): void
{
    $supply = solanaRpc($settings, 'getTokenSupply', [$mint, ['commitment' => 'finalized']]);
    $decimals = is_array($supply) ? ($supply['value']['decimals'] ?? null) : null;
    if (!is_int($decimals) || $decimals !== 6) {
        throw new RuntimeException('The configured Solana stablecoin mint is invalid or does not use six decimals.');
    }
}

function assertSolanaRecipientTokenAccount(array $settings, string $mint): void
{
    $accounts = solanaRpc($settings, 'getTokenAccountsByOwner', [
        $settings['recipient_address'],
        ['mint' => $mint],
        ['encoding' => 'jsonParsed', 'commitment' => 'finalized'],
    ]);
    if (is_array($accounts['value'] ?? null)) {
        foreach ($accounts['value'] as $entry) {
            $info = $entry['account']['data']['parsed']['info'] ?? null;
            if (
                is_array($info)
                && ($info['mint'] ?? null) === $mint
                && ($info['owner'] ?? null) === $settings['recipient_address']
                && ($info['tokenAmount']['decimals'] ?? null) === 6
            ) {
                return;
            }
        }
    }
    throw new RuntimeException(
        'The receiving wallet needs a token account for this asset before checkouts can be created.'
    );
}

function solanaFetchPriceMicroUsd(PDO $db): int
{
    $db->beginTransaction();
    try {
        $cacheQuery = $db->query(
            'SELECT price_micro_usd, UNIX_TIMESTAMP(fetched_at) AS fetched_epoch
             FROM solana_price_cache WHERE id = 1 FOR UPDATE'
        );
        $cached = $cacheQuery->fetch(PDO::FETCH_ASSOC);
        $fetchedAt = $cached ? (int)$cached['fetched_epoch'] : 0;
        if (
            $cached
            && (int)$cached['price_micro_usd'] > 0
            && $fetchedAt >= time() - 30
            && $fetchedAt <= time() + 30
        ) {
            $db->commit();
            return (int)$cached['price_micro_usd'];
        }

        $price = solanaRequestPriceMicroUsd();
        $db->prepare(
            'UPDATE solana_price_cache
             SET price_micro_usd = :price, fetched_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        )->execute([':price' => $price]);
        $db->commit();
        return $price;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function solanaRequestPriceMicroUsd(): int
{
    $url = 'https://api.coingecko.com/api/v3/simple/price'
        . '?ids=solana&vs_currencies=usd&include_last_updated_at=true';
    $headers = [
        'Accept: application/json',
        'User-Agent: VamperSocialPayments/1.0',
    ];
    $apiKey = trim((string)getenv('COINGECKO_API_KEY'));
    if ($apiKey !== '') {
        $headers[] = 'x-cg-demo-api-key: ' . $apiKey;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Unable to fetch SOL/USD quote (HTTP ' . $status . '): ' . $curlError);
        throw new RuntimeException('A current SOL/USD quote is unavailable. Please retry later.');
    }
    $decoded = json_decode($response, true);
    $price = $decoded['solana']['usd'] ?? null;
    $updatedAt = $decoded['solana']['last_updated_at'] ?? null;
    if (
        !is_numeric($price)
        || !is_int($updatedAt)
        || $updatedAt > time() + 30
        || time() - $updatedAt > 180
        || (float)$price <= 0
        || (float)$price > 10000
    ) {
        throw new RuntimeException('The SOL/USD quote is invalid or stale. Please retry later.');
    }

    $priceMicroUsd = (int)round((float)$price * 1000000);
    if ($priceMicroUsd <= 0) {
        throw new RuntimeException('The SOL/USD quote is invalid. Please retry later.');
    }
    return $priceMicroUsd;
}

function solanaFormatBaseUnits(int $baseUnits, int $decimals): string
{
    $scale = 10 ** $decimals;
    $whole = intdiv($baseUnits, $scale);
    $fraction = str_pad((string)($baseUnits % $scale), $decimals, '0', STR_PAD_LEFT);
    return $whole . '.' . $fraction;
}

function solanaParseAmountToBaseUnits(string $amount, int $decimals): int
{
    if (
        $decimals < 0
        || $decimals > 9
        || !preg_match('/^(0|[1-9][0-9]*)\\.([0-9]{1,' . $decimals . '})$/D', $amount, $parts)
    ) {
        throw new InvalidArgumentException('Invalid Solana payment amount.');
    }
    $fraction = str_pad($parts[2], $decimals, '0');
    return (int)($parts[1] . $fraction);
}

function solanaCalculateSolQuote(int $packageCents, int $priceMicroUsd): array
{
    if ($packageCents <= 0 || $priceMicroUsd <= 0 || $priceMicroUsd > 10000000000) {
        throw new InvalidArgumentException('Invalid package price or SOL/USD quote.');
    }
    $packageMicroUsd = $packageCents * 10000;
    $baseUnits = intdiv($packageMicroUsd * 1000000000 + $priceMicroUsd - 1, $priceMicroUsd);
    $maxSuffix = min(9999, intdiv(9999 * 1000000000, $priceMicroUsd));
    if ($maxSuffix < 1) {
        throw new RuntimeException('The SOL/USD price is too high to create a unique payment amount.');
    }
    return ['base_units' => $baseUnits, 'max_suffix' => $maxSuffix];
}

function solanaCheckoutId(): string
{
    return polygonUsdcCheckoutId();
}

function solanaValidateSignature(string $signature): bool
{
    return preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,100}$/D', $signature) === 1;
}

function solanaVerifyTransaction(
    array $settings,
    string $signature,
    string $asset,
    ?string $tokenMint,
    string $recipient,
    int $expectedBaseUnits,
    int $createdAt,
    int $expiresAt
): array {
    if (!solanaValidateSignature($signature)) {
        throw new DomainException('Enter a valid Solana transaction signature.');
    }
    $transaction = solanaRpc($settings, 'getTransaction', [
        $signature,
        [
            'commitment' => 'finalized',
            'encoding' => 'jsonParsed',
            'maxSupportedTransactionVersion' => 0,
        ],
    ]);
    if ($transaction === null) {
        return ['status' => 'pending'];
    }
    if (
        !is_array($transaction)
        || !is_int($transaction['blockTime'] ?? null)
        || !is_int($transaction['slot'] ?? null)
        || !is_array($transaction['meta'] ?? null)
        || ($transaction['meta']['err'] ?? null) !== null
        || !is_array($transaction['transaction']['signatures'] ?? null)
        || !isset($transaction['transaction']['signatures'][0])
        || !hash_equals($signature, (string)$transaction['transaction']['signatures'][0])
    ) {
        throw new DomainException('The Solana transaction did not succeed or its signature does not match.');
    }

    $blockTime = $transaction['blockTime'];
    if ($blockTime < $createdAt || $blockTime >= $expiresAt) {
        throw new DomainException('This payment was made outside its checkout validity period.');
    }

    $message = $transaction['transaction']['message'] ?? null;
    $instructions = is_array($message) ? ($message['instructions'] ?? []) : [];
    foreach (($transaction['meta']['innerInstructions'] ?? []) as $innerGroup) {
        if (is_array($innerGroup) && is_array($innerGroup['instructions'] ?? null)) {
            $instructions = array_merge($instructions, $innerGroup['instructions']);
        }
    }

    $actualBaseUnits = $asset === 'SOL'
        ? solanaSumNativeTransfers($instructions, $recipient)
        : solanaSumTokenTransfers(
            $transaction,
            $instructions,
            $recipient,
            (string)$tokenMint
        );
    if ($actualBaseUnits !== $expectedBaseUnits) {
        throw new DomainException(
            'No exact Solana transfer matching this order was found. Check the network, asset, recipient, and amount.'
        );
    }

    return ['status' => 'completed', 'slot' => $transaction['slot']];
}

function solanaSumNativeTransfers(array $instructions, string $recipient): int
{
    $total = 0;
    foreach ($instructions as $instruction) {
        $parsed = is_array($instruction) ? ($instruction['parsed'] ?? null) : null;
        $info = is_array($parsed) ? ($parsed['info'] ?? null) : null;
        if (
            !is_array($instruction)
            || ($instruction['program'] ?? null) !== 'system'
            || ($parsed['type'] ?? null) !== 'transfer'
            || !is_array($info)
            || ($info['destination'] ?? null) !== $recipient
        ) {
            continue;
        }
        $lamports = $info['lamports'] ?? null;
        if ((!is_int($lamports) && !is_string($lamports)) || !ctype_digit((string)$lamports)) {
            continue;
        }
        $amount = (int)$lamports;
        if ($amount > PHP_INT_MAX - $total) {
            throw new DomainException('The Solana transfer amount is invalid.');
        }
        $total += $amount;
    }
    return $total;
}

function solanaSumTokenTransfers(
    array $transaction,
    array $instructions,
    string $recipient,
    string $tokenMint
): int {
    $accountKeys = $transaction['transaction']['message']['accountKeys'] ?? [];
    $postBalances = $transaction['meta']['postTokenBalances'] ?? [];
    $destinationAccounts = [];
    foreach ($postBalances as $balance) {
        if (!is_array($balance) || ($balance['owner'] ?? null) !== $recipient
            || ($balance['mint'] ?? null) !== $tokenMint
        ) {
            continue;
        }
        $accountIndex = $balance['accountIndex'] ?? null;
        if (!is_int($accountIndex) || !isset($accountKeys[$accountIndex])) {
            continue;
        }
        $key = is_array($accountKeys[$accountIndex])
            ? ($accountKeys[$accountIndex]['pubkey'] ?? null)
            : $accountKeys[$accountIndex];
        if (is_string($key)) {
            $destinationAccounts[$key] = true;
        }
    }

    $total = 0;
    foreach ($instructions as $instruction) {
        $parsed = is_array($instruction) ? ($instruction['parsed'] ?? null) : null;
        $info = is_array($parsed) ? ($parsed['info'] ?? null) : null;
        if (
            !is_array($instruction)
            || !in_array($instruction['program'] ?? null, ['spl-token', 'spl-token-2022'], true)
            || !in_array($parsed['type'] ?? null, ['transfer', 'transferChecked'], true)
            || !is_array($info)
            || !isset($destinationAccounts[$info['destination'] ?? ''])
            || (isset($info['mint']) && $info['mint'] !== $tokenMint)
        ) {
            continue;
        }
        $amount = $info['tokenAmount']['amount'] ?? $info['amount'] ?? null;
        if ((!is_int($amount) && !is_string($amount)) || !ctype_digit((string)$amount)) {
            continue;
        }
        $amount = (int)$amount;
        if ($amount > PHP_INT_MAX - $total) {
            throw new DomainException('The Solana stablecoin transfer amount is invalid.');
        }
        $total += $amount;
    }
    return $total;
}
