<?php

function ensureDeviceSecuritySchema(PDO $db): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $existingTables = $db->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('device_installations', 'user_devices', 'device_security_config')"
    )->fetchColumn();
    if ((int)$existingTables === 3) {
        $schemaReady = true;
        return;
    }

    $db->exec("CREATE TABLE IF NOT EXISTS device_installations (
        device_id CHAR(36) NOT NULL PRIMARY KEY,
        is_blocked TINYINT(1) NOT NULL DEFAULT 0,
        block_reason VARCHAR(500) NULL,
        blocked_by INT NULL,
        blocked_at DATETIME NULL,
        first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_device_installations_last_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS user_devices (
        device_id CHAR(36) NOT NULL,
        user_id INT NOT NULL,
        first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (device_id, user_id),
        KEY idx_user_devices_user (user_id, last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS device_security_config (
        id TINYINT NOT NULL PRIMARY KEY,
        enforcement_enabled TINYINT(1) NOT NULL DEFAULT 0,
        max_accounts INT NOT NULL DEFAULT 3,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT IGNORE INTO device_security_config (id, enforcement_enabled, max_accounts)
        VALUES (1, 0, 3)");
    $schemaReady = true;
}

function normalizeDeviceId(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $deviceId = strtolower(trim($value));
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $deviceId)
        ? $deviceId
        : null;
}

function deviceSecurityConfig(PDO $db): array
{
    ensureDeviceSecuritySchema($db);
    $config = $db->query(
        'SELECT enforcement_enabled, max_accounts FROM device_security_config WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC);

    return [
        'enforcement_enabled' => (int)($config['enforcement_enabled'] ?? 0) === 1,
        'max_accounts' => max(1, (int)($config['max_accounts'] ?? 3)),
    ];
}

function beginDeviceAccountRegistration(PDO $db, ?string $deviceId): void
{
    ensureDeviceSecuritySchema($db);
    $config = deviceSecurityConfig($db);
    if ($config['enforcement_enabled'] && $deviceId === null) {
        throw new DomainException('Please update the app before creating an account.');
    }

    $db->beginTransaction();
    if ($deviceId === null) {
        return;
    }

    $upsert = $db->prepare(
        'INSERT INTO device_installations (device_id, last_seen_at)
         VALUES (:device_id, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP()'
    );
    $upsert->execute([':device_id' => $deviceId]);

    $device = $db->prepare('SELECT is_blocked FROM device_installations WHERE device_id = :device_id FOR UPDATE');
    $device->execute([':device_id' => $deviceId]);
    if ((int)$device->fetchColumn() === 1) {
        throw new DomainException('Account creation is not available from this device.');
    }

    if ($config['enforcement_enabled']) {
        $count = $db->prepare('SELECT COUNT(*) FROM user_devices WHERE device_id = :device_id');
        $count->execute([':device_id' => $deviceId]);
        if ((int)$count->fetchColumn() >= $config['max_accounts']) {
            throw new DomainException('This device has reached the account creation limit.');
        }
    }
}

function linkUserToDevice(PDO $db, int $userId, ?string $deviceId): void
{
    if ($deviceId === null) {
        return;
    }

    $db->prepare(
        'INSERT INTO user_devices (device_id, user_id, last_seen_at)
         VALUES (:device_id, :user_id, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP()'
    )->execute([
        ':device_id' => $deviceId,
        ':user_id' => $userId,
    ]);
}

function assertDeviceNotBlocked(PDO $db, ?string $deviceId): void
{
    $config = deviceSecurityConfig($db);
    if ($deviceId === null) {
        if ($config['enforcement_enabled']) {
            throw new DomainException('Please update the app before signing in.');
        }
        return;
    }

    $stmt = $db->prepare('SELECT is_blocked FROM device_installations WHERE device_id = :device_id LIMIT 1');
    $stmt->execute([':device_id' => $deviceId]);
    if ((int)$stmt->fetchColumn() === 1) {
        throw new DomainException('This device has been blocked. Please contact support.');
    }
}
