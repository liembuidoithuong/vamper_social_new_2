<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/SolanaPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$checkoutId = is_array($data) ? trim((string)($data['checkout_id'] ?? '')) : '';
$signature = is_array($data) ? trim((string)($data['transaction_signature'] ?? '')) : '';

if (!preg_match('/^[a-f0-9-]{36}$/iD', $checkoutId) || !solanaValidateSignature($signature)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid Solana order and transaction signature.']);
    exit;
}

try {
    ensureSolanaPaymentSchema($db);
    $query = $db->prepare(
        'SELECT *, UNIX_TIMESTAMP(created_at) AS created_epoch,
                UNIX_TIMESTAMP(expires_at) AS expires_epoch
         FROM solana_checkout_orders
         WHERE checkout_id = :checkout_id AND user_id = :user_id LIMIT 1'
    );
    $query->execute([':checkout_id' => $checkoutId, ':user_id' => $userId]);
    $checkout = $query->fetch(PDO::FETCH_ASSOC);
    if (!$checkout) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Solana checkout was not found.']);
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
            'message' => 'This Solana payment was already processed.',
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
        echo json_encode(['success' => false, 'message' => 'This Solana checkout is not available.']);
        exit;
    }
    $settings = solanaPaymentSettings();
    if (!$settings['enabled'] || $checkout['network'] !== $settings['network_key']) {
        throw new RuntimeException('Solana payment verification is not configured for this order network.');
    }

    $asset = (string)$checkout['asset'];
    $packages = polygonUsdcCoinPackages();
    $coins = (int)$checkout['coins'];
    if (!isset($packages[$coins]) || !in_array($asset, ['SOL', 'USDC', 'USDT'], true)) {
        throw new DomainException('The stored Solana package is invalid.');
    }
    $expectedMint = match ($asset) {
        'USDC' => $settings['usdc_mint'],
        'USDT' => $settings['usdt_mint'],
        default => null,
    };
    if (
        !hash_equals($settings['recipient_address'], (string)$checkout['recipient_address'])
        || ($asset === 'SOL' && $checkout['token_mint'] !== null)
        || ($asset !== 'SOL' && ($expectedMint === null || $checkout['token_mint'] !== $expectedMint))
    ) {
        throw new DomainException('The stored Solana recipient or token does not match the configured payment method.');
    }

    $packageCents = (int)str_replace('.', '', $packages[$coins]);
    $storedAmount = (int)$checkout['amount_base_units'];
    if ($asset === 'SOL') {
        $priceMicroUsd = (int)$checkout['sol_price_micro_usd'];
        if ($priceMicroUsd <= 0 || $priceMicroUsd > 10000000000) {
            throw new DomainException('The stored SOL/USD quote is invalid.');
        }
        $quote = solanaCalculateSolQuote($packageCents, $priceMicroUsd);
        $baseUnits = $quote['base_units'];
        $maxSuffix = $quote['max_suffix'];
        $decimals = 9;
    } else {
        $baseUnits = $packageCents * 10000;
        $maxSuffix = 9999;
        $decimals = 6;
    }
    if (
        $storedAmount <= $baseUnits
        || $storedAmount > $baseUnits + $maxSuffix
        || solanaFormatBaseUnits($storedAmount, $decimals) !== (string)$checkout['amount_display']
    ) {
        throw new DomainException('The stored Solana payment amount does not match its package.');
    }

    assertSolanaNetwork($settings);
    if ($expectedMint !== null) {
        assertSolanaStablecoinMint($settings, $expectedMint);
    }
    $verification = solanaVerifyTransaction(
        $settings,
        $signature,
        $asset,
        $expectedMint,
        $settings['recipient_address'],
        $storedAmount,
        (int)$checkout['created_epoch'],
        (int)$checkout['expires_epoch']
    );
    if ($verification['status'] === 'pending') {
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'message' => 'Solana has not finalized this transaction yet. Wait a little and verify again.',
            'data' => ['status' => 'pending'],
        ]);
        exit;
    }

    $db->beginTransaction();
    $lockedQuery = $db->prepare(
        'SELECT * FROM solana_checkout_orders WHERE checkout_id = :checkout_id FOR UPDATE'
    );
    $lockedQuery->execute([':checkout_id' => $checkoutId]);
    $lockedCheckout = $lockedQuery->fetch(PDO::FETCH_ASSOC);
    if (!$lockedCheckout) {
        throw new DomainException('This Solana checkout has already been processed.');
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
            'message' => 'This Solana payment was already processed.',
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
        throw new DomainException('This Solana checkout is not available.');
    }

    $usedSignatureQuery = $db->prepare(
        'SELECT checkout_id FROM solana_checkout_orders
         WHERE transaction_signature = :signature LIMIT 1'
    );
    $usedSignatureQuery->execute([':signature' => $signature]);
    if ($usedSignatureQuery->fetchColumn() !== false) {
        throw new DomainException('This Solana transaction has already been used for a coin top-up.');
    }

    $balanceQuery = $db->prepare('SELECT coins FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
    $balanceQuery->execute([':id' => $userId]);
    $balanceBefore = $balanceQuery->fetchColumn();
    if ($balanceBefore === false) {
        throw new DomainException('User account is unavailable.');
    }

    $isTestnet = $settings['network_key'] === 'devnet';
    $gateway = $isTestnet ? "Solana Devnet $asset (test)" : "Solana $asset";
    $transactionStatus = $isTestnet ? 'sandbox_mock' : 'completed';
    $balanceAfter = (int)$balanceBefore + $coins;
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

    recordVpcLedger(
        $db,
        $userId,
        $coins,
        (int)$balanceBefore,
        $balanceAfter,
        $isTestnet ? 'solana_test_topup' : 'solana_topup',
        $isTestnet ? "Solana Devnet $asset test payment" : "Solana $asset payment",
        $signature
    );
    $db->prepare(
        "UPDATE solana_checkout_orders
         SET status = 'completed', transaction_signature = :signature,
             slot = :slot, transaction_id = :transaction_id, completed_at = NOW()
         WHERE checkout_id = :checkout_id"
    )->execute([
        ':signature' => $signature,
        ':slot' => $verification['slot'],
        ':transaction_id' => $transactionId,
        ':checkout_id' => $checkoutId,
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $isTestnet
            ? "Solana Devnet test payment verified. Added $coins test VPC."
            : "Solana $asset payment verified. Added $coins VPC.",
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
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Unable to verify Solana payment: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to verify the Solana payment. Please retry.',
    ]);
}
