<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema\Fixtures;

use Flytachi\Winter\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;
use ReflectionClass;

require_once __DIR__ . '/Entities.php';

final class SchemaFixtures
{
    /**
     * Maps entity classes to tables the way PPAMapping does for an application.
     *
     * @param array<string, class-string> $map table => entity class
     * @return list<Table>
     */
    public static function tables(string $driver, array $map, ?string $schema = null): array
    {
        $tables = [];
        foreach ($map as $name => $class) {
            $mapping = new ColumnMapping($driver);
            foreach (new ReflectionClass($class)->getProperties() as $p) {
                $mapping->push($p);
            }
            $t = new Table(name: $name, columns: $mapping->getColumns(), schema: $schema);
            $t->entity = $class;
            $tables[] = $t;
        }
        return $tables;
    }

    /** A scratch directory for a history; removed by {@see rm()}. */
    public static function dir(): string
    {
        $dir = sys_get_temp_dir() . '/ppa-schema-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    public static function rm(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
