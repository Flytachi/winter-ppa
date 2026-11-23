<?php

// `migrate` of one history in a process of its own — what a restarted application does: its
// version code loaded fresh, and a process that can be killed midway. Used by the run.
//
//   php e2e/migrate.php <driver class> <history dir> <owner>
//
// Prints {"done": […], "log": [[level, message], …]}; a failure on stderr, exit 1.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/src/Support.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Flytachi\\Winter\\Ppa\\E2e\\';
    if (str_starts_with($class, $prefix) && is_file($file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php')) {
        require $file;
    }
});

[, $driverClass, $path, $owner] = $argv;
/** @var Flytachi\Winter\Ppa\E2e\Driver $driver */
$driver = new $driverClass();
$log = new class extends Psr\Log\AbstractLogger {
    public array $lines = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
};
try {
    $done = new Flytachi\Winter\Ppa\Schema\SchemaMigrator(
        $driver->open(),
        $driver->name(),
        new Flytachi\Winter\Ppa\Schema\SchemaDirectory($path),
        $owner,
        $driver->historySchema(),
        $log,
        'E2E',
    )->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    echo json_encode(['done' => null, 'log' => $log->lines]);
    exit(1);
}
echo json_encode(['done' => $done, 'log' => $log->lines]);
