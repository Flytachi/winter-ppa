<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * The application's entities at one moment of its life — as data, so that each next state
 * is a small change of the one before, the way a developer edits them. {@see code()} turns
 * it into real PHP: a config, an entity and a repository per table, with real attributes,
 * mapped by {@see \Flytachi\Winter\Ppa\PPAMapping} as an application's are.
 *
 * A table: `class`, `repo`, `schema` (null: the config's), `attrs` (on the class), `props`:
 * name => [list of attributes, declaration] — `['#[Varchar(32)]'], '?string $phone = null'`.
 */
final readonly class State
{
    /**
     * @param array<string, array{class: string, repo: string, schema: ?string, attrs: list<string>,
     *     props: array<string, array{0: list<string>, 1: string}>}> $tables
     * @param array<string, string> $extensions The config's #[Extension]s: name => the attribute's arguments.
     */
    public function __construct(public array $tables, public array $extensions)
    {
    }

    /** A property added at the end of its entity, or after `$after`; replaced where it is when there. */
    public function with(string $table, string $prop, array $attrs, string $decl, ?string $after = null): self
    {
        $props = $this->tables[$table]['props'];
        if (isset($props[$prop]) || $after === null) {
            $props[$prop] = [$attrs, $decl];
        } else {
            $out = [];
            foreach ($props as $name => $p) {
                $out[$name] = $p;
                if ($name === $after) {
                    $out[$prop] = [$attrs, $decl];
                }
            }
            $props = $out;
        }
        return $this->table($table, ['props' => $props]);
    }

    public function without(string $table, string $prop): self
    {
        $props = $this->tables[$table]['props'];
        unset($props[$prop]);
        return $this->table($table, ['props' => $props]);
    }

    /** A property renamed in its place — with #[RenamedFrom] added by the step that needs it. */
    public function renamed(string $table, string $from, string $to, array $attrs, string $decl): self
    {
        $out = [];
        foreach ($this->tables[$table]['props'] as $name => $p) {
            $name === $from ? $out[$to] = [$attrs, $decl] : $out[$name] = $p;
        }
        return $this->table($table, ['props' => $out]);
    }

    /** The attributes of a property, changed by `$change`. */
    public function attrs(string $table, string $prop, callable $change): self
    {
        [$attrs, $decl] = $this->tables[$table]['props'][$prop];
        return $this->with($table, $prop, array_values($change($attrs)), $decl);
    }

    public function decl(string $table, string $prop, string $decl): self
    {
        return $this->with($table, $prop, $this->tables[$table]['props'][$prop][0], $decl);
    }

    public function table(string $table, array $def): self
    {
        $tables = $this->tables;
        $tables[$table] = $def + ($tables[$table] ?? ['attrs' => [], 'schema' => null, 'props' => []]);
        return new self($tables, $this->extensions);
    }

    /**
     * The table under another name — and in another schema when given — its class and
     * repository as they are; `$attr` (a #[RenamedFrom]) put on the class.
     */
    public function retable(string $from, string $to, ?string $schema, ?string $attr): self
    {
        $tables = [];
        foreach ($this->tables as $name => $t) {
            if ($name === $from) {
                $t['schema'] = $schema;
                $t['attrs'] = $attr === null ? $t['attrs'] : [...$t['attrs'], $attr];
                $tables[$to] = $t;
            } else {
                $tables[$name] = $t;
            }
        }
        return new self($tables, $this->extensions);
    }

    public function withoutTable(string $table): self
    {
        $tables = $this->tables;
        unset($tables[$table]);
        return new self($tables, $this->extensions);
    }

    public function withExtension(string $name, ?string $args = null): self
    {
        return new self($this->tables, [...$this->extensions, $name => $args ?? "'{$name}'"]);
    }

    public function withoutExtension(string $name): self
    {
        $ext = $this->extensions;
        unset($ext[$name]);
        return new self($this->tables, $ext);
    }

    /** Every attribute the state writes, as written — what the coverage check reads. */
    public function attributeUsages(): array
    {
        $out = ['#[Table]', '#[Migratable(priority: MigratablePriority::High)]'];
        foreach ($this->extensions as $args) {
            $out[] = "#[Extension({$args})]";
        }
        foreach ($this->tables as $t) {
            array_push($out, ...$t['attrs']);
            foreach ($t['props'] as [$attrs]) {
                array_push($out, ...$attrs);
            }
        }
        return array_values(array_unique($out));
    }

    /** The state as PHP, in a namespace of its own: a process cannot declare a class twice. */
    public function code(string $namespace): string
    {
        $ext = implode("\n", array_map(fn($args) => "#[Extension({$args})]", $this->extensions));
        $out = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Flytachi\\Winter\\Cdo\\Config\\PgDbConfig;
            use Flytachi\\Winter\\Ppa\\E2e\\{AuditLevel, UserStatus, UserStatusV2};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Additive\\{DefaultVal, NullableIs};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Config\\{Extension, Migratable};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Constraint\\{Check, CheckEnum, ForeignKey, ForeignRepo};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Entity\\Table;
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Hybrid\\{BigId, Id, SmallId, UuidPk};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Idx\\{Index, Primary, Unique};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Migration\\RenamedFrom;
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Primal\\{BigInteger, Binary, Blob, Boolean, Char, Date, DateTime,
                Decimal, Double, FloatType, Integer, Json, SmallInteger, Text, Time, Timestamp, Type, Uuid, Varchar};
            use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Sub\\AutoIncrement;
            use Flytachi\\Winter\\Ppa\\Mapping\\Constants\\{FKAction, IndexMethod, MigratablePriority};
            use Flytachi\\Winter\\Ppa\\Stereotype\\Repository;

            #[Migratable(priority: MigratablePriority::High)]
            {$ext}
            final class PgDb extends PgDbConfig
            {
                public function setUp(): void
                {
                    [\$this->host, \$port, \$this->database, \$this->username, \$this->password]
                        = explode(':', (string) getenv('E2E_PG'));
                    \$this->port = (int) \$port;
                }
            }


            PHP;
        foreach ($this->tables as $name => $t) {
            $out .= "#[Table]\n" . implode('', array_map(fn($a) => "{$a}\n", $t['attrs']));
            $out .= "class {$t['class']}\n{\n";
            foreach ($t['props'] as [$attrs, $decl]) {
                foreach ($attrs as $a) {
                    $out .= "    {$a}\n";
                }
                $out .= "    public {$decl};\n\n";
            }
            $out = rtrim($out) . "\n}\n\n";
            $schema = $t['schema'] !== null ? "\n    protected ?string \$schema = '{$t['schema']}';" : '';
            $out .= "class {$t['repo']} extends Repository\n{\n"
                . "    public static string \$table = '{$name}';{$schema}\n"
                . "    protected string \$entityClassName = {$t['class']}::class;\n"
                . "    protected string \$dbConfigClassName = PgDb::class;\n}\n\n";
        }
        return $out;
    }
}
