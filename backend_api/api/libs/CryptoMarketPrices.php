<?php

function fetchCryptoMarketPrices(): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to fetch market prices.');
    }

    $url = 'https://api.coingecko.com/api/v3/simple/price'
        . '?ids=solana%2Cusd-coin&vs_currencies=usd&include_last_updated_at=true';
    $headers = ['Accept: application/json', 'User-Agent: VamperSocialPayments/1.0'];
    $apiKey = trim((string)getenv('COINGECKO_API_KEY'));
    if ($apiKey !== '') {
        $headers[] = 'x-cg-demo-api-key: ' . $apiKey;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Unable to fetch crypto market prices (HTTP ' . $status . '): ' . $curlError);
        throw new RuntimeException('Current market prices are temporarily unavailable.');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The market price provider returned an invalid response.');
    }

    $assets = [
        'SOL' => ['id' => 'solana', 'maximum' => 10000],
        'USDC' => ['id' => 'usd-coin', 'maximum' => 10],
    ];
    $prices = [];
    foreach ($assets as $symbol => $asset) {
        $price = $decoded[$asset['id']]['usd'] ?? null;
        $updatedAt = $decoded[$asset['id']]['last_updated_at'] ?? null;
        if (
            !is_numeric($price)
            || !is_int($updatedAt)
            || $updatedAt > time() + 30
            || time() - $updatedAt > 180
            || (float)$price <= 0
            || (float)$price > $asset['maximum']
        ) {
            throw new RuntimeException('The ' . $symbol . '/USD market quote is invalid or stale.');
        }

        $prices[$symbol] = [
            'price_usd' => number_format((float)$price, 6, '.', ''),
            'updated_at' => gmdate(DATE_ATOM, $updatedAt),
        ];
    }

    return $prices;
}
