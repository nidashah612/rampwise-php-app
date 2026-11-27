<?php
// JSON endpoint: GET (search) / POST (create) / PUT (update) / DELETE
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';

const PACE_M_PER_MIN = ['slow' => 50, 'normal' => 75, 'fast' => 95];
// Sort options are a fixed whitelist; user input only selects a key, it is never put into SQL.
const SORTS = [
    'time'   => 'r.status ASC, r.distance_m ASC',
    'access' => "r.status ASC, r.steps ASC, CASE r.slope WHEN 'flat' THEN 0 WHEN 'gentle' THEN 1 ELSE 2 END ASC, r.covered DESC, r.distance_m ASC",
];
const BASE_SQL = 'SELECT r.*, bf.name AS from_name, bt.name AS to_name
                  FROM routes r
                  JOIN buildings bf ON bf.id = r.from_id
                  JOIN buildings bt ON bt.id = r.to_id';

function shape(array $r, string $pace = 'normal'): array {
    $uid = $_SESSION['uid'];
    return [
        'id'            => (int)$r['id'],
        'from_id'       => (int)$r['from_id'],
        'to_id'         => (int)$r['to_id'],
        'from_name'     => $r['from_name'],
        'to_name'       => $r['to_name'],
        'distance_m'    => (int)$r['distance_m'],
        'steps'         => (int)$r['steps'],
        'slope'         => $r['slope'],
        'has_lift'      => (bool)$r['has_lift'],
        'covered'       => (bool)$r['covered'],
        'minutes'       => max(1, (int)ceil($r['distance_m'] / PACE_M_PER_MIN[$pace])),
        'status'        => $r['status'],
        'closed_reason' => $r['closed_reason'],
        'mine'          => $r['owner_uid'] === $uid,
        'can_reopen'    => $r['status'] === 'closed' && ($r['closed_by'] === $uid || $r['owner_uid'] === $uid),
    ];
}

function find_route(int $id): array {
    $st = db()->prepare(BASE_SQL . ' WHERE r.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail(404, 'Route not found.');
    return $row;
}

/** Ownership rule: only the session that created a route may change it. */
function require_owner(array $route): void {
    if ($route['owner_uid'] === null || $route['owner_uid'] !== $_SESSION['uid']) {
        fail(403, 'You can only change routes you added yourself.');
    }
}

function route_params(array $c): array {
    return [':f' => $c['from_id'], ':t' => $c['to_id'], ':d' => $c['distance_m'], ':s' => $c['steps'],
            ':sl' => $c['slope'], ':l' => (int)$c['has_lift'], ':c' => (int)$c['covered']];
}

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        $from = to_int($_GET['from'] ?? null);
        $to   = to_int($_GET['to'] ?? null);
        $errs = [];
        if ($from === null) $errs['from'] = 'Choose where you are.';
        if ($to   === null) $errs['to']   = 'Choose where you want to go.';
        if (!$errs && $from === $to) $errs['to'] = 'Start and destination must be different.';
        $pace = $_GET['pace'] ?? 'normal';
        if (!is_string($pace) || !isset(PACE_M_PER_MIN[$pace])) $errs['pace'] = 'Choose slow, normal or fast.';
        $sort = $_GET['sort'] ?? 'time';
        if (!is_string($sort) || !isset(SORTS[$sort])) $errs['sort'] = 'Sort by time or accessibility.';
        $max = null;
        if (($_GET['max_m'] ?? '') !== '') {
            $max = to_int($_GET['max_m']);
            if ($max === null || $max < 10 || $max > 2000) $errs['max_m'] = 'Max distance must be 10 to 2000 m.';
        }
        if ($errs) fail(422, 'Please fix the highlighted fields.', $errs);

        // Routes are two-way: match the pair in either direction. Every value is a bound parameter.
        $sql = BASE_SQL . ' WHERE ((r.from_id = :a AND r.to_id = :b) OR (r.from_id = :b AND r.to_id = :a))';
        $params = [':a' => $from, ':b' => $to];
        if (($_GET['step_free'] ?? '') === '1')   $sql .= ' AND (r.steps = 0 OR r.has_lift = 1)';
        if (($_GET['covered'] ?? '') === '1')     $sql .= ' AND r.covered = 1';
        if (($_GET['avoid_steep'] ?? '') === '1') $sql .= " AND r.slope <> 'steep'";
        if ($max !== null) { $sql .= ' AND r.distance_m <= :max'; $params[':max'] = $max; }
        $sql .= ' ORDER BY ' . SORTS[$sort];   // open routes first, then fastest or most accessible

        $st = db()->prepare($sql);
        $st->execute($params);
        json_out(['ok' => true, 'routes' => array_map(fn($r) => shape($r, $pace), $st->fetchAll())]);

    case 'POST':
        require_csrf();
        [$c, $errs] = validate_route(read_json());
        if ($errs) fail(422, 'Please fix the highlighted fields.', $errs);
        $st = db()->prepare(
            'INSERT INTO routes (from_id,to_id,distance_m,steps,slope,has_lift,covered,owner_uid)
             VALUES (:f,:t,:d,:s,:sl,:l,:c,:o)'
        );
        $st->execute(route_params($c) + [':o' => $_SESSION['uid']]);
        json_out(['ok' => true, 'route' => shape(find_route((int)db()->lastInsertId()))], 201);

    case 'PUT':
        require_csrf();
        $id = to_int($_GET['id'] ?? null);
        if ($id === null) fail(400, 'Missing route id.');
        require_owner(find_route($id));
        [$c, $errs] = validate_route(read_json());
        if ($errs) fail(422, 'Please fix the highlighted fields.', $errs);
        $st = db()->prepare(
            'UPDATE routes SET from_id=:f, to_id=:t, distance_m=:d, steps=:s, slope=:sl, has_lift=:l, covered=:c
             WHERE id=:id AND owner_uid=:o'
        );
        $st->execute(route_params($c) + [':id' => $id, ':o' => $_SESSION['uid']]);
        json_out(['ok' => true, 'route' => shape(find_route($id))]);

    case 'DELETE':
        require_csrf();
        $id = to_int($_GET['id'] ?? null);
        if ($id === null) fail(400, 'Missing route id.');
        require_owner(find_route($id));
        $st = db()->prepare('DELETE FROM routes WHERE id = ? AND owner_uid = ?');
        $st->execute([$id, $_SESSION['uid']]);
        json_out(['ok' => true, 'deleted' => $id]);

    default:
        header('Allow: GET, POST, PUT, DELETE');
        fail(405, 'Method not allowed.');
}
