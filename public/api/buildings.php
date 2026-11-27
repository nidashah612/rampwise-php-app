<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, 'Method not allowed.');
$rows = db()->query('SELECT id, name FROM buildings ORDER BY name')->fetchAll();
json_out(['ok' => true, 'buildings' => $rows]);
