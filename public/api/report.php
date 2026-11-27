<?php
// POST = report a route closed. DELETE = reopen it (reporter or route owner only).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['POST', 'DELETE'], true)) { header('Allow: POST, DELETE'); fail(405, 'Method not allowed.'); }
require_csrf();

function load_route(int $id): array {
    $st = db()->prepare('SELECT * FROM routes WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail(404, 'Route not found.');
    return $row;
}

if ($method === 'POST') {
    $in = read_json();
    $id = to_int($in['route_id'] ?? null);
    if ($id === null) fail(400, 'Missing route id.');
    $reason = $in['reason'] ?? '';
    $reason = is_string($reason) ? trim($reason) : '';
    $len = mb_strlen($reason);
    if ($len < 10 || $len > 200) {
        fail(422, 'Please fix the highlighted fields.', ['reason' => 'Give a reason of 10 to 200 characters.']);
    }
    $route = load_route($id);
    if ($route['status'] === 'closed') fail(409, 'This route is already reported as closed.');

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE routes SET status='closed', closed_reason=?, closed_by=? WHERE id=?")
        ->execute([$reason, $_SESSION['uid'], $id]);
    $pdo->prepare('INSERT INTO reports (route_id, reason, reporter_uid) VALUES (?,?,?)')
        ->execute([$id, $reason, $_SESSION['uid']]);
    $pdo->commit();
    json_out(['ok' => true, 'route_id' => $id, 'status' => 'closed'], 201);
}

// DELETE: reopen
$id = to_int($_GET['route_id'] ?? null);
if ($id === null) fail(400, 'Missing route id.');
$route = load_route($id);
if ($route['status'] !== 'closed') fail(409, 'This route is not closed.');
$uid = $_SESSION['uid'];
if ($route['closed_by'] !== $uid && $route['owner_uid'] !== $uid) {
    fail(403, 'Only the person who reported the closure, or the route owner, can reopen it.');
}
db()->prepare("UPDATE routes SET status='open', closed_reason=NULL, closed_by=NULL WHERE id=?")->execute([$id]);
json_out(['ok' => true, 'route_id' => $id, 'status' => 'open']);
