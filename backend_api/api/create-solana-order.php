<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/SolanaPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$packages = polygonUsdcCoinPackages();
$coins = is_array($data) ? filter_var($data['coins'] ?? null, FILTER_VALIDATE_INT) : false;
$asset = is_array($data) ? strtoupper(trim((string)($data['asset'] ?? ''))) : '';

if ($coins === false || !isset($packages[$coins]) || !in_array($asset, ['SOL', 'USDC', 'USDT'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select a valid coin package and Solana asset.']);
    exit;
}

try {
    $settings = solanaPaymentSettings();
    if (!$settings['enabled']) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Solana top-ups are not configured yet.']);
        exit;
    }

    $tokenMint = match ($asset) {
        'USDC' => $settings['usdc_mint'],
        'USDT' => $settings['usdt_mint'],
        default => null,
    };
    if ($asset !== 'SOL' && $tokenMint === null) {
        throw new DomainException("$asset payments are not configured on the selected Solana network.");
    }

    assertSolanaNetwork($settings);
    if ($tokenMint !== null) {
        assertSolanaStablecoinMint($settings, $tokenMint);
        assertSolanaRecipientTokenAccount($settings, $tokenMint);
    }
    ensureSolanaPaymentSchema($db);

    $packagePrice = $packages[$coins];
    $packageCents = (int)str_replace('.', '', $packagePrice);
    $priceMicroUsd = $asset === 'SOL' ? solanaFetchPriceMicroUsd($db) : null;
    $decimals = $asset === 'SOL' ? 9 : 6;
    if ($asset === 'SOL') {
        $quote = solanaCalculateSolQuote($packageCents, $priceMicroUsd);
        $baseUnits = $quote['base_units'];
        $maxSuffix = $quote['max_suffix'];
        $expiresInMinutes = 15;
    } else {
        $baseUnits = $packageCents * 10000;
        $maxSuffix = 9999;
        $expiresInMinutes = 24 * 60;
    }
    $checkoutId = solanaCheckoutId();
    $db->beginTransaction();
    $db->query('SELECT id FROM solana_payment_allocator WHERE id = 1 FOR UPDATE')->fetchColumn();
    $db->exec(
        "UPDATE solana_checkout_orders SET status = 'expired'
         WHERE status = 'pending' AND expires_at <= NOW()"
    );

    $userActiveQuery = $db->prepare(
        "SELECT COUNT(*) FROM solana_checkout_orders
         WHERE status = 'pending' AND user_id = :user_id"
    );
    $userActiveQuery->execute([':user_id' => $userId]);
    if ((int)$userActiveQuery->fetchColumn() >= 5) {
        throw new DomainException('You already have several open Solana checkouts. Complete one or try again later.');
    }

    $activeAmountsQuery = $db->prepare(
        "SELECT amount_base_units FROM solana_checkout_orders
         WHERE status = 'pending' AND asset = :asset"
    );
    $activeAmountsQuery->execute([':asset' => $asset]);
    $usedAmounts = [];
    while (($usedAmount = $activeAmountsQuery->fetchColumn()) !== false) {
        $usedAmounts[(string)$usedAmount] = true;
    }

    $suffixStart = random_int(1, $maxSuffix);
    $amountBaseUnits = null;
    for ($attempt = 0; $attempt < $maxSuffix; $attempt++) {
        $suffix = (($suffixStart + $attempt - 1) % $maxSuffix) + 1;
        $candidate = $baseUnits + $suffix;
        if (!isset($usedAmounts[(string)$candidate])) {
            $amountBaseUnits = $candidate;
            break;
        }
    }
    if ($amountBaseUnits === null) {
        throw new RuntimeException('There are too many open Solana checkouts. Please try again later.');
    }

    $amountDisplay = solanaFormatBaseUnits($amountBaseUnits, $decimals);
    $expiresSqlMinutes = $expiresInMinutes === 15 ? 15 : 1440;
    $insert = $db->prepare(
        "INSERT INTO solana_checkout_orders
            (checkout_id, user_id, network, asset, token_mint, recipient_address,
             amount_base_units, amount_display, amount_usd, sol_price_micro_usd,
             coins, status, expires_at)
         VALUES
            (:checkout_id, :user_id, :network, :asset, :token_mint, :recipient,
             :amount_base_units, :amount_display, :amount_usd, :sol_price_micro_usd,
             :coins, 'pending', DATE_ADD(NOW(), INTERVAL $expiresSqlMinutes MINUTE))"
    );
    $insert->execute([
        ':checkout_id' => $checkoutId,
        ':user_id' => $userId,
        ':network' => $settings['network_key'],
        ':asset' => $asset,
        ':token_mint' => $tokenMint,
        ':recipient' => $settings['recipient_address'],
        ':amount_base_units' => $amountBaseUnits,
        ':amount_display' => $amountDisplay,
        ':amount_usd' => $packagePrice,
        ':sol_price_micro_usd' => $priceMicroUsd,
        ':coins' => $coins,
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'checkout_id' => $checkoutId,
            'coins' => $coins,
            'asset' => $asset,
            'amount' => $amountDisplay,
            'currency' => $asset,
            'network' => $settings['network'],
            'network_key' => $settings['network_key'],
            'token_mint' => $tokenMint,
            'recipient_address' => $settings['recipient_address'],
            'sol_price_usd' => $priceMicroUsd === null
                ? null
                : number_format($priceMicroUsd / 1000000, 6, '.', ''),
            'expires_in_minutes' => $expiresInMinutes,
        ],
    ]);
} catch (DomainException $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Unable to create Solana checkout: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to start a Solana checkout. Please try again later.',
    ]);
}
