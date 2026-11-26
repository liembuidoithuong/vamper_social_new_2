<?php
require_once __DIR__ . '/PolygonUsdcPayments.php';
require_once __DIR__ . '/SolanaPayments.php';

const CONFIGURED_CRYPTO_TYPES = [
    'evm_native',
    'evm_token',
    'solana_native',
    'solana_spl',
];

function ensureConfiguredCryptoSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS crypto_payment_methods (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        label VARCHAR(100) NOT NULL,
        asset VARCHAR(20) NOT NULL,
        asset_type VARCHAR(24) NOT NULL,
        network_key VARCHAR(48) NOT NULL,
        network_name VARCHAR(80) NOT NULL,
        is_testnet TINYINT(1) NOT NULL DEFAULT 1,
        chain_id BIGINT UNSIGNED NULL,
        genesis_hash VARCHAR(64) NULL,
        rpc_url VARCHAR(2048) NOT NULL,
        recipient_address VARCHAR(128) NOT NULL,
        token_address VARCHAR(128) NULL,
        decimals TINYINT UNSIGNED NOT NULL,
        pricing_mode VARCHAR(16) NOT NULL,
        market_coin_id VARCHAR(80) NULL,
        manual_price_usd DECIMAL(20,10) NULL,
        min_confirmations INT UNSIGNED NOT NULL DEFAULT 1,
        expires_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1440,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_crypto_method_network_asset (network_key, asset, asset_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS configured_crypto_checkout_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        checkout_id CHAR(36) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        method_id VARCHAR(64) NOT NULL,
        method_snapshot JSON NOT NULL,
        coins INT NOT NULL,
        amount_base_units DECIMAL(65,0) NOT NULL,
        amount_display VARCHAR(80) NOT NULL,
        amount_usd DECIMAL(10,2) NOT NULL,
        transaction_reference VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
        block_number BIGINT UNSIGNED NULL,
        transaction_id INT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        KEY idx_configured_checkout_user (user_id, created_at),
        KEY idx_configured_checkout_method_status (method_id, status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureConfiguredCryptoReferenceSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS crypto_payment_references (
        network_fingerprint VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        transaction_reference VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        checkout_id CHAR(36) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (network_fingerprint, transaction_reference),
        UNIQUE KEY idx_crypto_payment_reference_checkout (checkout_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function configuredCryptoNetworkFingerprint(array $method): string
{
    return str_starts_with((string)$method['asset_type'], 'evm_')
        ? 'evm:' . (string)$method['chain_id']
        : 'solana:' . (string)$method['genesis_hash'];
}

function configuredCryptoLegacyReferenceExists(
    PDO $db,
    array $method,
    string $reference
): bool {
    if (str_starts_with((string)$method['asset_type'], 'evm_')) {
        if (!adminTableExists($db, 'crypto_checkout_orders')) {
            return false;
        }
        $query = $db->prepare(
            'SELECT 1 FROM crypto_checkout_orders
             WHERE chain_id = :chain_id AND transaction_hash = :transaction_hash LIMIT 1'
        );
        $query->execute([
            ':chain_id' => (int)$method['chain_id'],
            ':transaction_hash' => strtolower($reference),
        ]);
        return $query->fetchColumn() !== false;
    }

    if (!adminTableExists($db, 'solana_checkout_orders')) {
        return false;
    }
    foreach (['mainnet-beta', 'devnet'] as $networkKey) {
        $settings = solanaPaymentSettings($db, $networkKey);
        if (
            !is_string($settings['genesis_hash'] ?? null)
            || !hash_equals((string)$method['genesis_hash'], $settings['genesis_hash'])
        ) {
            continue;
        }
        $query = $db->prepare(
            'SELECT 1 FROM solana_checkout_orders
             WHERE network = :network AND transaction_signature = :signature LIMIT 1'
        );
        $query->execute([
            ':network' => $networkKey,
            ':signature' => $reference,
        ]);
        if ($query->fetchColumn() !== false) {
            return true;
        }
    }
    return false;
}

function configuredCryptoReservePaymentReference(
    PDO $db,
    string $networkFingerprint,
    string $reference,
    string $checkoutId
): void {
    $query = $db->prepare(
        'INSERT INTO crypto_payment_references
            (network_fingerprint, transaction_reference, checkout_id)
         VALUES (:network_fingerprint, :transaction_reference, :checkout_id)'
    );
    $query->execute([
        ':network_fingerprint' => $networkFingerprint,
        ':transaction_reference' => $reference,
        ':checkout_id' => $checkoutId,
    ]);
}

function configuredCryptoPublicMethod(array $method): array
{
    return [
        'id' => (string)$method['id'],
        'label' => (string)$method['label'],
        'network' => (string)$method['network_name'],
        'network_key' => (string)$method['network_key'],
        'asset' => (string)$method['asset'],
        'asset_type' => (string)$method['asset_type'],
        'decimals' => (int)$method['decimals'],
        'chain_id' => $method['chain_id'] === null ? null : (int)$method['chain_id'],
        'is_testnet' => (bool)$method['is_testnet'],
    ];
}

function configuredCryptoPublicMethods(PDO $db): array
{
    ensureConfiguredCryptoSchema($db);
    $query = $db->query(
        'SELECT id, label, asset, asset_type, network_key, network_name,
                decimals, is_testnet, chain_id
         FROM crypto_payment_methods WHERE enabled = 1
         ORDER BY network_name, asset, label'
    );
    return array_map('configuredCryptoPublicMethod', $query->fetchAll(PDO::FETCH_ASSOC));
}

function configuredCryptoMethodById(PDO $db, string $methodId, bool $enabledOnly = true): ?array
{
    ensureConfiguredCryptoSchema($db);
    $query = $db->prepare(
        'SELECT * FROM crypto_payment_methods WHERE id = :id'
        . ($enabledOnly ? ' AND enabled = 1' : '')
        . ' LIMIT 1'
    );
    $query->execute([':id' => $methodId]);
    $method = $query->fetch(PDO::FETCH_ASSOC);
    return $method ?: null;
}

function configuredCryptoFormatUnits(string $units, int $decimals): string
{
    $units = ltrim($units, '0') ?: '0';
    if ($decimals === 0) {
        return $units;
    }
    $padded = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
    $whole = substr($padded, 0, -$decimals);
    $fraction = rtrim(substr($padded, -$decimals), '0');
    return $whole . '.' . ($fraction === '' ? '0' : $fraction);
}

function configuredCryptoDivideCeil(string $value, int $divisor): string
{
    if (!ctype_digit($value) || $divisor <= 0) {
        throw new InvalidArgumentException('Invalid payment quote.');
    }
    $quotient = '';
    $remainder = 0;
    foreach (str_split($value) as $digit) {
        $remainder = ($remainder * 10) + (int)$digit;
        $quotient .= (string)intdiv($remainder, $divisor);
        $remainder %= $divisor;
    }
    $quotient = ltrim($quotient, '0') ?: '0';
    return $remainder === 0
        ? $quotient
        : configuredCryptoAddUnsigned($quotient, 1);
}

function configuredCryptoAddUnsigned(string $value, int $increment): string
{
    if (!ctype_digit($value) || $increment < 0) {
        throw new InvalidArgumentException('Invalid payment amount.');
    }
    $index = strlen($value) - 1;
    $carry = $increment;
    while ($index >= 0 && $carry > 0) {
        $sum = (int)$value[$index] + ($carry % 10);
        $value[$index] = (string)($sum % 10);
        $carry = intdiv($carry, 10) + intdiv($sum, 10);
        $index--;
    }
    while ($carry > 0) {
        $value = (string)($carry % 10) . $value;
        $carry = intdiv($carry, 10);
    }
    return ltrim($value, '0') ?: '0';
}

function configuredCryptoAddDecimalStrings(string $left, string $right): string
{
    if (!ctype_digit($left) || !ctype_digit($right)) {
        throw new InvalidArgumentException('Invalid payment amount.');
    }
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    $leftIndex = strlen($left) - 1;
    $rightIndex = strlen($right) - 1;
    $carry = 0;
    $result = '';
    while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
        $sum = $carry
            + ($leftIndex >= 0 ? (int)$left[$leftIndex--] : 0)
            + ($rightIndex >= 0 ? (int)$right[$rightIndex--] : 0);
        $result = (string)($sum % 10) . $result;
        $carry = intdiv($sum, 10);
    }
    return ltrim($result, '0') ?: '0';
}

function configuredCryptoDivideSmall(string $value, int $divisor): array
{
    if (!ctype_digit($value) || $divisor <= 0) {
        throw new InvalidArgumentException('Invalid payment amount.');
    }
    $quotient = '';
    $remainder = 0;
    foreach (str_split($value) as $digit) {
        $remainder = ($remainder * 10) + (int)$digit;
        $quotient .= (string)intdiv($remainder, $divisor);
        $remainder %= $divisor;
    }
    return [ltrim($quotient, '0') ?: '0', $remainder];
}

function configuredCryptoUnsignedToHex(string $value): string
{
    if (!ctype_digit($value)) {
        throw new InvalidArgumentException('Invalid payment amount.');
    }
    $value = ltrim($value, '0') ?: '0';
    if ($value === '0') {
        return '0';
    }
    $hex = '';
    while ($value !== '0') {
        [$value, $remainder] = configuredCryptoDivideSmall($value, 16);
        $hex = dechex($remainder) . $hex;
    }
    return $hex;
}

function configuredCryptoNormalizeHex(string $value): string
{
    if (!preg_match('/^0x[0-9a-f]+$/iD', $value)) {
        throw new DomainException('The EVM transaction contains an invalid amount.');
    }
    return ltrim(strtolower(substr($value, 2)), '0') ?: '0';
}

function configuredCryptoBaseUnits(int $packageCents, int $priceMicroUsd, int $decimals): string
{
    if (
        $packageCents <= 0
        || $priceMicroUsd <= 0
        || $priceMicroUsd > 10000000000
        || $decimals < 0
        || $decimals > 18
    ) {
        throw new DomainException('The configured crypto quote or token decimals are invalid.');
    }
    $packageMicroUsd = (string)($packageCents * 10000);
    $scaledAmount = $packageMicroUsd . str_repeat('0', $decimals);
    return configuredCryptoDivideCeil($scaledAmount, $priceMicroUsd);
}

function configuredCryptoPriceMicroUsd(array $method): int
{
    if ($method['pricing_mode'] === 'manual') {
        $price = (string)$method['manual_price_usd'];
        if (!preg_match('/^(0|[1-9][0-9]{0,4})(?:\.([0-9]{1,10}))?$/D', $price, $matches)) {
            throw new DomainException('The configured manual USD price is invalid.');
        }
        $whole = (int)$matches[1];
        $fraction = str_pad($matches[2] ?? '', 7, '0');
        $priceMicroUsd = ($whole * 1000000)
            + (int)substr($fraction, 0, 6)
            + ((int)$fraction[6] >= 5 ? 1 : 0);
        if ($priceMicroUsd <= 0 || $priceMicroUsd > 10000000000) {
            throw new DomainException('The configured manual USD price is invalid.');
        }
        return $priceMicroUsd;
    }

    $coinId = (string)$method['market_coin_id'];
    if (!preg_match('/^[a-z0-9-]{1,80}$/D', $coinId)) {
        throw new DomainException('The market price identifier is invalid.');
    }
    $url = 'https://api.coingecko.com/api/v3/simple/price?ids='
        . rawurlencode($coinId) . '&vs_currencies=usd&include_last_updated_at=true';
    $headers = ['Accept: application/json', 'User-Agent: VamperSocialPayments/1.0'];
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
        error_log('Configured crypto quote failed (HTTP ' . $status . '): ' . $curlError);
        throw new RuntimeException('The selected crypto market quote is temporarily unavailable.');
    }
    $decoded = json_decode($response, true);
    $price = $decoded[$coinId]['usd'] ?? null;
    $updatedAt = $decoded[$coinId]['last_updated_at'] ?? null;
    if (
        !is_numeric($price)
        || !is_int($updatedAt)
        || $updatedAt > time() + 30
        || time() - $updatedAt > 180
        || (float)$price <= 0
        || (float)$price > 10000
    ) {
        throw new RuntimeException('The selected crypto market quote is invalid or stale.');
    }
    $priceMicroUsd = (int)round((float)$price * 1000000);
    if ($priceMicroUsd <= 0) {
        throw new RuntimeException('The selected crypto market quote is below supported precision.');
    }
    return $priceMicroUsd;
}

function configuredCryptoRpc(array $method, string $rpcMethod, array $params = []): mixed
{
    $rpcUrl = (string)$method['rpc_url'];
    $parts = parse_url($rpcUrl);
    if (
        filter_var($rpcUrl, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
    ) {
        throw new RuntimeException('The configured RPC URL is invalid.');
    }
    $host = strtolower(trim((string)$parts['host'], '[]'));
    $port = (int)($parts['port'] ?? 443);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('The configured RPC URL is invalid.');
    }
    $addresses = filter_var($host, FILTER_VALIDATE_IP)
        ? [$host]
        : array_values(array_unique(array_filter(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
        ), 'is_string')));
    if ($addresses === []) {
        throw new RuntimeException('The configured RPC host could not be resolved.');
    }
    foreach ($addresses as $address) {
        if (
            filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            throw new RuntimeException('The configured RPC host must resolve only to public IP addresses.');
        }
    }
    $resolve = [];
    $resolveHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
    foreach ($addresses as $address) {
        $resolve[] = $resolveHost . ':' . $port . ':' . (
            str_contains($address, ':') ? '[' . $address . ']' : $address
        );
    }
    $curl = curl_init($rpcUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $rpcMethod,
            'params' => $params,
        ], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => $resolve,
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if (
        $response === false
        || $status < 200
        || $status >= 300
        || !is_array($decoded)
        || array_key_exists('error', $decoded)
    ) {
        error_log('Configured crypto RPC failed: ' . $rpcMethod . ', HTTP ' . $status);
        throw new RuntimeException('The configured blockchain RPC is temporarily unavailable.');
    }
    return $decoded['result'] ?? null;
}

function configuredCryptoCheckNetwork(array $method): void
{
    if (str_starts_with((string)$method['asset_type'], 'evm_')) {
        $chainHex = configuredCryptoRpc($method, 'eth_chainId');
        if (
            !is_string($chainHex)
            || !preg_match('/^0x[0-9a-f]+$/iD', $chainHex)
            || hexdec($chainHex) !== (int)$method['chain_id']
        ) {
            throw new RuntimeException('The RPC endpoint is not on the configured EVM chain.');
        }
        return;
    }
    $genesisHash = configuredCryptoRpc($method, 'getGenesisHash');
    if (
        !is_string($genesisHash)
        || !hash_equals((string)$method['genesis_hash'], $genesisHash)
    ) {
        throw new RuntimeException('The RPC endpoint is not on the configured Solana cluster.');
    }
}

function configuredCryptoValidateMethod(array $method): void
{
    $type = (string)$method['asset_type'];
    if (
        !preg_match('/^cfg_[a-z0-9][a-z0-9_-]{1,59}$/D', (string)$method['id'])
        || !preg_match('/^[A-Z0-9]{2,20}$/D', (string)$method['asset'])
        || trim((string)$method['label']) === ''
        || trim((string)$method['network_name']) === ''
        || !preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', (string)$method['network_key'])
        || !in_array($type, CONFIGURED_CRYPTO_TYPES, true)
    ) {
        throw new DomainException('The configured crypto asset type is unsupported.');
    }
    $decimals = (int)$method['decimals'];
    if ($decimals < 0 || $decimals > 18) {
        throw new DomainException('Asset decimals must be between 0 and 18.');
    }
    if (
        ($type === 'evm_native' && $decimals !== 18)
        || ($type === 'solana_native' && $decimals !== 9)
        || (str_starts_with($type, 'solana_') && (int)$method['min_confirmations'] !== 1)
    ) {
        throw new DomainException('Native asset decimals or Solana finality settings are invalid.');
    }
    if (!in_array($method['pricing_mode'], ['market', 'manual'], true)) {
        throw new DomainException('Select a supported crypto pricing mode.');
    }
    if (
        ($method['pricing_mode'] === 'market'
            && !preg_match('/^[a-z0-9-]{1,80}$/D', (string)$method['market_coin_id']))
        || ($method['pricing_mode'] === 'manual'
            && (!preg_match('/^(0|[1-9][0-9]{0,4})(?:\.[0-9]{1,10})?$/D', (string)$method['manual_price_usd'])
                || (float)$method['manual_price_usd'] < 0.000001
                || (float)$method['manual_price_usd'] > 10000))
    ) {
        throw new DomainException('Set a valid market quote ID or manual USD price.');
    }
    if ((int)$method['min_confirmations'] < 1 || (int)$method['min_confirmations'] > 100000) {
        throw new DomainException('Confirmation count must be between 1 and 100000.');
    }
    if ((int)$method['expires_minutes'] < 5 || (int)$method['expires_minutes'] > 10080) {
        throw new DomainException('Checkout expiry must be between 5 minutes and 7 days.');
    }
    if (str_starts_with($type, 'evm_')) {
        if (!in_array($method['is_testnet'], [0, 1, '0', '1', false, true], true)) {
            throw new DomainException('Select whether the EVM network is a testnet or mainnet.');
        }
        if (
            !empty($method['genesis_hash'])
            ||
            !filter_var($method['chain_id'], FILTER_VALIDATE_INT)
            || (int)$method['chain_id'] <= 0
            || !preg_match('/^0x[a-fA-F0-9]{40}$/D', (string)$method['recipient_address'])
            || strtolower((string)$method['recipient_address']) === '0x' . str_repeat('0', 40)
        ) {
            throw new DomainException('Set a valid EVM chain ID and receiving address.');
        }
        if (
            $type === 'evm_token'
            && !preg_match('/^0x[a-fA-F0-9]{40}$/D', (string)$method['token_address'])
        ) {
            throw new DomainException('Set a valid EVM token contract address.');
        }
        if (
            $type === 'evm_token'
            && strtolower((string)$method['token_address']) === '0x' . str_repeat('0', 40)
        ) {
            throw new DomainException('The EVM token contract address cannot be zero.');
        }
        if ($type === 'evm_native' && !empty($method['token_address'])) {
            throw new DomainException('Native EVM assets cannot have a token contract address.');
        }
    } else {
        if (!in_array($method['is_testnet'], [0, 1, '0', '1', false, true], true)) {
            throw new DomainException('Select whether the Solana cluster is a testnet or mainnet.');
        }
        if (
            $method['chain_id'] !== null
            || !configuredCryptoIsSolanaPublicKey((string)$method['recipient_address'])
            || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', (string)$method['genesis_hash'])
        ) {
            throw new DomainException('Set a valid Solana address and cluster genesis hash.');
        }
        if (
            $type === 'solana_spl'
            && !configuredCryptoIsSolanaPublicKey((string)$method['token_address'])
        ) {
            throw new DomainException('Set a valid Solana SPL token mint.');
        }
        if ($type === 'solana_native' && !empty($method['token_address'])) {
            throw new DomainException('Native Solana assets cannot have a token mint.');
        }
    }
}

function configuredCryptoIsSolanaPublicKey(string $value): bool
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    if ($value === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $value)) {
        return false;
    }
    $leadingZeros = strspn($value, '1');
    $bytes = [];
    foreach (str_split($value) as $character) {
        $carry = strpos($alphabet, $character);
        if ($carry === false) {
            return false;
        }
        for ($index = 0, $length = count($bytes); $index < $length; $index++) {
            $carry += $bytes[$index] * 58;
            $bytes[$index] = $carry & 0xff;
            $carry = intdiv($carry, 256);
        }
        while ($carry > 0) {
            $bytes[] = $carry & 0xff;
            $carry = intdiv($carry, 256);
        }
    }
    return $leadingZeros + count($bytes) === 32
        && $leadingZeros !== strlen($value);
}

function configuredCryptoVerifyNetwork(array $method): void
{
    configuredCryptoCheckNetwork($method);
    if ($method['asset_type'] === 'evm_token') {
        $code = configuredCryptoRpc($method, 'eth_getCode', [$method['token_address'], 'latest']);
        if (!is_string($code) || $code === '0x' || $code === '0x0') {
            throw new RuntimeException('The configured EVM token contract has no deployed code.');
        }
        $decimals = configuredCryptoRpc($method, 'eth_call', [
            ['to' => $method['token_address'], 'data' => '0x313ce567'],
            'latest',
        ]);
        if (
            !is_string($decimals)
            || !preg_match('/^0x[0-9a-f]{1,64}$/iD', $decimals)
            || hexdec($decimals) !== (int)$method['decimals']
        ) {
            throw new RuntimeException('The configured EVM token decimals do not match the contract.');
        }
    } elseif ($method['asset_type'] === 'solana_spl') {
        $supply = configuredCryptoRpc($method, 'getTokenSupply', [
            $method['token_address'],
            ['commitment' => 'finalized'],
        ]);
        $accounts = configuredCryptoRpc($method, 'getTokenAccountsByOwner', [
            $method['recipient_address'],
            ['mint' => $method['token_address']],
            ['encoding' => 'jsonParsed', 'commitment' => 'finalized'],
        ]);
        if (
            !is_array($supply)
            || !is_int($supply['value']['decimals'] ?? null)
            || $supply['value']['decimals'] !== (int)$method['decimals']
            || !is_array($accounts)
            || !is_array($accounts['value'] ?? null)
            || $accounts['value'] === []
        ) {
            throw new RuntimeException('The configured SPL mint decimals or receiving token account are invalid.');
        }
    }
}

function configuredCryptoVerifyTransaction(
    array $method,
    string $reference,
    string $amount,
    int $createdAt,
    int $expiresAt
): array {
    if (str_starts_with((string)$method['asset_type'], 'solana_')) {
        if (!solanaValidateSignature($reference)) {
            throw new DomainException('Enter a valid Solana transaction signature.');
        }
        configuredCryptoCheckNetwork($method);
        $transaction = configuredCryptoRpc($method, 'getTransaction', [
            $reference,
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
            || !hash_equals($reference, (string)$transaction['transaction']['signatures'][0])
        ) {
            throw new DomainException('The Solana transaction did not succeed or its signature does not match.');
        }
        $paidAt = $transaction['blockTime'];
        if ($paidAt < $createdAt || $paidAt >= $expiresAt) {
            throw new DomainException('This payment was made outside its checkout validity period.');
        }
        $message = $transaction['transaction']['message'] ?? [];
        $instructions = is_array($message) ? ($message['instructions'] ?? []) : [];
        foreach (($transaction['meta']['innerInstructions'] ?? []) as $innerGroup) {
            if (is_array($innerGroup) && is_array($innerGroup['instructions'] ?? null)) {
                $instructions = array_merge($instructions, $innerGroup['instructions']);
            }
        }
        $actualAmount = $method['asset_type'] === 'solana_native'
            ? configuredCryptoSolanaNativeTransfers($instructions, (string)$method['recipient_address'])
            : configuredCryptoSolanaSplTransfers(
                $transaction,
                $instructions,
                (string)$method['recipient_address'],
                (string)$method['token_address'],
                (int)$method['decimals']
            );
        if ($actualAmount !== (ltrim($amount, '0') ?: '0')) {
            throw new DomainException(
                'No exact Solana transfer matching this order was found. Check the network, asset, recipient, and amount.'
            );
        }
        return ['status' => 'completed', 'slot' => $transaction['slot'], 'confirmations' => 1];
    }

    if (!preg_match('/^0x[a-fA-F0-9]{64}$/D', $reference)) {
        throw new DomainException('Enter a valid EVM transaction hash.');
    }
    configuredCryptoCheckNetwork($method);
    $receipt = configuredCryptoRpc($method, 'eth_getTransactionReceipt', [$reference]);
    $transaction = configuredCryptoRpc($method, 'eth_getTransactionByHash', [$reference]);
    if ($receipt === null || $transaction === null) {
        return ['status' => 'pending'];
    }
    if (
        !is_array($receipt)
        || !is_array($transaction)
        || strcasecmp((string)($receipt['transactionHash'] ?? ''), $reference) !== 0
        || ($receipt['status'] ?? null) !== '0x1'
        || !is_string($receipt['blockNumber'] ?? null)
        || !preg_match('/^0x[0-9a-f]+$/iD', $receipt['blockNumber'])
    ) {
        throw new DomainException('The EVM transaction did not succeed.');
    }

    if ($method['asset_type'] === 'evm_native') {
        if (
            strcasecmp((string)($transaction['to'] ?? ''), (string)$method['recipient_address']) !== 0
            || configuredCryptoNormalizeHex((string)($transaction['value'] ?? ''))
                !== configuredCryptoUnsignedToHex($amount)
        ) {
            throw new DomainException('No native-coin transfer matching this checkout was found.');
        }
    } else {
        $expectedRecipient = '0x' . str_pad(
            strtolower(substr((string)$method['recipient_address'], 2)),
            64,
            '0',
            STR_PAD_LEFT
        );
        $expectedAmountHex = configuredCryptoUnsignedToHex($amount);
        $matches = 0;
        foreach (($receipt['logs'] ?? []) as $log) {
            $topics = $log['topics'] ?? null;
            if (
                !is_array($topics)
                || count($topics) !== 3
                || strcasecmp((string)($log['address'] ?? ''), (string)$method['token_address']) !== 0
                || strcasecmp((string)($topics[0] ?? ''), POLYGON_USDC_TRANSFER_TOPIC) !== 0
                || strcasecmp((string)($topics[2] ?? ''), $expectedRecipient) !== 0
            ) {
                continue;
            }
            $data = (string)($log['data'] ?? '');
            if (!preg_match('/^0x[0-9a-f]{64}$/iD', $data)) {
                continue;
            }
            $actualAmount = ltrim(strtolower(substr($data, 2)), '0') ?: '0';
            if ($actualAmount === $expectedAmountHex) {
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new DomainException('No unique token transfer matching this checkout was found.');
        }
    }

    $block = configuredCryptoRpc($method, 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
    $timestamp = is_array($block) ? ($block['timestamp'] ?? null) : null;
    if (!is_string($timestamp) || !preg_match('/^0x[0-9a-f]+$/iD', $timestamp)) {
        throw new RuntimeException('The EVM RPC did not return the transaction block timestamp.');
    }
    $paidAt = hexdec($timestamp);
    if ($paidAt < $createdAt || $paidAt >= $expiresAt) {
        throw new DomainException('This payment was made outside its checkout validity period.');
    }
    $latestBlock = configuredCryptoRpc($method, 'eth_blockNumber');
    if (!is_string($latestBlock) || !preg_match('/^0x[0-9a-f]+$/iD', $latestBlock)) {
        throw new RuntimeException('The EVM RPC did not return a valid block number.');
    }
    $confirmations = hexdec($latestBlock) - hexdec($receipt['blockNumber']) + 1;
    if ($confirmations < (int)$method['min_confirmations']) {
        return ['status' => 'confirming', 'confirmations' => max(0, $confirmations)];
    }
    return [
        'status' => 'completed',
        'confirmations' => $confirmations,
        'block_number' => hexdec($receipt['blockNumber']),
    ];
}

function configuredCryptoSolanaNativeTransfers(array $instructions, string $recipient): string
{
    $total = '0';
    foreach ($instructions as $instruction) {
        $parsed = is_array($instruction) ? ($instruction['parsed'] ?? null) : null;
        $info = is_array($parsed) ? ($parsed['info'] ?? null) : null;
        $lamports = is_array($info) ? ($info['lamports'] ?? null) : null;
        if (
            !is_array($instruction)
            || ($instruction['program'] ?? null) !== 'system'
            || ($parsed['type'] ?? null) !== 'transfer'
            || !is_array($info)
            || ($info['destination'] ?? null) !== $recipient
            || (!is_int($lamports) && !is_string($lamports))
            || !ctype_digit((string)$lamports)
        ) {
            continue;
        }
        $total = configuredCryptoAddDecimalStrings($total, (string)$lamports);
    }
    return $total;
}

function configuredCryptoSolanaSplTransfers(
    array $transaction,
    array $instructions,
    string $recipient,
    string $tokenMint,
    int $decimals
): string {
    $accountKeys = $transaction['transaction']['message']['accountKeys'] ?? [];
    $postBalances = $transaction['meta']['postTokenBalances'] ?? [];
    $destinationAccounts = [];
    foreach ($postBalances as $balance) {
        if (
            !is_array($balance)
            || ($balance['owner'] ?? null) !== $recipient
            || ($balance['mint'] ?? null) !== $tokenMint
            || ($balance['uiTokenAmount']['decimals'] ?? null) !== $decimals
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

    $total = '0';
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
        $transferDecimals = $info['tokenAmount']['decimals'] ?? $info['decimals'] ?? $decimals;
        if ($transferDecimals !== $decimals) {
            continue;
        }
        $amount = $info['tokenAmount']['amount'] ?? $info['amount'] ?? null;
        if ((!is_int($amount) && !is_string($amount)) || !ctype_digit((string)$amount)) {
            continue;
        }
        $total = configuredCryptoAddDecimalStrings($total, (string)$amount);
    }
    return $total;
}
