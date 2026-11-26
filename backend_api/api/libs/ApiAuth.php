<?php
require_once __DIR__ . '/DeviceSecurity.php';

function ensureApiTokensTable(PDO $db): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $schema = $db->query(
        "SELECT
            EXISTS(SELECT 1 FROM information_schema.tables
                   WHERE table_schema = DATABASE() AND table_name = 'api_tokens') AS table_exists,
            EXISTS(SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'api_tokens'
                     AND column_name = 'device_id') AS device_column_exists,
            EXISTS(SELECT 1 FROM information_schema.statistics
                   WHERE table_schema = DATABASE() AND table_name = 'api_tokens'
                     AND index_name = 'idx_api_tokens_device_id') AS device_index_exists"
    )->fetch(PDO::FETCH_ASSOC);

    if ((int)$schema['table_exists'] === 0) {
        $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            device_id CHAR(36) NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_api_tokens_user_id (user_id),
            INDEX idx_api_tokens_device_id (device_id),
            INDEX idx_api_tokens_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        if ((int)$schema['device_column_exists'] === 0) {
            $db->exec('ALTER TABLE api_tokens ADD COLUMN device_id CHAR(36) NULL AFTER user_id');
        }
        if ((int)$schema['device_index_exists'] === 0) {
            $db->exec('ALTER TABLE api_tokens ADD INDEX idx_api_tokens_device_id (device_id)');
        }
    }
    $schemaReady = true;
}

function issueApiToken(PDO $db, int $userId, ?string $deviceId = null): string
{
    ensureApiTokensTable($db);
    ensureDeviceSecuritySchema($db);
    assertDeviceNotBlocked($db, $deviceId);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        if ($deviceId !== null) {
            $upsert = $db->prepare(
                'INSERT INTO device_installations (device_id, last_seen_at)
                 VALUES (:device_id, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP()'
            );
            $upsert->execute([':device_id' => $deviceId]);
            assertDeviceNotBlocked($db, $deviceId);
            linkUserToDevice($db, $userId, $deviceId);
        }

        $stmt = $db->prepare(
            "INSERT INTO api_tokens (token_hash, user_id, device_id, expires_at)
             VALUES (:token_hash, :user_id, :device_id, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))"
        );
        $stmt->execute([
            ':token_hash' => $tokenHash,
            ':user_id' => $userId,
            ':device_id' => $deviceId,
        ]);
        if ($ownsTransaction) {
            $db->commit();
        }
        return $token;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function authenticateApiUser(PDO $db): int
{
    try {
        ensureApiTokensTable($db);
        ensureDeviceSecuritySchema($db);
    } catch (PDOException $error) {
        error_log('Unable to initialize API token storage: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication service is unavailable.',
        ]);
        exit();
    }

    $token = readBearerToken();
    if ($token === null) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required. Please sign in again.',
        ]);
        exit();
    }

    try {
        $stmt = $db->prepare(
            "SELECT t.user_id, t.device_id
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             LEFT JOIN device_installations d ON d.device_id = t.device_id
             LEFT JOIN device_security_config c ON c.id = 1
             WHERE t.token_hash = :token_hash
               AND t.expires_at > UTC_TIMESTAMP()
               AND u.is_active = 1
               AND (d.is_blocked IS NULL OR d.is_blocked = 0)
               AND (t.device_id IS NOT NULL OR COALESCE(c.enforcement_enabled, 0) = 0)
             LIMIT 1"
        );
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        error_log('Unable to validate API token: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication service is unavailable.',
        ]);
        exit();
    }

    if (!$session) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Session expired or invalid. Please sign in again.',
        ]);
        exit();
    }

    if (!empty($session['device_id'])) {
        $db->prepare(
            'UPDATE device_installations
             SET last_seen_at = UTC_TIMESTAMP()
             WHERE device_id = :device_id
               AND last_seen_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE'
        )->execute([':device_id' => $session['device_id']]);
        $db->prepare(
            'UPDATE user_devices
             SET last_seen_at = UTC_TIMESTAMP()
             WHERE device_id = :device_id AND user_id = :user_id
               AND last_seen_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE'
        )->execute([':device_id' => $session['device_id'], ':user_id' => (int)$session['user_id']]);
    }

    return (int)$session['user_id'];
}

function revokeApiToken(PDO $db): void
{
    authenticateApiUser($db);
    $token = readBearerToken();
    if ($token === null) {
        return;
    }

    $stmt = $db->prepare('DELETE FROM api_tokens WHERE token_hash = :token_hash');
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
}

function readBearerToken(): ?string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $authorization = $value;
                break;
            }
        }
    }

    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches)
        ? $matches[1]
        : null;
}
