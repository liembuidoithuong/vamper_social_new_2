<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/ConfiguredCryptoPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$checkoutId = is_array($data) ? trim((string)($data['checkout_id'] ?? '')) : '';
$reference = is_array($data) ? trim((string)($data['transaction_reference'] ?? '')) : '';

if (!preg_match('/^[a-f0-9-]{36}$/iD', $checkoutId) || $reference === '' || strlen($reference) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid checkout and transaction reference.']);
    exit;
}

try {
    ensureConfiguredCryptoSchema($db);
    ensurePolygonUsdcPaymentSchema($db);
    ensureSolanaPaymentSchema($db);
    ensureConfiguredCryptoReferenceSchema($db);
    $query = $db->prepare(
        'SELECT *, UNIX_TIMESTAMP(created_at) AS created_epoch,
                UNIX_TIMESTAMP(expires_at) AS expires_epoch
         FROM configured_crypto_checkout_orders
         WHERE checkout_id = :checkout_id AND user_id = :user_id LIMIT 1'
    );
    $query->execute([':checkout_id' => $checkoutId, ':user_id' => $userId]);
    $checkout = $query->fetch(PDO::FETCH_ASSOC);
    if (!$checkout) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Crypto checkout was not found.']);
        exit;
    }
    if ($checkout['status'] === 'completed') {
        $balanceQuery = $db->prepare('SELECT coins FROM users WHERE id = :id AND is_active = 1');
        $balanceQuery->execute([':id' => $userId]);
        $balance = $balanceQuery->fetchColumn();
        if ($balance === false) {
            throw new DomainException('User account is unavailable.');
        }
        echo json_encode([
            'success' => true,
            'message' => 'This crypto payment was already processed.',
            'data' => [
                'status' => 'completed',
                'coins' => (int)$checkout['coins'],
                'balance' => (int)$balance,
                'already_processed' => true,
            ],
        ]);
        exit;
    }
    if (!in_array($checkout['status'], ['pending', 'expired'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This crypto checkout is not available.']);
        exit;
    }
    $method = json_decode((string)$checkout['method_snapshot'], true);
    if (!is_array($method)) {
        throw new RuntimeException('The stored crypto checkout configuration is invalid.');
    }
    configuredCryptoValidateMethod($method);
    $verification = configuredCryptoVerifyTransaction(
        $method,
        $reference,
        (string)$checkout['amount_base_units'],
        (int)$checkout['created_epoch'],
        (int)$checkout['expires_epoch']
    );
    if (in_array($verification['status'] ?? null, ['pending', 'confirming'], true)) {
        if (
            $verification['status'] === 'pending'
            && (int)$checkout['expires_epoch'] <= time()
        ) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'This checkout expired before the payment was confirmed. Contact support with the transaction reference.',
            ]);
            exit;
        }
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'message' => $verification['status'] === 'pending'
                ? 'Transaction not visible on the selected network yet. Wait and verify again.'
                : 'Transaction is confirmed; waiting for additional confirmations.',
            'data' => [
                'status' => $verification['status'],
                'confirmations' => $verification['confirmations'] ?? 0,
                'required_confirmations' => (int)$method['min_confirmations'],
            ],
        ]);
        exit;
    }

    $storedReference = str_starts_with((string)$method['asset_type'], 'evm_')
        ? strtolower($reference)
        : $reference;
    if (configuredCryptoLegacyReferenceExists($db, $method, $storedReference)) {
        throw new DomainException('This transaction has already been used for a coin top-up.');
    }

    $db->beginTransaction();
    $lockedQuery = $db->prepare(
        'SELECT * FROM configured_crypto_checkout_orders WHERE checkout_id = :checkout_id FOR UPDATE'
    );
    $lockedQuery->execute([':checkout_id' => $checkoutId]);
    $lockedCheckout = $lockedQuery->fetch(PDO::FETCH_ASSOC);
    if (!$lockedCheckout) {
        throw new DomainException('This crypto checkout was not found.');
    }
    if ($lockedCheckout['status'] === 'completed') {
        $balanceQuery = $db->prepare('SELECT coins FROM users WHERE id = :id AND is_active = 1');
        $balanceQuery->execute([':id' => $userId]);
        $balance = $balanceQuery->fetchColumn();
        if ($balance === false) {
            throw new DomainException('User account is unavailable.');
        }
        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'This crypto payment was already processed.',
            'data' => [
                'status' => 'completed',
                'coins' => (int)$lockedCheckout['coins'],
                'balance' => (int)$balance,
                'already_processed' => true,
            ],
        ]);
        exit;
    }
    if (!in_array($lockedCheckout['status'], ['pending', 'expired'], true)) {
        throw new DomainException('This crypto checkout is not available.');
    }
    $usedReferenceQuery = $db->prepare(
        'SELECT checkout_id FROM configured_crypto_checkout_orders
         WHERE transaction_reference = :reference LIMIT 1'
    );
    $usedReferenceQuery->execute([':reference' => $storedReference]);
    if ($usedReferenceQuery->fetchColumn() !== false) {
        throw new DomainException('This transaction has already been used for a coin top-up.');
    }
    configuredCryptoReservePaymentReference(
        $db,
        configuredCryptoNetworkFingerprint($method),
        $storedReference,
        $checkoutId
    );
    $balanceQuery = $db->prepare('SELECT coins FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
    $balanceQuery->execute([':id' => $userId]);
    $balanceBefore = $balanceQuery->fetchColumn();
    if ($balanceBefore === false) {
        throw new DomainException('User account is unavailable.');
    }

    $coins = (int)$lockedCheckout['coins'];
    $balanceAfter = (int)$balanceBefore + $coins;
    $isTestnet = (bool)$method['is_testnet'];
    $asset = strtoupper((string)$method['asset']);
    $label = trim((string)$method['label']);
    $gateway = substr($label !== '' ? $label : ($asset . ' ' . $method['network_name']), 0, 50);
    $transactionStatus = $isTestnet ? 'sandbox_mock' : 'completed';
    $db->prepare('UPDATE users SET coins = :coins WHERE id = :id')
        ->execute([':coins' => $balanceAfter, ':id' => $userId]);
    $db->prepare(
        "INSERT INTO transactions (user_id, amount_usd, coins, gateway, status, created_at)
         VALUES (:user_id, :amount, :coins, :gateway, :status, NOW())"
    )->execute([
        ':user_id' => $userId,
        ':amount' => $lockedCheckout['amount_usd'],
        ':coins' => $coins,
        ':gateway' => $gateway,
        ':status' => $transactionStatus,
    ]);
    $transactionId = (int)$db->lastInsertId();
    $sourceType = $isTestnet ? 'configured_crypto_test_topup' : 'configured_crypto_topup';
    recordVpcLedger(
        $db,
        $userId,
        $coins,
        (int)$balanceBefore,
        $balanceAfter,
        $sourceType,
        substr($label . ($isTestnet ? ' test payment' : ' payment'), 0, 255),
        $storedReference
    );
    $db->prepare(
        "UPDATE configured_crypto_checkout_orders
         SET status = 'completed', transaction_reference = :reference,
             block_number = :block_number, transaction_id = :transaction_id, completed_at = NOW()
         WHERE checkout_id = :checkout_id"
    )->execute([
        ':reference' => $storedReference,
        ':block_number' => $verification['block_number'] ?? $verification['slot'] ?? null,
        ':transaction_id' => $transactionId,
        ':checkout_id' => $checkoutId,
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $isTestnet
            ? "$asset test payment verified. Added $coins test VPC."
            : "$asset payment verified. Added $coins VPC.",
        'data' => [
            'status' => 'completed',
            'coins' => $coins,
            'balance' => $balanceAfter,
            'already_processed' => false,
        ],
    ]);
} catch (DomainException $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (PDOException $error) {
    if ($db->inTransaction()) $db->rollBack();
    if (($error->errorInfo[0] ?? null) === '23000') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'This transaction has already been used for a coin top-up.',
        ]);
        exit;
    }
    error_log('Configured crypto payment database write failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Payment verification could not be saved. Please retry.']);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Configured crypto payment verification failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to verify this crypto payment right now. Please retry.',
    ]);
}
