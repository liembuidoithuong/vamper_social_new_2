<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/ConfiguredCryptoPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$coins = is_array($data) ? filter_var($data['coins'] ?? null, FILTER_VALIDATE_INT) : false;
$methodId = is_array($data) ? trim((string)($data['method_id'] ?? '')) : '';
$packages = polygonUsdcCoinPackages();

if ($coins === false || !isset($packages[$coins]) || !preg_match('/^cfg_[a-z0-9][a-z0-9_-]{1,59}$/D', $methodId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select a valid coin package and payment method.']);
    exit;
}

try {
    ensureConfiguredCryptoSchema($db);
    $method = configuredCryptoMethodById($db, $methodId);
    if (!$method) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'The selected crypto payment method is not available.']);
        exit;
    }
    configuredCryptoValidateMethod($method);
    configuredCryptoVerifyNetwork($method);

    $packagePrice = $packages[$coins];
    $packageCents = (int)str_replace('.', '', $packagePrice);
    $priceMicroUsd = configuredCryptoPriceMicroUsd($method);
    $baseUnits = configuredCryptoBaseUnits($packageCents, $priceMicroUsd, (int)$method['decimals']);
    $checkoutId = polygonUsdcCheckoutId();
    $db->beginTransaction();
    $methodLock = $db->prepare('SELECT * FROM crypto_payment_methods WHERE id = :id AND enabled = 1 FOR UPDATE');
    $methodLock->execute([':id' => $methodId]);
    $lockedMethod = $methodLock->fetch(PDO::FETCH_ASSOC);
    if (
        !$lockedMethod
        || !hash_equals(
            hash('sha256', json_encode($method, JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($lockedMethod, JSON_THROW_ON_ERROR))
        )
    ) {
        throw new DomainException('The selected crypto payment method is no longer available.');
    }
    $db->exec(
        "UPDATE configured_crypto_checkout_orders SET status = 'expired'
         WHERE status = 'pending' AND expires_at <= NOW()"
    );
    $activeQuery = $db->prepare(
        "SELECT COUNT(*) FROM configured_crypto_checkout_orders
         WHERE user_id = :user_id AND status = 'pending' AND expires_at > NOW()"
    );
    $activeQuery->execute([':user_id' => $userId]);
    if ((int)$activeQuery->fetchColumn() >= 5) {
        throw new DomainException(
            'You have several open crypto checkouts. Complete, verify, or remove one before creating another.'
        );
    }

    $usedQuery = $db->prepare(
        "SELECT amount_base_units FROM configured_crypto_checkout_orders
         WHERE method_id = :method_id
           AND (status = 'pending' OR (status = 'cancelled' AND expires_at > NOW()))"
    );
    $usedQuery->execute([':method_id' => $methodId]);
    $usedAmounts = array_fill_keys(
        array_map('strval', $usedQuery->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
    $amountBaseUnits = null;
    [$suffixLimit] = configuredCryptoDivideSmall(
        '10000' . str_repeat('0', (int)$method['decimals']),
        $priceMicroUsd
    );
    $suffixLimit = strlen($suffixLimit) > 4 ? 9999 : min(9999, (int)$suffixLimit);
    $suffixStart = $suffixLimit > 0 ? random_int(1, $suffixLimit) : 0;
    for ($attempt = 0; $attempt < max(1, $suffixLimit); $attempt++) {
        $suffix = $suffixLimit === 0
            ? 0
            : (($suffixStart + $attempt - 1) % $suffixLimit) + 1;
        $candidate = configuredCryptoAddDecimalStrings($baseUnits, (string)$suffix);
        if (!isset($usedAmounts[$candidate])) {
            $amountBaseUnits = $candidate;
            break;
        }
    }
    if ($amountBaseUnits === null) {
        throw new RuntimeException('There are too many open checkouts for this method. Please try again later.');
    }

    $amountDisplay = configuredCryptoFormatUnits($amountBaseUnits, (int)$method['decimals']);
    $expiresMinutes = (int)$method['expires_minutes'];
    $snapshot = $method;
    $snapshot['price_micro_usd'] = $priceMicroUsd;
    $snapshot['package_price_usd'] = $packagePrice;
    $insert = $db->prepare(
        "INSERT INTO configured_crypto_checkout_orders
            (checkout_id, user_id, method_id, method_snapshot, coins, amount_base_units,
             amount_display, amount_usd, status, expires_at)
         VALUES
            (:checkout_id, :user_id, :method_id, :method_snapshot, :coins, :amount_base_units,
             :amount_display, :amount_usd, 'pending', DATE_ADD(NOW(), INTERVAL $expiresMinutes MINUTE))"
    );
    $insert->execute([
        ':checkout_id' => $checkoutId,
        ':user_id' => $userId,
        ':method_id' => $methodId,
        ':method_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ':coins' => $coins,
        ':amount_base_units' => $amountBaseUnits,
        ':amount_display' => $amountDisplay,
        ':amount_usd' => $packagePrice,
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'checkout_id' => $checkoutId,
            'payment_method' => $methodId,
            'method_id' => $methodId,
            'coins' => $coins,
            'amount' => $amountDisplay,
            'currency' => $method['asset'],
            'asset' => $method['asset'],
            'asset_type' => $method['asset_type'],
            'decimals' => (int)$method['decimals'],
            'network' => $method['network_name'],
            'network_key' => $method['network_key'],
            'is_testnet' => (bool)$method['is_testnet'],
            'chain_id' => $method['chain_id'] === null ? null : (int)$method['chain_id'],
            'token_address' => $method['token_address'],
            'token_mint' => $method['token_address'],
            'recipient_address' => $method['recipient_address'],
            'min_confirmations' => (int)$method['min_confirmations'],
            'expires_in_minutes' => $expiresMinutes,
        ],
    ]);
} catch (DomainException $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Unable to create configured crypto checkout: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to start a crypto checkout. Please try again later.',
    ]);
}
