<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/libs/AdminTools.php';
require_once __DIR__ . '/api/libs/LiveKit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST is required.']);
    exit;
}

session_name('vamper_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

if (
    ($_SESSION['admin_logged_in'] ?? false) !== true
    || !is_int($_SESSION['admin_id'] ?? null)
    || !is_string($_SESSION['admin_csrf'] ?? null)
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Administrator sign-in is required.']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
if (
    !is_string($data['csrf_token'] ?? null)
    || !hash_equals($_SESSION['admin_csrf'], $data['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'The security token is invalid or expired.']);
    exit;
}

$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$partyId = filter_var($data['party_id'] ?? null, FILTER_VALIDATE_INT);
$monitorId = is_string($data['monitor_id'] ?? null) ? $data['monitor_id'] : '';

if (!$partyId || $partyId < 1 || !in_array($action, ['start', 'heartbeat', 'stop'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid room and action are required.']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    assertAdminApiPermission($db, (int)$_SESSION['admin_id'], 'party_monitoring');
    ensurePartyAdminMonitorTable($db);

    if ($action === 'start') {
        $partyQuery = $db->prepare(
            'SELECT title, is_live FROM parties WHERE id = :id LIMIT 1'
        );
        $partyQuery->execute([':id' => $partyId]);
        $party = $partyQuery->fetch(PDO::FETCH_ASSOC);
        if (!$party || (int)$party['is_live'] !== 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'This room is not live.']);
            exit;
        }

        $settings = liveKitSettings();
        if ($settings === null) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'LiveKit is not configured on the server.']);
            exit;
        }

        $monitorId = bin2hex(random_bytes(16));
        $adminName = mb_substr((string)($_SESSION['admin_name'] ?? 'Vamper Admin'), 0, 100);
        $db->beginTransaction();
        $db->exec(
            'DELETE FROM party_admin_monitors
             WHERE ended_at < UTC_TIMESTAMP() - INTERVAL 1 DAY
                OR (ended_at IS NULL AND last_seen < UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        );
        $insertMonitor = $db->prepare(
            'INSERT INTO party_admin_monitors (monitor_id, party_id, admin_id, admin_name, last_seen)
             VALUES (:monitor_id, :party_id, :admin_id, :admin_name, UTC_TIMESTAMP())'
        );
        $insertMonitor->execute([
            ':monitor_id' => $monitorId,
            ':party_id' => $partyId,
            ':admin_id' => (int)$_SESSION['admin_id'],
            ':admin_name' => $adminName,
        ]);
        writeAdminAudit(
            $db,
            (int)$_SESSION['admin_id'],
            'party_audio_monitor_started',
            'party',
            $partyId,
            ['monitor_id' => $monitorId]
        );
        $db->commit();

        echo json_encode([
            'success' => true,
            'data' => [
                'monitor_id' => $monitorId,
                'livekit_url' => $settings['url'],
                'livekit_token' => liveKitToken(
                    $settings,
                    'admin-monitor-' . $monitorId,
                    'Admin listening',
                    'vamper-party-' . $partyId,
                    false,
                    false,
                    3600,
                    false,
                    false,
                    false,
                    false
                ),
            ],
        ]);
        exit;
    }

    if (!preg_match('/^[a-f0-9]{32}$/D', $monitorId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The monitoring session is invalid.']);
        exit;
    }

    if ($action === 'heartbeat') {
        $heartbeat = $db->prepare(
            'UPDATE party_admin_monitors SET last_seen = UTC_TIMESTAMP()
             WHERE monitor_id = :monitor_id AND party_id = :party_id AND admin_id = :admin_id
               AND ended_at IS NULL'
        );
        $heartbeat->execute([
            ':monitor_id' => $monitorId,
            ':party_id' => $partyId,
            ':admin_id' => (int)$_SESSION['admin_id'],
        ]);
        $check = $db->prepare(
            'SELECT 1 FROM party_admin_monitors
             WHERE monitor_id = :monitor_id AND party_id = :party_id AND admin_id = :admin_id
               AND ended_at IS NULL LIMIT 1'
        );
        $check->execute([
            ':monitor_id' => $monitorId,
            ':party_id' => $partyId,
            ':admin_id' => (int)$_SESSION['admin_id'],
        ]);
        if (!$check->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'The monitoring session is no longer active.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    $monitor = $db->prepare(
        'SELECT ended_at FROM party_admin_monitors
         WHERE monitor_id = :monitor_id AND party_id = :party_id AND admin_id = :admin_id LIMIT 1'
    );
    $monitor->execute([
        ':monitor_id' => $monitorId,
        ':party_id' => $partyId,
        ':admin_id' => (int)$_SESSION['admin_id'],
    ]);
    $endedAt = $monitor->fetchColumn();
    if ($endedAt === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'The monitoring session was not found.']);
        exit;
    }

    if ($endedAt === null) {
        $stop = $db->prepare(
            'UPDATE party_admin_monitors SET ended_at = UTC_TIMESTAMP()
             WHERE monitor_id = :monitor_id AND party_id = :party_id AND admin_id = :admin_id
               AND ended_at IS NULL'
        );
        $stop->execute([
            ':monitor_id' => $monitorId,
            ':party_id' => $partyId,
            ':admin_id' => (int)$_SESSION['admin_id'],
        ]);
        writeAdminAudit(
            $db,
            (int)$_SESSION['admin_id'],
            'party_audio_monitor_stopped',
            'party',
            $partyId,
            ['monitor_id' => $monitorId]
        );
    }

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Admin party audio monitor request failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update the audio monitoring session.']);
}
