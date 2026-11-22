<?php

// The life of an application, end to end, on a live database — see e2e/README.md.
//
//   E2E_PG=127.0.0.1:5432:e2e:postgres:test php e2e/run.php
//   E2E_STOP=7   variant C stops at stage-3 #7 (5–9; random when not given)
//
// The database is emptied: give it one of its own.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/src/Support.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Flytachi\\Winter\\Ppa\\E2e\\';
    if (str_starts_with($class, $prefix) && is_file($file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

if (getenv('E2E_PG') === false) {
    fwrite(STDERR, "E2E_PG=host:port:database:user:password is not set.\n");
    exit(2);
}
$stop = getenv('E2E_STOP');
$stop = $stop === '' ? false : $stop;       // a workflow passes an empty input as ''
if ($stop !== false && (!ctype_digit($stop) || (int) $stop < 1 || (int) $stop > 17)) {
    fwrite(STDERR, "E2E_STOP must be a snapshot of stage 3: 1–17.\n");
    exit(2);
}

exit(new Flytachi\Winter\Ppa\E2e\Runner($stop === false ? null : (int) $stop)->run());
