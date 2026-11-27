<?php
// Shared helpers: session, CSRF, PDO, JSON output, validation.
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();
// Anonymous identity used for "ownership": whoever created a route may edit/delete it.
if (empty($_SESSION['uid']))  $_SESSION['uid']  = bin2hex(random_bytes(8));
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db/rampwise.sqlite';
        if (!is_file($path)) fail(500, 'Database missing. Run: php db/seed.php');
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// One error format for every endpoint: {ok:false, error:{message, fields}}
function fail(int $status, string $message, array $fields = []): never {
    json_out(['ok' => false, 'error' => ['message' => $message, 'fields' => (object)$fields]], $status);
}

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage());               // details go to the log, not the browser
    fail(500, 'Something went wrong on the server.');
});

function require_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        fail(403, 'Invalid or missing CSRF token. Reload the page and try again.');
    }
}

function read_json(): array {
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) fail(400, 'Request body must be valid JSON.');
    return $data;
}

function to_int(mixed $v): ?int {
    if (is_int($v)) return $v;
    if (is_string($v) && preg_match('/^-?\d{1,9}$/', trim($v))) return (int)$v;
    return null;
}

function to_bool(mixed $v): ?bool {
    return match (true) {
        $v === true || $v === 1 || $v === '1'  => true,
        $v === false || $v === 0 || $v === '0' => false,
        default => null,
    };
}

function building_exists(int $id): bool {
    $st = db()->prepare('SELECT 1 FROM buildings WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

/** Server-side validation of a route payload. Returns [cleanData, errorsByField]. */
function validate_route(array $in): array {
    $e = [];
    $c = [
        'from_id'    => to_int($in['from_id'] ?? null),
        'to_id'      => to_int($in['to_id'] ?? null),
        'distance_m' => to_int($in['distance_m'] ?? null),
        'steps'      => to_int($in['steps'] ?? 0),
        'slope'      => $in['slope'] ?? null,
        'has_lift'   => to_bool($in['has_lift'] ?? false),
        'covered'    => to_bool($in['covered'] ?? false),
    ];
    if ($c['from_id'] === null || !building_exists($c['from_id'])) $e['from_id'] = 'Choose a start building.';
    if ($c['to_id']   === null || !building_exists($c['to_id']))   $e['to_id']   = 'Choose a destination.';
    if (!isset($e['from_id']) && !isset($e['to_id']) && $c['from_id'] === $c['to_id']) {
        $e['to_id'] = 'Start and destination must be different.';
    }
    if ($c['distance_m'] === null || $c['distance_m'] < 10 || $c['distance_m'] > 2000) {
        $e['distance_m'] = 'Distance must be a whole number from 10 to 2000 metres.';
    }
    if ($c['steps'] === null || $c['steps'] < 0 || $c['steps'] > 200) {
        $e['steps'] = 'Steps must be a whole number from 0 to 200.';
    }
    if (!in_array($c['slope'], ['flat', 'gentle', 'steep'], true)) $e['slope'] = 'Choose flat, gentle or steep.';
    if ($c['has_lift'] === null) $e['has_lift'] = 'Invalid value.';
    if ($c['covered']  === null) $e['covered']  = 'Invalid value.';
    return [$c, $e];
}
