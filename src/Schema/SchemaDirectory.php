<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

/**
 * The history of one database: the folder of its versions, `ppa/<Config>/`, read in the
 * order of their numbers. The code of a version is in its `custom/`.
 */
final readonly class SchemaDirectory
{
    public function __construct(public string $path)
    {
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }

    /**
     * @return list<SchemaVersionFile>
     * @throws SchemaException A file that is not a version, two files of one version, or a
     *     version of another driver.
     */
    public function versions(?string $driver = null): array
    {
        if (!$this->exists()) {
            return [];
        }
        // Every JSON of the folder: one that is not a version is said, not passed over.
        $files = glob($this->path . '/*.json') ?: [];
        $versions = [];
        $seen = [];
        foreach ($files as $file) {
            $v = SchemaVersionFile::read($file);
            if (isset($seen[$v->version])) {
                throw new SchemaException(
                    "Version {$v->version} is there twice: {$seen[$v->version]} and " . basename($file) . '.'
                );
            }
            if ($driver !== null && $v->driver !== $driver) {
                throw new SchemaException(
                    "{$v->label()} was recorded for {$v->driver}, the database is {$driver}: "
                    . 'a snapshot belongs to its driver.'
                );
            }
            $seen[$v->version] = basename($file);
            $versions[] = $v;
        }
        usort(
            $versions,
            fn(SchemaVersionFile $a, SchemaVersionFile $b)
                => SchemaVersionFile::number($a->version) <=> SchemaVersionFile::number($b->version),
        );
        $code = SchemaVersionFile::CODE_DIR;
        foreach (glob($this->path . '/*.php') ?: [] as $php) {
            throw new SchemaException(
                basename($php) . " is beside the versions: the code of a version is in {$code}/."
            );
        }
        foreach (glob("{$this->path}/{$code}/*.php") ?: [] as $php) {
            if (!is_file($this->path . '/' . basename($php, '.php') . '.json')) {
                throw new SchemaException("{$code}/" . basename($php) . ' has no version: ' . basename($php, '.php')
                    . '.json is not in the folder.');
            }
        }
        return $versions;
    }

    /** The next version: the last one's number + 1 — `V001` in an empty folder. */
    public function nextVersion(): string
    {
        $versions = $this->versions();
        $last = $versions === [] ? 0 : SchemaVersionFile::number(end($versions)->version);
        return SchemaVersionFile::versionOf($last + 1);
    }

    /**
     * Writes a version: its JSON, and the skeleton of its code when it has code.
     *
     * @param list<array> $changes
     * @return list<string> The files written.
     */
    public function write(string $version, string $name, string $driver, array $changes, ?string $code = null): array
    {
        if (!preg_match(SchemaVersionFile::NAME_PATTERN, $name)) {
            throw new SchemaException("'{$name}' cannot name a version: lower-case letters, digits and _ only.");
        }
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new SchemaException("Cannot create {$this->path}.");
        }
        $data = ['version' => $version, 'name' => $name, 'driver' => $driver];
        if ($code !== null) {
            $data['code'] = true;
        }
        $data['changes'] = $changes;
        $base = "{$this->path}/{$version}_{$name}";
        file_put_contents(
            "{$base}.json",
            json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
        $files = ["{$base}.json"];
        if ($code !== null) {
            $dir = "{$this->path}/" . SchemaVersionFile::CODE_DIR;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new SchemaException("Cannot create {$dir}.");
            }
            file_put_contents("{$dir}/{$version}_{$name}.php", $code);
            $files[] = "{$dir}/{$version}_{$name}.php";
        }
        return $files;
    }
}
