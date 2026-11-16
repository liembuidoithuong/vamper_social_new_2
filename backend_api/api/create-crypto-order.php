<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/PolygonUsdcPayments.php';

$database = new Database();
$db = $database->getConnection();
$userId = authenticateApiUser($db);
$data = json_decode(file_get_contents('php://input'), true);
$packages = polygonUsdcCoinPackages();
$coins = is_array($data) ? filter_var($data['coins'] ?? null, FILTER_VALIDATE_INT) : false;

if ($coins === false || !isset($packages[$coins])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select a valid coin package.']);
    exit;
}

try {
    $settings = polygonUsdcSettings();
    if (!$settings['enabled']) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'USDC top-ups are not configured yet.',
        ]);
        exit;
    }

    assertPolygonUsdcNetwork($settings);
    ensurePolygonUsdcPaymentSchema($db);
    $checkoutId = polygonUsdcCheckoutId();
    $baseAmount = (int)polygonUsdcAmountToBaseUnits($packages[$coins]);
    $db->beginTransaction();
    $db->query('SELECT id FROM crypto_payment_allocator WHERE id = 1 FOR UPDATE')->fetchColumn();
    $db->exec(
        "UPDATE crypto_checkout_orders SET status = 'expired'
         WHERE status = 'pending' AND expires_at <= NOW()"
    );

    $activeQuery = $db->prepare(
        "SELECT amount_usdc FROM crypto_checkout_orders
         WHERE status = 'pending' AND coins = :coins"
    );
    $activeQuery->execute([':coins' => $coins]);
    $usedSuffixes = [];
    while (($activeAmount = $activeQuery->fetchColumn()) !== false) {
        $suffix = (int)polygonUsdcAmountToBaseUnits((string)$activeAmount) - $baseAmount;
        if ($suffix >= 1 && $suffix <= 9999) {
            $usedSuffixes[$suffix] = true;
        }
    }

    $userActiveQuery = $db->prepare(
        "SELECT COUNT(*) FROM crypto_checkout_orders
         WHERE status = 'pending' AND user_id = :user_id"
    );
    $userActiveQuery->execute([':user_id' => $userId]);
    if ((int)$userActiveQuery->fetchColumn() >= 5) {
        throw new DomainException('You already have several open USDC checkouts. Complete one or try again later.');
    }

    $amount = null;
    $startSuffix = random_int(1, 9999);
    for ($attempt = 0; $attempt < 9999; $attempt++) {
        $suffix = (($startSuffix + $attempt - 1) % 9999) + 1;
        if (!isset($usedSuffixes[$suffix])) {
            $amount = polygonUsdcFormatBaseUnits($baseAmount + $suffix);
            break;
        }
    }
    if ($amount === null) {
        throw new RuntimeException('There are too many open checkouts. Please try again later.');
    }

    $insert = $db->prepare(
        "INSERT INTO crypto_checkout_orders
            (checkout_id, user_id, chain_id, token_contract, recipient_address,
             amount_usdc, coins, status, expires_at)
         VALUES (:checkout_id, :user_id, :chain_id, :token_contract, :recipient,
                 :amount, :coins, 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR))"
    );
    $insert->execute([
        ':checkout_id' => $checkoutId,
        ':user_id' => $userId,
        ':chain_id' => $settings['chain_id'],
        ':token_contract' => $settings['token_contract'],
        ':recipient' => $settings['recipient_address'],
        ':amount' => $amount,
        ':coins' => $coins,
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'checkout_id' => $checkoutId,
            'coins' => $coins,
            'amount_usdc' => $amount,
            'currency' => 'USDC',
            'network' => $settings['network'],
            'chain_id' => $settings['chain_id'],
            'token_contract' => $settings['token_contract'],
            'recipient_address' => $settings['recipient_address'],
            'min_confirmations' => $settings['min_confirmations'],
            'expires_in_hours' => 24,
        ],
    ]);
} catch (DomainException $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Unable to create Polygon USDC checkout: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to start a USDC checkout. Please try again later.',
    ]);
}
