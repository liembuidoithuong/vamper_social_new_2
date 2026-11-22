<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/PolygonUsdcPayments.php';
require_once __DIR__ . '/libs/SolanaPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);

try {
    ensurePolygonUsdcPaymentSchema($db);
    ensureSolanaPaymentSchema($db);

    $orders = [];
    $polygonQuery = $db->prepare(
        "SELECT checkout_id, coins, amount_usdc, chain_id, token_contract,
                recipient_address, status, UNIX_TIMESTAMP(expires_at) AS expires_epoch,
                GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), expires_at) / 60)) AS expires_in_minutes,
                expires_at <= NOW() AS is_expired
         FROM crypto_checkout_orders
         WHERE user_id = :user_id AND status IN ('pending', 'expired')
         ORDER BY (status = 'pending') DESC, created_at DESC
         LIMIT 50"
    );
    $polygonQuery->execute([':user_id' => $userId]);
    while ($order = $polygonQuery->fetch(PDO::FETCH_ASSOC)) {
        $chainId = (int)$order['chain_id'];
        $network = match ($chainId) {
            137 => 'Polygon PoS',
            80002 => 'Polygon PoS Amoy (testnet)',
            default => 'Polygon (Chain ID ' . $chainId . ')',
        };
        $orders[] = [
            'checkout_id' => $order['checkout_id'],
            'payment_method' => 'polygon_usdc',
            'coins' => (int)$order['coins'],
            'asset' => 'USDC',
            'amount' => (string)$order['amount_usdc'],
            'network' => $network,
            'chain_id' => $chainId,
            'token_mint' => $order['token_contract'],
            'recipient_address' => $order['recipient_address'],
            'expires_at_epoch' => (int)$order['expires_epoch'],
            'expires_in_minutes' => (int)$order['expires_in_minutes'],
            'is_expired' => (bool)$order['is_expired'] || $order['status'] === 'expired',
        ];
    }

    $solanaQuery = $db->prepare(
        "SELECT checkout_id, network, asset, token_mint, recipient_address,
                amount_display, sol_price_micro_usd, coins, status,
                UNIX_TIMESTAMP(expires_at) AS expires_epoch,
                GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), expires_at) / 60)) AS expires_in_minutes,
                expires_at <= NOW() AS is_expired
         FROM solana_checkout_orders
         WHERE user_id = :user_id AND status IN ('pending', 'expired')
         ORDER BY (status = 'pending') DESC, created_at DESC
         LIMIT 50"
    );
    $solanaQuery->execute([':user_id' => $userId]);
    while ($order = $solanaQuery->fetch(PDO::FETCH_ASSOC)) {
        $networkKey = (string)$order['network'];
        if (!in_array($networkKey, ['mainnet-beta', 'devnet'], true)) {
            continue;
        }
        $settings = solanaPaymentSettings($db, $networkKey);
        $asset = strtoupper((string)$order['asset']);
        if (!in_array($asset, ['SOL', 'USDC', 'USDT'], true)) {
            continue;
        }
        $priceMicroUsd = $order['sol_price_micro_usd'] === null
            ? null
            : (int)$order['sol_price_micro_usd'];
        $orders[] = [
            'checkout_id' => $order['checkout_id'],
            'payment_method' => 'solana_' . strtolower($asset),
            'coins' => (int)$order['coins'],
            'asset' => $asset,
            'amount' => (string)$order['amount_display'],
            'network' => $settings['network'],
            'chain_id' => null,
            'token_mint' => $order['token_mint'],
            'recipient_address' => $order['recipient_address'],
            'expires_at_epoch' => (int)$order['expires_epoch'],
            'sol_price_usd' => $priceMicroUsd === null
                ? null
                : number_format($priceMicroUsd / 1000000, 6, '.', ''),
            'expires_in_minutes' => (int)$order['expires_in_minutes'],
            'is_expired' => (bool)$order['is_expired'] || $order['status'] === 'expired',
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => ['orders' => $orders],
    ]);
} catch (Throwable $error) {
    error_log('Unable to load open crypto checkouts: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load your open crypto checkouts. Please try again.',
    ]);
}
