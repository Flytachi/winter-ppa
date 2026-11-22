<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use PDO;

/**
 * `winter_ppa_schema_history` — which versions this database has, in the database itself:
 * it travels with a backup and a dump, and on PostgreSQL and SQLite a version's row is
 * written in the version's own transaction, so the two never disagree.
 *
 * One row per version of one owner — the config whose history it is (its class name): two
 * configs, or an application and a package, on one database keep their histories apart, each
 * with its own V001. A row holds the version's name, the checksum of its files, whether it had code, its state,
 * when, how long, by which database user, and a copy of the JSON it applied — for reading
 * only: the files stay what versions are applied from.
 *
 * States: `applied` — run; `created` — part of a fresh database made from the model at once;
 * `baseline` — found in a database made before there was a history; `running` / `failed` —
 * a version that started and did not end (MySQL, MariaDB: their schema changes are not
 * rolled back), run again from its start next time.
 *
 * In the config's schema on PostgreSQL (`getSchema()`), else in the default one.
 */
final class SchemaHistory
{
    public const string TABLE = 'winter_ppa_schema_history';

    private bool $locked = false;

    public function __construct(
        private readonly PDO $db,
        private readonly string $driver,
        private readonly string $owner,
        private readonly ?string $schema = null,
    ) {
    }

    public function name(): string
    {
        return $this->driver === 'pgsql' && $this->schema !== null && $this->schema !== ''
            ? $this->schema . '.' . self::TABLE
            : self::TABLE;
    }

    public function exists(): bool
    {
        return new SchemaInspector($this->db, $this->driver)->hasTable($this->name());
    }

    /** Creates the table when it is not there — without an error in the server's log. */
    public function ensure(): void
    {
        $text = $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->name() . " (
                owner VARCHAR(190) NOT NULL,
                version VARCHAR(40) NOT NULL,
                name VARCHAR(200) NOT NULL,
                checksum CHAR(64) NOT NULL,
                code BOOLEAN NOT NULL DEFAULT FALSE,
                state VARCHAR(16) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ms INT NOT NULL DEFAULT 0,
                applied_by VARCHAR(100),
                changes {$text} NOT NULL,
                PRIMARY KEY (owner, version)
            )"
        );
    }

    /**
     * @return array<string, array{version: string, name: string, checksum: string, code: bool,
     *     state: string, applied_at: string, ms: int, applied_by: ?string}> By version, in order.
     */
    public function rows(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $rows = [];
        $st = $this->db->prepare(
            'SELECT version, name, checksum, code, state, applied_at, ms, applied_by FROM ' . $this->name()
            . ' WHERE owner = ?'
        );
        $st->execute([$this->owner]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $r['code'] = (bool) $r['code'];
            $r['ms'] = (int) $r['ms'];
            $rows[$r['version']] = $r;
        }
        // By number, not by text: V1000 comes after V999.
        uksort($rows, fn(string $a, string $b) => SchemaVersionFile::number($a) <=> SchemaVersionFile::number($b));
        return $rows;
    }

    /** The JSON a version applied, as the database keeps it. */
    public function changesOf(string $version): ?string
    {
        if (!$this->exists()) {
            return null;
        }
        $st = $this->db->prepare('SELECT changes FROM ' . $this->name() . ' WHERE owner = ? AND version = ?');
        $st->execute([$this->owner, $version]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function record(SchemaVersionFile $v, string $state, int $ms = 0): void
    {
        $user = match ($this->driver) {
            'pgsql' => 'current_user',
            'mysql' => 'CURRENT_USER()',
            default => 'NULL',
        };
        $this->db->prepare('DELETE FROM ' . $this->name() . ' WHERE owner = ? AND version = ?')
            ->execute([$this->owner, $v->version]);
        $this->db->prepare(
            'INSERT INTO ' . $this->name()
            . ' (owner, version, name, checksum, code, state, ms, applied_by, changes)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, {$user}, ?)"
        )->execute([$this->owner, $v->version, $v->name, $v->checksum, $v->hasCode() ? 1 : 0, $state, $ms, $v->json]);
    }

    /**
     * Holds the database's schema for this process: a second instance starting at the same
     * moment waits here, and then finds nothing left to do. PostgreSQL: an advisory lock;
     * MySQL, MariaDB: GET_LOCK; SQLite: none — its writer takes the file.
     *
     * @param callable(): void|null $waiting Told once when another instance holds it.
     * @throws SchemaException Not taken within `$timeout` seconds.
     */
    public function lock(int $timeout = 300, ?callable $waiting = null): void
    {
        if ($this->driver === 'pgsql') {
            $key = $this->lockKey();
            if (!(bool) $this->db->query("SELECT pg_try_advisory_lock({$key})")->fetchColumn()) {
                $waiting && $waiting();
                $this->db->exec("SET lock_timeout = '" . ($timeout * 1000) . "ms'");
                try {
                    $this->db->query("SELECT pg_advisory_lock({$key})")->fetchColumn();
                } catch (\PDOException $e) {
                    throw new SchemaException("The schema lock was not released within {$timeout} s.", previous: $e);
                } finally {
                    $this->db->exec('RESET lock_timeout');
                }
            }
        } elseif ($this->driver === 'mysql') {
            $name = $this->db->quote('winter_ppa_schema:' . $this->db->query('SELECT DATABASE()')->fetchColumn());
            if ((int) $this->db->query("SELECT GET_LOCK({$name}, 0)")->fetchColumn() !== 1) {
                $waiting && $waiting();
                if ((int) $this->db->query("SELECT GET_LOCK({$name}, {$timeout})")->fetchColumn() !== 1) {
                    throw new SchemaException("The schema lock was not released within {$timeout} s.");
                }
            }
        }
        $this->locked = true;
    }

    public function unlock(): void
    {
        if (!$this->locked) {
            return;
        }
        if ($this->driver === 'pgsql') {
            $this->db->query('SELECT pg_advisory_unlock(' . $this->lockKey() . ')')->fetchColumn();
        } elseif ($this->driver === 'mysql') {
            $name = $this->db->quote('winter_ppa_schema:' . $this->db->query('SELECT DATABASE()')->fetchColumn());
            $this->db->query("SELECT RELEASE_LOCK({$name})")->fetchColumn();
        }
        $this->locked = false;
    }

    private function lockKey(): int
    {
        return (int) hexdec(substr(hash('sha256', 'winter_ppa_schema:' . $this->name()), 0, 15));
    }
}
