<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

check(password_issue('corta') !== null, 'password troppo corta viene rifiutata', $failures);
check(password_issue('password123') !== null, 'password comune viene rifiutata', $failures);
check(password_issue('1234567890') !== null, 'password numerica viene rifiutata', $failures);
check(password_issue('Cavallo-Blu-Marino-42') === null, 'password robusta viene accettata', $failures);
check(password_issue(str_repeat('a', 9)) !== null, 'password di 9 caratteri (sotto soglia) viene rifiutata', $failures);
check(password_issue(str_repeat('a', 10)) === null, 'password di esattamente 10 caratteri non banali viene accettata', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
