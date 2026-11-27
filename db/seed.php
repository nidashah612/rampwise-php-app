<?php
// Creates the schema and inserts the Assignment 1 sample data.
// Usage:  php db/seed.php           (create if missing, seed if empty)
//         php db/seed.php --reset   (delete the database and start again)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$file = __DIR__ . '/rampwise.sqlite';
if (in_array('--reset', $argv, true) && is_file($file)) unlink($file);

$pdo = new PDO('sqlite:' . $file, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

if ((int)$pdo->query('SELECT COUNT(*) FROM buildings')->fetchColumn() > 0) {
    echo "Database already seeded. Use --reset to start again.\n";
    exit(0);
}

// ASSUMPTION: replace these six names/routes with the ones from your Assignment 1 data.js
$buildings = ['Library', 'Science Block', 'Engineering', 'Admin Block', 'Cafeteria', 'Sports Hall'];
$ins = $pdo->prepare('INSERT INTO buildings (name) VALUES (?)');
foreach ($buildings as $b) $ins->execute([$b]);

// from, to, distance_m, steps, slope, has_lift, covered, status, closed_reason
$routes = [
    [1, 2, 180,  0, 'flat',   0, 1, 'open',   null],
    [1, 2, 120, 14, 'gentle', 0, 0, 'open',   null],
    [2, 3, 250,  0, 'gentle', 0, 0, 'closed', 'Ramp under repair'],
    [1, 3, 300,  0, 'flat',   0, 1, 'open',   null],
    [1, 4,  90,  6, 'flat',   1, 0, 'open',   null],
    [4, 5, 140,  0, 'flat',   0, 1, 'open',   null],
    [5, 6, 320,  0, 'gentle', 0, 0, 'open',   null],
    [3, 6, 410, 12, 'steep',  0, 0, 'open',   null],
    [2, 5, 200,  0, 'flat',   0, 1, 'open',   null],
    [4, 3, 160,  4, 'gentle', 0, 0, 'open',   null],
];
$ins = $pdo->prepare(
    'INSERT INTO routes (from_id,to_id,distance_m,steps,slope,has_lift,covered,status,closed_reason)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
foreach ($routes as $r) $ins->execute($r);

echo "Seeded " . count($buildings) . " buildings and " . count($routes) . " routes.\n";
