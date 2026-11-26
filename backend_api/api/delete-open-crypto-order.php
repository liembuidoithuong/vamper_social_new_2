<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/PolygonUsdcPayments.php';
require_once __DIR__ . '/libs/SolanaPayments.php';
require_once __DIR__ . '/libs/ConfiguredCryptoPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$checkoutId = is_array($data) ? trim((string)($data['checkout_id'] ?? '')) : '';

if (!preg_match('/^[a-f0-9-]{36}$/iD', $checkoutId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid checkout ID.']);
    exit;
}

try {
    ensurePolygonUsdcPaymentSchema($db);
    ensureSolanaPaymentSchema($db);
    ensureConfiguredCryptoSchema($db);
    $db->beginTransaction();

    $orders = [
        [
            'table' => 'crypto_checkout_orders',
            'reference' => 'transaction_hash',
        ],
        [
            'table' => 'solana_checkout_orders',
            'reference' => 'transaction_signature',
        ],
        [
            'table' => 'configured_crypto_checkout_orders',
            'reference' => 'transaction_reference',
        ],
    ];
    foreach ($orders as $order) {
        $table = $order['table'];
        $reference = $order['reference'];
        $query = $db->prepare(
            "SELECT status, `$reference` AS payment_reference,
                    expires_at > NOW() AS not_expired
             FROM `$table`
             WHERE checkout_id = :checkout_id AND user_id = :user_id
             FOR UPDATE"
        );
        $query->execute([
            ':checkout_id' => $checkoutId,
            ':user_id' => $userId,
        ]);
        $checkout = $query->fetch(PDO::FETCH_ASSOC);
        if (!$checkout) {
            continue;
        }
        if (
            !in_array($checkout['status'], ['pending', 'expired'], true)
            || $checkout['payment_reference'] !== null
        ) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'This checkout can no longer be removed.',
            ]);
            exit;
        }

        $delete = $db->prepare(
            "UPDATE `$table` SET status = 'cancelled'
             WHERE checkout_id = :checkout_id AND user_id = :user_id
               AND status IN ('pending', 'expired')"
        );
        $delete->execute([
            ':checkout_id' => $checkoutId,
            ':user_id' => $userId,
        ]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('The checkout changed while it was being removed.');
        }

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Checkout removed.',
            'was_active' => $checkout['status'] === 'pending'
                && (bool)$checkout['not_expired'],
        ]);
        exit;
    }

    $db->rollBack();
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Checkout was not found.',
    ]);
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Unable to remove crypto checkout: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to remove this checkout. Please try again.',
    ]);
}
