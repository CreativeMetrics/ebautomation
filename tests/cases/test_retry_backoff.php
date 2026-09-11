<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

// Server locale che simula un endpoint API: risponde diversamente in base
// al numero di volte che è stato chiamato sullo stesso path.
file_put_contents(__DIR__ . '/mock_server.php', <<<'PHP'
<?php
$counter_file = __DIR__ . '/hit_counter.json';
$hits = file_exists($counter_file) ? json_decode(file_get_contents($counter_file), true) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$hits[$path] = ($hits[$path] ?? 0) + 1;
file_put_contents($counter_file, json_encode($hits));
if ($path === '/flaky-then-ok') {
    if ($hits[$path] <= 1) { http_response_code(500); echo json_encode(['error' => 'temporary']); exit; }
    http_response_code(200); echo json_encode(['ok' => true]); exit;
}
if ($path === '/always-400') { http_response_code(400); echo json_encode(['error' => 'bad request']); exit; }
if ($path === '/always-500') { http_response_code(500); echo json_encode(['error' => 'server error']); exit; }
http_response_code(404);
PHP);

$port = 8700 + random_int(0, 900); // porta pseudo-casuale per non collidere tra test in parallelo
$cmd  = sprintf('php -S 127.0.0.1:%d %s > %s/server.log 2>&1 & echo $!', $port, escapeshellarg(__DIR__ . '/mock_server.php'), escapeshellarg(__DIR__));
$pid  = trim((string)shell_exec($cmd));
usleep(600000); // attende l'avvio del server

try {
    $res1 = api_call_with_retry("http://127.0.0.1:$port/flaky-then-ok", [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    check($res1['status'] === 200, 'flaky-then-ok: status finale 200', $failures);
    check($res1['attempts'] === 2, 'flaky-then-ok: risolto al secondo tentativo (con retry)', $failures);

    $res2 = api_call_with_retry("http://127.0.0.1:$port/always-400", [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    check($res2['attempts'] === 1, 'always-400: nessun retry su errore non transitorio', $failures);

    $t0 = microtime(true);
    $res3 = api_call_with_retry("http://127.0.0.1:$port/always-500", [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $elapsed = microtime(true) - $t0;
    check($res3['attempts'] === 3, 'always-500: esaurisce tutti e 3 i tentativi', $failures);
    check($elapsed >= 1.0 && $elapsed < 5.0, "always-500: il backoff cumulativo è nell'ordine di ~1.2s (osservato: " . round($elapsed, 2) . "s)", $failures);
} finally {
    if ($pid) @exec("kill $pid 2>/dev/null");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
