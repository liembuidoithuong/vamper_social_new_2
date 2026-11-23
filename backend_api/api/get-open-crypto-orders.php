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

    $pageSize = 20;
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
    if ($offset === false || $offset < 0 || $offset > 1000000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid checkout page.']);
        exit;
    }

    $query = $db->prepare(
        "SELECT checkout_id, payment_method, coins, asset, amount, network_key,
                chain_id, token_mint, recipient_address, status,
                sol_price_micro_usd,
                UNIX_TIMESTAMP(completed_at) AS completed_epoch,
                UNIX_TIMESTAMP(expires_at) AS expires_epoch,
                GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), expires_at) / 60)) AS expires_in_minutes,
                expires_at <= NOW() AS is_expired
         FROM (
            SELECT checkout_id, 'polygon_usdc' AS payment_method, coins,
                   'USDC' AS asset, amount_usdc AS amount, 'polygon' AS network_key,
                   chain_id, token_contract AS token_mint, recipient_address,
                   status, NULL AS sol_price_micro_usd, expires_at, created_at,
                   completed_at, COALESCE(completed_at, created_at) AS activity_at,
                   COALESCE(transaction_id, id) AS order_id
            FROM crypto_checkout_orders
            WHERE user_id = :polygon_user_id
              AND chain_id IN (137, 80002)
              AND status IN ('pending', 'expired', 'completed')
            UNION ALL
            SELECT checkout_id, CONCAT('solana_', LOWER(asset)) AS payment_method,
                   coins, UPPER(asset) AS asset, amount_display AS amount,
                   network AS network_key, NULL AS chain_id, token_mint,
                   recipient_address, status, sol_price_micro_usd, expires_at,
                   created_at, completed_at,
                   COALESCE(completed_at, created_at) AS activity_at,
                   COALESCE(transaction_id, id) AS order_id
            FROM solana_checkout_orders
            WHERE user_id = :solana_user_id
              AND network IN ('mainnet-beta', 'devnet')
              AND UPPER(asset) IN ('SOL', 'USDC', 'USDT')
              AND status IN ('pending', 'expired', 'completed')
         ) AS open_orders
         ORDER BY activity_at DESC, created_at DESC, order_id DESC, checkout_id DESC
         LIMIT :page_size OFFSET :page_offset"
    );
    $query->bindValue(':polygon_user_id', $userId, PDO::PARAM_INT);
    $query->bindValue(':solana_user_id', $userId, PDO::PARAM_INT);
    $query->bindValue(':page_size', $pageSize + 1, PDO::PARAM_INT);
    $query->bindValue(':page_offset', $offset, PDO::PARAM_INT);
    $query->execute();

    $activeCountQuery = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM crypto_checkout_orders
             WHERE user_id = :polygon_count_user
               AND status = 'pending' AND expires_at > NOW())
            +
            (SELECT COUNT(*) FROM solana_checkout_orders
             WHERE user_id = :solana_count_user
               AND status = 'pending' AND expires_at > NOW())"
    );
    $activeCountQuery->execute([
        ':polygon_count_user' => $userId,
        ':solana_count_user' => $userId,
    ]);
    $activeCount = (int)$activeCountQuery->fetchColumn();

    $orders = [];
    $hasMore = false;
    $solanaSettings = [];
    while ($order = $query->fetch(PDO::FETCH_ASSOC)) {
        if (count($orders) === $pageSize) {
            $hasMore = true;
            break;
        }
        $method = (string)$order['payment_method'];
        $asset = strtoupper((string)$order['asset']);
        if ($method === 'polygon_usdc') {
            $chainId = (int)$order['chain_id'];
            $network = match ($chainId) {
                137 => 'Polygon PoS',
                80002 => 'Polygon PoS Amoy (testnet)',
                default => 'Polygon (Chain ID ' . $chainId . ')',
            };
        } elseif (str_starts_with($method, 'solana_')) {
            $networkKey = (string)$order['network_key'];
            if (!in_array($networkKey, ['mainnet-beta', 'devnet'], true)) {
                continue;
            }
            $solanaSettings[$networkKey] ??= solanaPaymentSettings($db, $networkKey);
            $network = $solanaSettings[$networkKey]['network'];
        } else {
            continue;
        }
        if (!in_array($asset, ['SOL', 'USDC', 'USDT'], true)) {
            continue;
        }
        $orders[] = [
            'checkout_id' => $order['checkout_id'],
            'payment_method' => $method,
            'coins' => (int)$order['coins'],
            'asset' => $asset,
            'amount' => (string)$order['amount'],
            'status' => (string)$order['status'],
            'network' => $network,
            'chain_id' => $order['chain_id'] === null ? null : (int)$order['chain_id'],
            'token_mint' => $order['token_mint'],
            'recipient_address' => $order['recipient_address'],
            'expires_at_epoch' => (int)$order['expires_epoch'],
            'completed_at_epoch' => $order['completed_epoch'] === null
                ? null
                : (int)$order['completed_epoch'],
            'sol_price_usd' => $order['sol_price_micro_usd'] === null
                ? null
                : number_format((int)$order['sol_price_micro_usd'] / 1000000, 6, '.', ''),
            'expires_in_minutes' => (int)$order['expires_in_minutes'],
            'is_expired' => (bool)$order['is_expired'] || $order['status'] === 'expired',
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'orders' => $orders,
            'has_more' => $hasMore,
            'active_count' => $activeCount,
        ],
    ]);
} catch (Throwable $error) {
    error_log('Unable to load open crypto checkouts: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load your open crypto checkouts. Please try again.',
    ]);
}
