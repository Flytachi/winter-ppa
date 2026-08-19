<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table as EntityTable;
use Flytachi\Winter\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;

/**
 * Turns classes into database structure: configs into instances, repositories into a
 * {@see Declaration} of tables ready to be compared with a live schema.
 *
 * It is given the classes rather than asked to find them. Discovering "every repository
 * in the project" needs a notion of what the project is — a root directory, an autoload
 * map, a scanner — and that belongs to a framework, not to a persistence library. The
 * Winter kernel keeps a thin `PPAMapping` of its own that scans and then calls in here;
 * anything else can pass the reflections it already has.
 *
 * @link https://winterframe.net/docs/ppa PHP Persistence API
 */
final class PPAMapping
{
    /**
     * Instantiates database configs.
     *
     * A class that cannot be instantiated is skipped rather than fatal: the input is
     * usually the result of a scan, and a scan sees abstract bases and half-written
     * classes too.
     *
     * @param iterable<ReflectionClass> $reflections Classes implementing {@see DbConfigInterface}.
     * @return DbConfigInterface[]
     */
    public static function configsFrom(iterable $reflections): array
    {
        $configs = [];
        foreach ($reflections as $ref) {
            try {
                $configs[] = $ref->newInstance();
            } catch (ReflectionException) {
            }
        }

        return $configs;
    }

    /**
     * Builds the declaration — every table the given repositories describe, grouped by
     * the config that owns it.
     *
     * A repository takes part only when its entity carries {@see EntityTable}; the rest
     * are silently passed over, which is what makes it safe to hand this the whole
     * result of a scan.
     *
     * @param iterable<ReflectionClass> $reflections Classes implementing {@see RepositoryInterface}.
     */
    public static function declarationFrom(iterable $reflections): Declaration
    {
        $declaration = new Declaration();

        foreach ($reflections as $reflectionClass) {
            try {
                /** @var RepositoryInterface $repository */
                $repository = $reflectionClass->newInstance();
                /** @var DbConfigInterface $config */
                $config = new ReflectionClass($repository->getDbConfigClassName())->newInstance();
                $config->setUp();

                $reflectionClassEntity = new ReflectionClass($repository->getEntityClassName());
                $columnMap = new ColumnMapping($config->getDriver());

                $annotationClassEntity = $reflectionClassEntity
                    ->getAttributes(EntityTable::class, ReflectionAttribute::IS_INSTANCEOF);
                if (empty($annotationClassEntity)) {
                    continue;
                }

                foreach ($reflectionClassEntity->getProperties() as $property) {
                    $columnMap->push($property);
                }
                $declaration->push($config, new Table(
                    name: $repository::$table,
                    columns: $columnMap->getColumns(),
                    schema: $repository->getSchema(),
                ));
            } catch (ReflectionException) {
            }
        }

        return $declaration;
    }
}
