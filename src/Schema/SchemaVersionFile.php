<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

/**
 * One schema version: `V<nnn>_<name>.json` — the changes `call db snapshot` recorded —
 * and, when the version has code, `custom/V<nnn>_<name>.php`: the generated file and the
 * written one apart.
 *
 * ```json
 * {"version": "V007", "name": "add_order_status", "driver": "pgsql", "code": true,
 *  "changes": [{"op": "addColumn", "table": "orders", "column": {…}}]}
 * ```
 *
 * A version is a number, three digits at least (`V001`): the files sort in a file manager as
 * they apply, up to V999; past it the kernel still orders by the number.
 *
 * The checksum covers both files: a version changed after a database applied it is told
 * apart from the one it applied.
 */
final readonly class SchemaVersionFile
{
    public const string VERSION_PATTERN = '/^V(?:\d{3}|[1-9]\d{3,})$/';
    /** The folder of a version's code, inside the version's folder. */
    public const string CODE_DIR = 'custom';
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_]*$/';

    /**
     * @param list<array> $changes
     */
    public function __construct(
        public string $version,
        public string $name,
        public string $driver,
        public array $changes,
        public string $jsonPath,
        public ?string $phpPath,
        public string $checksum,
        public string $json,
    ) {
    }

    /** @throws SchemaException A file that is not a schema version, or one that contradicts its name. */
    public static function read(string $jsonPath): self
    {
        $base = basename($jsonPath, '.json');
        $json = (string) file_get_contents($jsonPath);
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SchemaException("{$base}.json is not valid JSON: {$e->getMessage()}", previous: $e);
        }
        foreach (['version', 'name', 'driver', 'changes'] as $key) {
            if (!isset($data[$key])) {
                throw new SchemaException("{$base}.json has no '{$key}'.");
            }
        }
        if (!preg_match(self::VERSION_PATTERN, $data['version'])) {
            throw new SchemaException("{$base}.json: '{$data['version']}' is no version (V001, V002…).");
        }
        if ($base !== $data['version'] . '_' . $data['name']) {
            throw new SchemaException(
                "{$base}.json says it is {$data['version']}_{$data['name']}: the file name and its content differ."
            );
        }
        $phpPath = dirname($jsonPath) . '/' . self::CODE_DIR . '/' . $base . '.php';
        $hasPhp = is_file($phpPath);
        $code = (bool) ($data['code'] ?? false);
        if ($code && !$hasPhp) {
            throw new SchemaException(
                "{$base} has code (\"code\": true), but " . self::CODE_DIR . "/{$base}.php is missing."
            );
        }
        if (!$code && $hasPhp) {
            throw new SchemaException(
                self::CODE_DIR . "/{$base}.php is there, but {$base}.json says the version has no code."
            );
        }
        $php = $hasPhp ? (string) file_get_contents($phpPath) : '';

        return new self(
            version: $data['version'],
            name: $data['name'],
            driver: $data['driver'],
            changes: array_values($data['changes']),
            jsonPath: $jsonPath,
            phpPath: $hasPhp ? $phpPath : null,
            checksum: hash('sha256', $json . "\0" . $php),
            json: $json,
        );
    }

    /** The number of a version: `V007` → 7. */
    public static function number(string $version): int
    {
        return (int) substr($version, 1);
    }

    /** `V007` for 7. */
    public static function versionOf(int $number): string
    {
        return sprintf('V%03d', $number);
    }

    public function hasCode(): bool
    {
        return $this->phpPath !== null;
    }

    /** The changes the kernel makes itself — those marked `"by": "code"` are left to the code. */
    public function kernelChanges(): array
    {
        return array_values(array_filter($this->changes, fn(array $c) => ($c['by'] ?? null) !== 'code'));
    }

    public function label(): string
    {
        return $this->version . '_' . $this->name;
    }

    /**
     * The version's code, loaded on demand: the class the `.php` declares with
     * `#[SchemaVersion]` of this version.
     *
     * A process declares a class once. The same code met again — in another folder (a new
     * release beside the old one, a history copied), or loaded before by an autoloader —
     * is the class already there; other code under a name already taken is said, instead
     * of PHP's fatal "cannot redeclare".
     *
     * @throws SchemaException No such class there, one that is no {@see VersionCode}, or a
     *     class of that name loaded already with other code.
     */
    public function code(): ?VersionCode
    {
        if ($this->phpPath === null) {
            return null;
        }
        static $loaded = [];
        $real = (string) realpath($this->phpPath);
        $source = (string) file_get_contents($real);
        $hash = hash('sha256', $source);
        $declared = self::declaredClass($source);
        if ($declared !== null && class_exists($declared, false)) {
            $ref = new \ReflectionClass($declared);
            $from = (string) $ref->getFileName();
            if ($from !== $real && (!is_file($from) || hash_file('sha256', $from) !== $hash)) {
                throw new SchemaException(
                    "{$declared} (" . basename($this->phpPath) . ") is loaded already, from {$from}, with other "
                    . 'code: a process declares a class once — run the migration in a new process.'
                );
            }
            if ($from === $real && ($loaded[$real] ?? $hash) !== $hash) {
                throw new SchemaException(
                    basename($this->phpPath) . " changed since {$declared} was loaded: run the migration in a new "
                    . 'process.'
                );
            }
            return $this->instance($ref);
        }
        $known = get_declared_classes();
        include $real;
        $loaded[$real] = $hash;
        foreach (array_reverse(array_diff(get_declared_classes(), $known)) as $class) {
            $ref = new \ReflectionClass($class);
            $attr = $ref->getAttributes(SchemaVersion::class)[0] ?? null;
            if ($attr !== null && $attr->newInstance()->version === $this->version) {
                return $this->instance($ref);
            }
        }
        throw new SchemaException(
            basename($this->phpPath) . " declares no class with #[SchemaVersion('{$this->version}')]."
        );
    }

    private function instance(\ReflectionClass $ref): VersionCode
    {
        $attr = $ref->getAttributes(SchemaVersion::class)[0] ?? null;
        if ($attr === null || $attr->newInstance()->version !== $this->version) {
            throw new SchemaException(
                basename((string) $this->phpPath) . " declares no class with #[SchemaVersion('{$this->version}')]."
            );
        }
        if (!$ref->isSubclassOf(VersionCode::class)) {
            throw new SchemaException(
                "{$ref->getName()} (" . basename((string) $this->phpPath) . ') must extend ' . VersionCode::class . '.'
            );
        }
        return $ref->newInstance();
    }

    /** The class a version's file declares — namespace and name read from its source. */
    private static function declaredClass(string $source): ?string
    {
        if (!preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $c)) {
            return null;
        }
        $ns = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $n) ? $n[1] . '\\' : '';
        return $ns . $c[1];
    }
}
