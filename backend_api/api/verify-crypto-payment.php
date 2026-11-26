<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/PolygonUsdcPayments.php';
require_once __DIR__ . '/libs/ConfiguredCryptoPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$checkoutId = is_array($data) ? trim((string)($data['checkout_id'] ?? '')) : '';
$transactionHash = is_array($data) ? trim((string)($data['transaction_hash'] ?? '')) : '';

if (
    !preg_match('/^[a-f0-9-]{36}$/iD', $checkoutId)
    || !preg_match('/^0x[a-f0-9]{64}$/iD', $transactionHash)
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid order and Polygon transaction hash.']);
    exit;
}

try {
    ensurePolygonUsdcPaymentSchema($db);
    ensureConfiguredCryptoReferenceSchema($db);
    $query = $db->prepare(
        'SELECT *, UNIX_TIMESTAMP(created_at) AS created_epoch,
                UNIX_TIMESTAMP(expires_at) AS expires_epoch
         FROM crypto_checkout_orders
         WHERE checkout_id = :checkout_id AND user_id = :user_id LIMIT 1'
    );
    $query->execute([':checkout_id' => $checkoutId, ':user_id' => $userId]);
    $checkout = $query->fetch(PDO::FETCH_ASSOC);
    if (!$checkout) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'USDC checkout was not found.']);
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
            'message' => 'This USDC payment was already processed.',
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
        echo json_encode(['success' => false, 'message' => 'This USDC checkout is not available.']);
        exit;
    }
    $networkKey = match ((int)$checkout['chain_id']) {
        POLYGON_USDC_CHAIN_ID => 'mainnet',
        POLYGON_AMOY_CHAIN_ID => 'amoy',
        default => '',
    };
    $settings = polygonUsdcSettings($db, $networkKey);
    if (!$settings['configured']) {
        throw new RuntimeException('Polygon USDC verification is not configured.');
    }

    $packages = polygonUsdcCoinPackages();
    $coins = (int)$checkout['coins'];
    $baseAmount = isset($packages[$coins])
        ? (int)polygonUsdcAmountToBaseUnits($packages[$coins])
        : 0;
    $orderAmount = (int)polygonUsdcAmountToBaseUnits((string)$checkout['amount_usdc']);
    if (
        $networkKey === ''
        || (int)$checkout['chain_id'] !== $settings['chain_id']
        || strcasecmp($checkout['token_contract'], (string)$settings['token_contract']) !== 0
        || !isset($packages[$coins])
        || $orderAmount <= $baseAmount
        || $orderAmount > $baseAmount + 9999
    ) {
        throw new DomainException('The stored USDC package does not match an available package.');
    }

    $verification = verifyPolygonUsdcPayment(
        $settings,
        $transactionHash,
        $checkout['recipient_address'],
        $checkout['amount_usdc'],
        $checkout['token_contract'],
        (int)$checkout['created_epoch'],
        (int)$checkout['expires_epoch']
    );

    if ($verification['status'] === 'pending' || $verification['status'] === 'confirming') {
        if (
            $verification['status'] === 'pending'
            && (int)$checkout['expires_epoch'] <= time()
        ) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'This checkout expired before the payment was confirmed. Contact support with the transaction hash.',
            ]);
            exit;
        }
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'message' => $verification['status'] === 'pending'
                ? 'Transaction not visible on the selected Polygon network yet. Wait a little and verify again.'
                : 'Transaction is confirmed on-chain; waiting for additional confirmations.',
            'data' => [
                'status' => $verification['status'],
                'confirmations' => $verification['confirmations'] ?? 0,
                'required_confirmations' => $settings['min_confirmations'],
            ],
        ]);
        exit;
    }

    $db->beginTransaction();
    $lockedQuery = $db->prepare(
        'SELECT * FROM crypto_checkout_orders WHERE checkout_id = :checkout_id FOR UPDATE'
    );
    $lockedQuery->execute([':checkout_id' => $checkoutId]);
    $lockedCheckout = $lockedQuery->fetch(PDO::FETCH_ASSOC);
    if (!$lockedCheckout) {
        throw new DomainException('This USDC checkout has already been processed.');
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
            'message' => 'This USDC payment was already processed.',
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
        throw new DomainException('This USDC checkout is not available.');
    }

    $usedHashQuery = $db->prepare(
        'SELECT checkout_id FROM crypto_checkout_orders WHERE transaction_hash = :transaction_hash LIMIT 1'
    );
    $usedHashQuery->execute([':transaction_hash' => strtolower($transactionHash)]);
    if ($usedHashQuery->fetchColumn() !== false) {
        throw new DomainException('This transaction has already been used for a coin top-up.');
    }
    configuredCryptoReservePaymentReference(
        $db,
        'evm:' . (int)$lockedCheckout['chain_id'],
        strtolower($transactionHash),
        $checkoutId
    );

    $balanceQuery = $db->prepare('SELECT coins FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
    $balanceQuery->execute([':id' => $userId]);
    $balanceBefore = $balanceQuery->fetchColumn();
    if ($balanceBefore === false) {
        throw new DomainException('User account is unavailable.');
    }

    $coins = (int)$lockedCheckout['coins'];
    $isAmoyTestnet = (int)$lockedCheckout['chain_id'] === POLYGON_AMOY_CHAIN_ID;
    $gateway = $isAmoyTestnet ? 'USDC Polygon Amoy (test)' : 'USDC Polygon';
    $transactionStatus = $isAmoyTestnet ? 'sandbox_mock' : 'completed';
    $balanceAfter = (int)$balanceBefore + $coins;
    $db->prepare('UPDATE users SET coins = :coins WHERE id = :id')
        ->execute([':coins' => $balanceAfter, ':id' => $userId]);
    $db->prepare(
        "INSERT INTO transactions (user_id, amount_usd, coins, gateway, status, created_at)
         VALUES (:user_id, :amount, :coins, :gateway, :status, NOW())"
    )->execute([
        ':user_id' => $userId,
        ':amount' => $lockedCheckout['amount_usdc'],
        ':coins' => $coins,
        ':gateway' => $gateway,
        ':status' => $transactionStatus,
    ]);
    $transactionId = (int)$db->lastInsertId();

    recordVpcLedger(
        $db,
        $userId,
        $coins,
        (int)$balanceBefore,
        $balanceAfter,
        $isAmoyTestnet ? 'polygon_usdc_test_topup' : 'polygon_usdc_topup',
        $isAmoyTestnet ? 'USDC Polygon Amoy test payment' : 'USDC Polygon payment',
        strtolower($transactionHash)
    );

    $db->prepare(
        "UPDATE crypto_checkout_orders
         SET status = 'completed', transaction_hash = :transaction_hash,
             transfer_log_index = :log_index, confirmed_block = :block_number,
             transaction_id = :transaction_id, completed_at = NOW()
         WHERE checkout_id = :checkout_id"
    )->execute([
        ':transaction_hash' => strtolower($transactionHash),
        ':log_index' => $verification['log_index'],
        ':block_number' => $verification['block_number'],
        ':transaction_id' => $transactionId,
        ':checkout_id' => $checkoutId,
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $isAmoyTestnet
            ? "Amoy test payment verified. Added $coins test VPC."
            : "Payment verified. Added $coins VPC.",
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
    error_log('Polygon USDC database write failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Payment verification could not be saved. Please retry.',
    ]);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Polygon USDC payment verification failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to verify this Polygon payment right now. Please retry.',
    ]);
}
