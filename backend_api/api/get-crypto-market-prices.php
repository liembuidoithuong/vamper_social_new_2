<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/CryptoMarketPrices.php';

$database = new Database();
$db = $database->getConnection();
authenticateApiUser($db);

try {
    echo json_encode([
        'success' => true,
        'data' => ['prices' => fetchCryptoMarketPrices()],
    ]);
} catch (Throwable $error) {
    error_log('Unable to load crypto market prices: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Current market prices are temporarily unavailable.',
    ]);
}
