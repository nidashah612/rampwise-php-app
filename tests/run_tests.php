<?php
// Integration tests. Start the server first:  php -S localhost:8000 -t public
// Then run:  php tests/run_tests.php [http://localhost:8000]
// Requires the curl extension. Re-seed first for a clean run: php db/seed.php --reset
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');

/** A "browser": its own cookie jar (= its own session) and CSRF token. */
final class Client {
    public string $csrf = '';
    private string $jar;
    public function __construct(private string $base) {
        $this->jar = tempnam(sys_get_temp_dir(), 'rw');
        $page = $this->raw('GET', '/index.php');
        preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $m);
        $this->csrf = $m[1] ?? '';
    }
    private function raw(string $method, string $path, ?string $body = null, array $headers = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
        ]);
        $out = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'body' => $out];
    }
    public function json(string $method, string $path, ?array $body = null, bool $withCsrf = true): array {
        $h = ['Content-Type: application/json'];
        if ($withCsrf) $h[] = 'X-CSRF-Token: ' . $this->csrf;
        $r = $this->raw($method, $path, $body === null ? null : json_encode($body), $h);
        return ['code' => $r['code'], 'data' => json_decode($r['body'], true) ?? []];
    }
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . ($ok ? '' : "  -> $detail") . "\n";
}

$alice = new Client($base);
$bob   = new Client($base);
$valid = ['from_id' => 1, 'to_id' => 5, 'distance_m' => 220, 'steps' => 0, 'slope' => 'flat', 'has_lift' => false, 'covered' => true];

// 1. Missing input (no buildings chosen)
$r = $alice->json('GET', '/api/routes.php');
check('1. Search with no buildings -> 422 with field errors', $r['code'] === 422 && isset($r['data']['error']['fields']['from']), json_encode($r));

// 2. Invalid input: same building, distance out of range
$r = $alice->json('POST', '/api/routes.php', ['from_id' => 2, 'to_id' => 2, 'distance_m' => 5, 'slope' => 'flat']);
check('2. Invalid route (same building, distance 5) -> 422', $r['code'] === 422 && isset($r['data']['error']['fields']['distance_m']), json_encode($r));

// 3. SQL-injection style input is rejected, not executed
$r = $alice->json('GET', '/api/routes.php?from=' . rawurlencode('1 OR 1=1') . '&to=2');
check('3. Non-integer "1 OR 1=1" in query -> 422', $r['code'] === 422, json_encode($r));

// 4. Successful create then successful update
$r = $alice->json('POST', '/api/routes.php', $valid);
$id = $r['data']['route']['id'] ?? 0;
check('4a. Create route -> 201', $r['code'] === 201 && $id > 0, json_encode($r));
$r = $alice->json('PUT', "/api/routes.php?id=$id", ['distance_m' => 330] + $valid);
check('4b. Update own route -> 200 and new distance saved', $r['code'] === 200 && ($r['data']['route']['distance_m'] ?? 0) === 330, json_encode($r));

// 5. Unauthorized: another session cannot edit/delete it, nor a seeded route
$r = $bob->json('PUT', "/api/routes.php?id=$id", $valid);
check("5a. Other user edits Alice's route -> 403", $r['code'] === 403, json_encode($r));
$r = $bob->json('DELETE', "/api/routes.php?id=$id");
check("5b. Other user deletes Alice's route -> 403", $r['code'] === 403, json_encode($r));
$r = $alice->json('DELETE', '/api/routes.php?id=1');
check('5c. Deleting a seeded route -> 403', $r['code'] === 403, json_encode($r));

// 6. CSRF
$r = $alice->json('POST', '/api/routes.php', $valid, false);
check('6. Write without CSRF token -> 403', $r['code'] === 403, json_encode($r));

// 7. Report: too-short reason rejected; valid report accepted; only reporter may reopen
$r = $alice->json('POST', '/api/report.php', ['route_id' => $id, 'reason' => 'bad']);
check('7a. Report with short reason -> 422', $r['code'] === 422, json_encode($r));
$r = $alice->json('POST', '/api/report.php', ['route_id' => $id, 'reason' => '<script>alert(1)</script> broken lift']);
check('7b. Valid report -> 201', $r['code'] === 201, json_encode($r));
$r = $bob->json('DELETE', "/api/report.php?route_id=$id");
check('7c. Non-reporter reopens -> 403', $r['code'] === 403, json_encode($r));

// 8. Sorting: valid option works, unknown option is rejected (never reaches SQL)
$r = $alice->json('GET', '/api/routes.php?from=1&to=2&sort=access');
check('8a. Sort by accessibility -> 200', $r['code'] === 200 && ($r['data']['routes'][0]['steps'] ?? 1) === 0, json_encode($r));
$r = $alice->json('GET', '/api/routes.php?from=1&to=2&sort=' . rawurlencode('id; DROP TABLE routes'));
check('8b. Unknown sort value -> 422', $r['code'] === 422, json_encode($r));

// 9. Cleanup: owner deletes own route
$r = $alice->json('DELETE', "/api/routes.php?id=$id");
check('9. Owner deletes own route -> 200', $r['code'] === 200, json_encode($r));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
