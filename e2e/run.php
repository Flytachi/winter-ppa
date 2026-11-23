<?php

// The life of an application, end to end, on a live database — see e2e/README.md.
//
//   E2E_PG=127.0.0.1:5432:e2e:postgres:test php e2e/run.php
//   E2E_MARIA=127.0.0.1:3306:e2e:root:test php e2e/run.php
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

// The database: one of these set, host:port:database:user:password.
$drivers = [
    'E2E_PG' => Flytachi\Winter\Ppa\E2e\PgDriver::class,
    'E2E_MARIA' => Flytachi\Winter\Ppa\E2e\MariaDriver::class,
];
$given = array_keys(array_filter(array_map(static fn(string $env) => getenv($env) !== false, array_combine(array_keys($drivers), array_keys($drivers)))));
if (count($given) !== 1) {
    fwrite(STDERR, 'Set one of ' . implode(', ', array_keys($drivers)) . "=host:port:database:user:password.\n");
    exit(2);
}
$stop = getenv('E2E_STOP');
$stop = $stop === '' ? false : $stop;       // a workflow passes an empty input as ''
if ($stop !== false && (!ctype_digit($stop) || (int) $stop < 1 || (int) $stop > 17)) {
    fwrite(STDERR, "E2E_STOP must be a snapshot of stage 3: 1–17.\n");
    exit(2);
}

exit(new Flytachi\Winter\Ppa\E2e\Runner(new $drivers[$given[0]](), $stop === false ? null : (int) $stop)->run());
