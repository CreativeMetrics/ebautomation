<?php
/**
 * Suite di test leggera per ebautomation — nessuna dipendenza esterna
 * (niente Composer/PHPUnit), coerente con lo zero-dependency del progetto.
 *
 * Ogni file in tests/cases/*.php gira come processo PHP separato dentro una
 * copia temporanea e isolata di functions.php (+ PHPMailer), con un proprio
 * database.sqlite: nessun test tocca i dati reali né condivide stato con gli
 * altri. Ogni caso stampa eventuali fallimenti su stderr ed esce con
 * exit(0) se tutte le sue verifiche interne passano, exit(1) altrimenti.
 *
 * Uso:
 *   php tests/run.php
 *
 * Uscita 0 se tutti i test passano, 1 altrimenti — pensata per la CI
 * (vedi .github/workflows/ci.yml).
 */

$app_dir   = dirname(__DIR__) . '/ebautomation';
$cases_dir = __DIR__ . '/cases';
$cases     = glob("$cases_dir/*.php") ?: [];
sort($cases);

if (!is_dir($app_dir) || !file_exists("$app_dir/functions.php")) {
    fwrite(STDERR, "Impossibile trovare $app_dir/functions.php\n");
    exit(1);
}
if (empty($cases)) {
    fwrite(STDERR, "Nessun test trovato in $cases_dir\n");
    exit(1);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = "$dir/$item";
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

$failed = 0;
$t0_total = microtime(true);

foreach ($cases as $case) {
    $name = basename($case, '.php');
    $tmp  = sys_get_temp_dir() . '/ebauto_test_' . bin2hex(random_bytes(6));
    mkdir($tmp, 0755, true);
    copy("$app_dir/functions.php", "$tmp/functions.php");
    if (is_dir("$app_dir/PHPMailer")) {
        mkdir("$tmp/PHPMailer", 0755, true);
        foreach (glob("$app_dir/PHPMailer/*.php") ?: [] as $f) {
            copy($f, "$tmp/PHPMailer/" . basename($f));
        }
    }
    copy($case, "$tmp/case.php");

    $t0 = microtime(true);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', 'case.php'], $descriptors, $pipes, $tmp);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($proc);
    $elapsed = round(microtime(true) - $t0, 2);

    rrmdir($tmp);

    if ($exit_code === 0) {
        echo "  ok    $name (${elapsed}s)\n";
    } else {
        echo "  FAIL  $name (${elapsed}s)\n";
        $out = trim($stdout . $stderr);
        if ($out !== '') {
            foreach (explode("\n", $out) as $line) echo "        $line\n";
        }
        $failed++;
    }
}

$total_elapsed = round(microtime(true) - $t0_total, 2);
echo "\n" . (count($cases) - $failed) . "/" . count($cases) . " test superati in {$total_elapsed}s.\n";
exit($failed > 0 ? 1 : 0);
