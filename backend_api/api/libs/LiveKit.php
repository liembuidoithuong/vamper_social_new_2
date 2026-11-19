<?php

function liveKitSettings(): ?array
{
    $url = trim((string)getenv('LIVEKIT_URL'));
    $apiKey = trim((string)getenv('LIVEKIT_API_KEY'));
    $apiSecret = trim((string)getenv('LIVEKIT_API_SECRET'));

    if ($url === '' || $apiKey === '' || $apiSecret === '') {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['wss', 'ws'], true) || empty($parts['host'])) {
        throw new RuntimeException('LIVEKIT_URL must be a ws:// or wss:// URL.');
    }

    return [
        'url' => rtrim($url, '/'),
        'http_url' => (($parts['scheme'] === 'wss') ? 'https' : 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : ''),
        'api_key' => $apiKey,
        'api_secret' => $apiSecret,
    ];
}

function ensurePartyAdminMonitorTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS party_admin_monitors (
        monitor_id CHAR(32) NOT NULL PRIMARY KEY,
        party_id INT NOT NULL,
        admin_id INT NOT NULL,
        admin_name VARCHAR(100) NOT NULL,
        last_seen DATETIME NOT NULL,
        ended_at DATETIME NULL,
        KEY idx_party_admin_monitors_active (party_id, ended_at, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function liveKitToken(
    array $settings,
    string $identity,
    string $name,
    string $room,
    bool $roomAdmin = false,
    bool $canPublish = true,
    int $ttlSeconds = 3600,
    bool $canShareScreen = false,
    bool $micLocked = false,
    bool $canPublishCamera = false,
    bool $canPublishData = true
): string
{
    $now = time();
    $publishSources = [];
    if ($canPublish && !$micLocked) {
        $publishSources[] = 'microphone';
    }
    if ($canPublish && $canShareScreen) {
        $publishSources = array_merge(
            $publishSources,
            ['screen_share', 'screen_share_audio']
        );
    }
    if ($canPublish && $canPublishCamera) {
        $publishSources[] = 'camera';
    }
    $payload = [
        'iss' => $settings['api_key'],
        'sub' => $identity,
        'nbf' => $now,
        'iat' => $now,
        'exp' => $now + $ttlSeconds,
        'name' => $name,
        'video' => $roomAdmin
            ? ['roomAdmin' => true, 'room' => $room]
            : [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => $canPublish,
                'canPublishSources' => $publishSources,
                'canSubscribe' => true,
                'canPublishData' => $canPublishData,
            ],
    ];

    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $encodedHeader = liveKitBase64Url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $encodedPayload = liveKitBase64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $unsignedToken = $encodedHeader . '.' . $encodedPayload;
    $signature = hash_hmac('sha256', $unsignedToken, $settings['api_secret'], true);

    return $unsignedToken . '.' . liveKitBase64Url($signature);
}

function liveKitAdminRequest(array $settings, string $method, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for LiveKit room moderation.');
    }

    $token = liveKitToken(
        $settings,
        'vamper-party-server',
        'Vamper Party Server',
        $payload['room'],
        true,
        true,
        600
    );
    $curl = curl_init($settings['http_url'] . '/twirp/livekit.RoomService/' . $method);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            'LiveKit moderation request failed' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ').')
        );
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

function liveKitBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
