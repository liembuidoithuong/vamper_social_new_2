<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once __DIR__ . '/libs/ApiAuth.php';
require_once __DIR__ . '/libs/PolygonUsdcPayments.php';
require_once __DIR__ . '/libs/SolanaPayments.php';
require_once __DIR__ . '/libs/ConfiguredCryptoPayments.php';

$database = new Database();
$db = $database->getConnection();
authenticateApiUser($db);
$settings = polygonUsdcSettings($db);
$solanaSettings = solanaPaymentSettings($db);
$paymentMethods = configuredCryptoPublicMethods($db);
if ($settings['enabled']) {
    $paymentMethods[] = [
        'id' => 'polygon_usdc',
        'label' => $settings['network'] === 'Polygon PoS Amoy (testnet)'
            ? 'USDC - Polygon Amoy (testnet)'
            : 'USDC - Polygon PoS',
        'network' => $settings['network'],
        'asset' => 'USDC',
        'asset_type' => 'evm_token',
        'decimals' => 6,
    ];
}
if ($solanaSettings['enabled']) {
    $paymentMethods[] = [
        'id' => 'solana_sol',
        'label' => $solanaSettings['network'] === 'Solana Devnet (testnet)'
            ? 'SOL - Solana Devnet (testnet)'
            : 'SOL - Solana',
        'network' => $solanaSettings['network'],
        'asset' => 'SOL',
        'asset_type' => 'solana_native',
        'decimals' => 9,
    ];
}

echo json_encode([
    'success' => true,
    'data' => [
        'checkout_enabled' => $settings['enabled'],
        'network' => $settings['network'],
        'chain_id' => $settings['chain_id'],
        'token_contract' => $settings['token_contract'],
        'recipient_address' => $settings['recipient_address'],
        'min_confirmations' => $settings['min_confirmations'],
        'payment_methods' => $paymentMethods,
    ],
]);
