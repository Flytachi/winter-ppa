<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Ppa\PPAMapping;
use Flytachi\Winter\Ppa\Schema\SchemaDiff;
use Flytachi\Winter\Ppa\Schema\SchemaDirectory;
use Flytachi\Winter\Ppa\Schema\SchemaInspector;
use Flytachi\Winter\Ppa\Schema\SchemaMigrator;
use Flytachi\Winter\Ppa\Schema\SchemaModel;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Flytachi\Winter\Ppa\Schema\SchemaVersionFile;
use Psr\Log\NullLogger;

/**
 * Runs the scenario on one database, the way an application lives:
 *
 * - A — one by one: a snapshot of each state, then `migrate`; the data of stage 2 after
 *   V005; every check after its version.
 * - B — all at once: an empty database, every version in one `migrate` (created at the
 *   last at once).
 * - C — a client that skipped updates: an empty database up to V005 at once, the data, then
 *   stage 3 up to a version picked at random (5–9th of it, E2E_STOP to pick it), then the
 *   rest — each run applying several versions, code and all.
 * - Errors — what must be refused, by `snapshot` and by `migrate`.
 *
 * A, B and C must end with the same schema, column for column.
 */
final class Runner
{
    public const string OWNER = 'Flytachi\\Winter\\Ppa\\E2e\\PgDb';
    private const string CODE_NAMESPACE = 'Flytachi\\Winter\\Ppa\\E2e\\Versions';

    private CDO $db;
    private string $tmp;
    /** @var list<Step> */
    private array $steps;
    private int $failures = 0;
    /** @var array<string, list<\ReflectionClass>> */
    private array $loaded = [];

    public function __construct(private readonly ?int $stop = null)
    {
        $this->steps = Scenario::steps();
        $this->tmp = sys_get_temp_dir() . '/ppa-e2e-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->tmp . '/states', 0777, true);
    }

    public function run(): int
    {
        $this->db = Db::open();
        $version = (string) $this->db->query('SHOW server_version')->fetchColumn();
        Out::title("PostgreSQL {$version}");
        $started = hrtime(true);
        try {
            $this->coverage();
            $a = $this->variant('A — one by one', fn() => $this->variantA());
            $b = $this->variant('B — every version at once on an empty database', fn() => $this->variantB());
            $k = $this->stop ?? random_int(5, 9);
            $c = $this->variant("C — up to stage-3 #{$k} (E2E_STOP={$k}), then the rest", fn() => $this->variantC($k));
            if ($a !== null && $b !== null && $c !== null) {
                Out::block('Same schema');
                $this->check('A = B', fn() => self::same($a, $b), stop: false);
                $this->check('A = C', fn() => self::same($a, $c), stop: false);
            }
            if ($a !== null) {
                $this->variant('Errors — what must be refused', fn() => $this->errors());
            }
        } finally {
            self::rm($this->tmp);
        }
        Out::result($this->failures, (hrtime(true) - $started) / 1e9);
        return $this->failures === 0 ? 0 : 1;
    }

    // ── Variants ────────────────────────────────────────────────────────────

    /** @return array<string, list<string>> The schema it ends with. */
    private function variantA(): array
    {
        Db::reset($this->db);
        $dir = $this->dir('history');
        $stage = 0;
        foreach ($this->steps as $i => $step) {
            if ($step->stage !== $stage) {
                $stage = $step->stage;
                Out::stage($stage);
            }
            $n = $i + 1;
            $this->check(sprintf('V%03d %s', $n, $step->name), function () use ($dir, $step, $n): string {
                $diff = $this->snapshot($dir, $step, $n, sprintf('S%03d', $n));
                $done = $this->migrator($dir)->migrate();
                expect(count($done) === 1, 'one version applied, ' . count($done) . ' were');
                $this->matchesModel($dir);
                ($step->check)?->__invoke($this->db, $n > Scenario::STAGE_1);
                if ($n > Scenario::STAGE_1) {
                    $this->counts($n);
                }
                return self::summary($diff) . ' · ' . $done[0]['action'];
            });
            if ($n === Scenario::STAGE_1) {
                Out::stage(2);
                $this->check('data', function (): string {
                    Data::fill($this->db);
                    $this->counts(Scenario::STAGE_1);
                    return array_sum(Data::counts(Scenario::STAGE_1)) + Data::GROUPS . ' rows';
                });
            }
        }
        $this->check('again: nothing to apply', fn() => expect($this->migrator($dir)->migrate() === [], 'a second run applies nothing'));
        return Db::dump($this->db);
    }

    private function variantB(): array
    {
        Db::reset($this->db);
        $dir = $this->copy(count($this->steps), 'b');
        $this->check(count($this->steps) . ' versions in one run', function () use ($dir): string {
            $done = $this->migrator($dir)->migrate();
            expect(count($done) === count($this->steps), count($done) . ' versions recorded');
            expect(array_unique(array_column($done, 'action')) === ['created'], 'all created at once');
            $this->matchesModel($dir);
            return 'created at V' . sprintf('%03d', count($done));
        });
        foreach ($this->steps as $i => $step) {
            if ($step->check !== null && $i === count($this->steps) - 1) {
                $this->check('the checks of the last version', fn() => ($step->check)($this->db, false));
            }
        }
        return Db::dump($this->db);
    }

    private function variantC(int $k): array
    {
        Db::reset($this->db);
        $stop = Scenario::STAGE_1 + $k;
        $runs = [[1, Scenario::STAGE_1], [Scenario::STAGE_1 + 1, $stop], [$stop + 1, count($this->steps)]];
        foreach ($runs as $r => [$from, $to]) {
            $dir = $this->copy($to, 'c');
            $this->check(sprintf('V%03d–V%03d in one run', $from, $to), function () use ($dir, $from, $to, $r): string {
                $done = $this->migrator($dir)->migrate();
                expect(count($done) === $to - $from + 1, count($done) . ' versions in this run');
                $this->matchesModel($dir);
                if ($r > 0) {
                    ($this->steps[$to - 1]->check)?->__invoke($this->db, true);
                    $this->counts($to);
                }
                return implode(' ', array_unique(array_column($done, 'action')));
            });
            if ($r === 0) {
                $this->check('data', function (): string {
                    Data::fill($this->db);
                    return 'on V' . sprintf('%03d', Scenario::STAGE_1);
                });
            }
        }
        return Db::dump($this->db);
    }

    private function errors(): void
    {
        $full = count($this->steps);
        $last = $this->steps[$full - 1]->state;

        Out::stage('snapshot');
        $refused = [
            ['a column gone, no --drop', $last->without('users', 'locale'), '/users\.locale is gone from the entity/'],
            ['a table gone, no --drop', $last->withoutTable('audit_events'), '/audit_events has no entity any more/'],
            ['a table renamed without #[RenamedFrom]', $last->retable('labels', 'tags', null, null), '/public\.labels has no entity any more/'],
            ['a key on the old name of a renamed table', $last->retable('labels', 'tags', null, "#[RenamedFrom('labels')]")->with('payments', 'label_id', ['#[SmallInteger]', "#[ForeignKey('labels', 'id')]"], '?int $label_id = null'), '/the foreign key fk_payments_label_id points at public\.labels, renamed to public\.tags/'],
            ['#[RenamedFrom] of no table', $last->table('labels', ['attrs' => ["#[RenamedFrom('nope')]"]]), '/`public\.nope` is not a table of the last snapshot/'],
            ['#[RenamedFrom] of a table declared too', $last->table('labels', ['attrs' => ["#[RenamedFrom('users')]"]]), '/`public\.users` is a table of the entities too/'],
            ['a rename without #[RenamedFrom]', $last->renamed('users', 'email', 'mail', ['#[Varchar(160)]', '#[Unique]'], "string \$mail = ''"), '/users\.email is gone from the entity/'],
            ['#[RenamedFrom] of no column', $last->with('users', 'nick', ['#[Varchar(20)]', "#[RenamedFrom('nope')]"], '?string $nick = null'), '/`nope` is not a column of the last snapshot/'],
            ['NOT NULL without a default', $last->with('users', 'code', ['#[Varchar(8)]'], 'string $code'), '/users\.code is NOT NULL with no default/'],
            ['a narrowing, not custom', $last->attrs('users', 'name', fn($a) => Scenario::swap($a, '#[Varchar(200)]', '#[Varchar(50)]')), '/users\.name/'],
            ['a primary key changed, not custom', $last->attrs('audit_events', 'happened', fn($a) => Scenario::swap($a, '#[Primary]', null)), '/primary key changes/'],
            ['a unique HASH index', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Unique(method: IndexMethod::HASH)]']), '/a unique index is a B-tree/'],
            ['an extension dropped without --drop', $last->withoutExtension('btree_gist')->attrs('audit_events', 'level', fn($a) => Scenario::swap($a, '#[Index(method: IndexMethod::GIST)]', null)), null],
            ['GIN on a VARCHAR', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Index(method: IndexMethod::GIN)]']), '/no GIN operator class for locale/'],
            ['HASH of two columns', $last->attrs('users', 'locale', fn($a) => [...$a, "#[Index(['email'], method: IndexMethod::HASH)]"]), '/a HASH index has one column/'],
            ['trigrams without pg_trgm', $last->withoutExtension('pg_trgm'), '/comes with the extension pg_trgm/'],
            ['two indexes of one name', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Index]', '#[Index]']), '/two indexes are named public\.users_locale_idx|two indexes are named users_locale_idx/'],
        ];
        $dir = $this->copy($full, 'err');
        $before = count(glob($dir->path . '/*.json'));
        foreach ($refused as $j => [$what, $state, $why]) {
            $this->check($what, function () use ($dir, $state, $why, $j): string {
                $diff = $this->diff($dir, new Step(4, 'refused', $state, ''), "R{$j}");
                if ($why === null) {
                    // Not refused — not done either: an extension leaves only by --drop.
                    expect(!in_array('dropExtension', array_column($diff->changes, 'op'), true), 'the extension would be dropped');
                    expect((bool) preg_grep('/--drop=extension:btree_gist drops it/', $diff->notices), 'no notice says how to drop it');
                    return 'kept, said';
                }
                expect($diff->errors !== [], 'the snapshot was not refused');
                expect((bool) preg_grep($why, $diff->errors), 'refused for another reason: ' . implode(' ', $diff->errors));
                return 'refused';
            }, stop: false);
        }
        $this->check('nothing was written', fn() => expect(count(glob($dir->path . '/*.json')) === $before, 'a version was written'));

        Out::stage('migrate');
        $this->refusedMigration('a version edited after it was applied', '/V010_\w+ changed after this database applied it/', function (SchemaDirectory $d): void {
            $f = glob($d->path . '/V010_*.json')[0];
            file_put_contents($f, file_get_contents($f) . ' ');
        });
        $this->refusedMigration('the code of an applied version edited', '/V008_\w+ changed after this database applied it/', function (SchemaDirectory $d): void {
            $f = glob($d->path . '/custom/V008_*.php')[0];
            file_put_contents($f, file_get_contents($f) . "\n// edited\n");
        });
        $this->refusedMigration('the files of an applied version gone', '/V010_\w+ is in the database\'s history, but its files are gone/', function (SchemaDirectory $d): void {
            foreach (glob($d->path . '/V010_*.json') as $f) {
                unlink($f);
            }
        });
        $this->refusedMigration('a version that arrived late', '/V010_\w+ is older than V022.*arrived late/', function (): void {
            $this->db->exec("DELETE FROM public.winter_ppa_schema_history WHERE version = 'V010'");
        });
        $this->refusedMigration('a version of another driver', '/recorded for mysql, the database is pgsql/', function (SchemaDirectory $d): void {
            file_put_contents($d->path . '/V023_other.json', json_encode(['version' => 'V023', 'name' => 'other', 'driver' => 'mysql', 'changes' => []]));
        });

        $flag = $last->with('users', 'flag', ['#[Boolean]'], 'bool $flag = false');
        $this->failingVersion('code that throws: the version rolled back', new Step(4, 'flag', $flag, '', custom: true, after: "throw new \\RuntimeException('no luck');"), '/V023_flag failed and was rolled back: no luck/');
        $narrow = $last->attrs('users', 'locale', fn($a) => ['#[Varchar(5)]']);
        $this->failingVersion('code that leaves the database wrong', new Step(4, 'narrow_locale', $narrow, '', custom: true), '/differs from its model.*users\.locale/s');
    }

    // ── Steps ───────────────────────────────────────────────────────────────

    /** `call db snapshot` of a step: diff, refused if it has errors, written, its code filled in. */
    private function snapshot(SchemaDirectory $dir, Step $step, int $n, string $key): SchemaDiff
    {
        $diff = $this->diff($dir, $step, $key);
        expect($diff->errors === [], 'the snapshot refused: ' . implode(' ', $diff->errors));
        foreach ($step->expect as $op => $count) {
            $has = count(array_filter($diff->changes, fn(array $c) => $c['op'] === $op));
            expect($has === $count, "{$op}: {$count} expected, {$has} recorded — " . implode(', ', array_column($diff->changes, 'op')));
        }
        $written = SchemaSnapshot::write($dir, $diff, 'pgsql', $step->name, $step->custom, self::CODE_NAMESPACE);
        expect($written['version'] === sprintf('V%03d', $n), "written as {$written['version']}");
        if ($step->custom) {
            self::fillCode(end($written['files']), $step);
        }
        $again = $this->diff($dir, $step, $key);
        expect($again->isEmpty() && $again->errors === [], 'a second snapshot of the same state records something: '
            . implode(', ', array_column($again->changes, 'op')));
        return $diff;
    }

    private function diff(SchemaDirectory $dir, Step $step, string $key): SchemaDiff
    {
        $items = PPAMapping::declarationFrom($this->load($step->state, $key))->getItems();
        expect(count($items) === 1, count($items) . ' configs mapped');
        return SchemaSnapshot::diff(
            $dir,
            $items[0]->getTables(),
            'pgsql',
            $step->drop,
            $step->keep,
            $step->custom,
            $items[0]->getExtensions(),
        );
    }

    /** The state's classes, written and loaded once: a namespace per state (`S007`, `R3`…). */
    private function load(State $state, string $key): array
    {
        if (!isset($this->loaded[$key])) {
            $ns = 'Flytachi\\Winter\\Ppa\\E2e\\States\\' . $key;
            $file = "{$this->tmp}/states/{$key}.php";
            file_put_contents($file, $state->code($ns));
            require $file;
            $this->loaded[$key] = array_map(
                fn(array $t) => new \ReflectionClass($ns . '\\' . $t['repo']),
                array_values($state->tables),
            );
        }
        return $this->loaded[$key];
    }

    private static function fillCode(string $file, Step $step): void
    {
        $php = (string) file_get_contents($file);
        foreach (['before' => $step->before, 'after' => $step->after] as $method => $body) {
            if ($body === null) {
                continue;
            }
            $empty = "public function {$method}(CDO \$db): void\n    {\n    }";
            expect(str_contains($php, $empty), "the skeleton has no empty {$method}()");
            $php = str_replace($empty, "public function {$method}(CDO \$db): void\n    {\n        {$body}\n    }", $php);
        }
        file_put_contents($file, $php);
    }

    private function refusedMigration(string $what, string $why, \Closure $break): void
    {
        $this->check($what, function () use ($why, $break): string {
            Db::reset($this->db);
            $dir = $this->copy(count($this->steps), 'm' . bin2hex(random_bytes(2)));
            $this->migrator($dir)->migrate();
            $break($dir);
            $this->expectThrows(fn() => $this->migrator($dir)->migrate(), $why);
            return 'refused';
        }, stop: false);
    }

    private function failingVersion(string $what, Step $step, string $why): void
    {
        $this->check($what, function () use ($step, $why): string {
            Db::reset($this->db);
            $full = count($this->steps);
            $dir = $this->copy($full, 'f' . bin2hex(random_bytes(2)));
            $this->migrator($dir)->migrate();
            $schema = Db::dump($this->db);
            $this->snapshot($dir, $step, $full + 1, 'F_' . $step->name);
            $this->expectThrows(fn() => $this->migrator($dir)->migrate(), $why);
            expect(Db::dump($this->db) === $schema, 'the schema changed: the version was not rolled back');
            expect(one($this->db, 'SELECT count(*) FROM public.winter_ppa_schema_history WHERE version = \'V023\'') == 0, 'the failed version is in the history');
            return 'rolled back';
        }, stop: false);
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    private function matchesModel(SchemaDirectory $dir): void
    {
        $differences = new SchemaInspector($this->db, 'pgsql')->compare(SchemaModel::fold($dir->versions('pgsql')))['differences'];
        expect($differences === [], 'the database differs from the model: ' . implode('; ', $differences));
    }

    private function counts(int $version): void
    {
        foreach (Data::counts($version) as $table => $rows) {
            $has = (int) one($this->db, "SELECT count(*) FROM {$table}");
            expect($has === $rows, "{$table}: {$rows} rows expected, {$has} there");
        }
    }

    /**
     * Every attribute of ppa, every parameter of it, every FKAction and IndexMethod: used by
     * some state, or the run fails — a new one is a gap until the scenario uses it.
     */
    private function coverage(): void
    {
        Out::block('Coverage');
        $usages = [];
        foreach ($this->steps as $step) {
            $usages += array_flip($step->state->attributeUsages());
        }
        $usages = array_keys($usages);
        $used = [];
        foreach ($usages as $u) {
            if (!preg_match('/^#\[(\w+)(?:\((.*)\))?\]$/s', $u, $m)) {
                continue;
            }
            $used[$m[1]] ??= [];
            foreach (self::arguments($m[2] ?? '') as $k => $arg) {
                $used[$m[1]][] = preg_match('/^(\w+)\s*:(?!:)/', $arg, $n) ? $n[1] : $k;
            }
        }
        $classes = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Mapping/Attributes/*/*.php') as $file) {
            $class = 'Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
            if (class_exists($class) && new \ReflectionClass($class)->getAttributes(\Attribute::class) !== []) {
                $classes[basename($file, '.php')] = $class;
            }
        }
        $this->check('every attribute', function () use ($classes, $used): string {
            $missing = array_diff(array_keys($classes), array_keys($used));
            expect($missing === [], 'used by no state: ' . implode(', ', $missing));
            return count($classes) . ' attributes';
        }, stop: false);
        $this->check('every parameter of every attribute', function () use ($classes, $used): string {
            $missing = [];
            $count = 0;
            foreach ($classes as $short => $class) {
                $params = new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
                foreach ($params as $k => $p) {
                    $count++;
                    $given = $used[$short] ?? [];
                    if (!in_array($p->getName(), $given, true) && !in_array($k, $given, true)) {
                        $missing[] = "{$short}::\${$p->getName()}";
                    }
                }
            }
            expect($missing === [], 'given by no state: ' . implode(', ', $missing));
            return "{$count} parameters";
        }, stop: false);
        $this->check('every FKAction and IndexMethod', function () use ($usages): string {
            $text = implode("\n", $usages);
            $missing = [];
            foreach ([\Flytachi\Winter\Ppa\Mapping\Constants\FKAction::class, \Flytachi\Winter\Ppa\Mapping\Constants\IndexMethod::class] as $enum) {
                $short = substr($enum, (int) strrpos($enum, '\\') + 1);
                foreach ($enum::cases() as $case) {
                    if (!str_contains($text, "{$short}::{$case->name}")) {
                        $missing[] = "{$short}::{$case->name}";
                    }
                }
            }
            expect($missing === [], 'used by no state: ' . implode(', ', $missing));
            return count(\Flytachi\Winter\Ppa\Mapping\Constants\FKAction::cases()) + count(\Flytachi\Winter\Ppa\Mapping\Constants\IndexMethod::cases()) . ' cases';
        }, stop: false);
    }

    /** @return list<string> The arguments of an attribute, split at the commas of its own level. */
    private static function arguments(string $args): array
    {
        $out = [];
        $depth = 0;
        $quote = null;
        $cur = '';
        for ($i = 0, $n = strlen($args); $i < $n; $i++) {
            $ch = $args[$i];
            if ($quote !== null) {
                $quote = $ch === $quote ? null : $quote;
            } elseif ($ch === "'" || $ch === '"') {
                $quote = $ch;
            } elseif ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if (trim($cur) !== '') {
            $out[] = trim($cur);
        }
        return $out;
    }

    private static function same(array $a, array $b): string
    {
        $lines = [];
        foreach ($a as $kind => $list) {
            foreach (array_diff($list, $b[$kind]) as $l) {
                $lines[] = "only in the first: {$kind} {$l}";
            }
            foreach (array_diff($b[$kind], $list) as $l) {
                $lines[] = "only in the second: {$kind} {$l}";
            }
            if ($lines === [] && $list !== $b[$kind]) {
                $lines[] = "{$kind} in another order";
            }
        }
        expect($lines === [], implode("\n          ", $lines));
        return count($a['columns']) . ' columns, ' . count($a['indexes']) . ' indexes, ' . count($a['constraints']) . ' constraints';
    }

    private function expectThrows(\Closure $run, string $why): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            expect((bool) preg_match($why, $e->getMessage()), 'refused for another reason: ' . $e->getMessage());
            return;
        }
        throw new Failure('not refused');
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    private function variant(string $title, \Closure $run): mixed
    {
        Out::block($title);
        try {
            return $run();
        } catch (Stopped) {
            Out::stopped();
            return null;
        } catch (\Throwable $e) {
            $this->failures++;
            Out::failed('the variant stopped', $e);
            return null;
        }
    }

    /**
     * One line of the report. A failure is counted and said; with `$stop` the variant ends
     * there — the steps after it stand on this one.
     */
    private function check(string $what, \Closure $run, bool $stop = true): void
    {
        $t0 = hrtime(true);
        try {
            $note = $run();
            Out::ok($what, is_string($note) ? $note : '', (hrtime(true) - $t0) / 1e6);
        } catch (\Throwable $e) {
            $this->failures++;
            Out::failed($what, $e);
            if ($stop) {
                throw new Stopped($what, previous: $e);
            }
        }
    }

    private function migrator(SchemaDirectory $dir): SchemaMigrator
    {
        return new SchemaMigrator($this->db, 'pgsql', $dir, self::OWNER, 'public', new NullLogger(), 'E2E');
    }

    private function dir(string $name): SchemaDirectory
    {
        return new SchemaDirectory($this->tmp . '/' . $name);
    }

    /** The first `$upTo` versions of variant A's history, as if the others did not exist yet. */
    private function copy(int $upTo, string $name): SchemaDirectory
    {
        $from = $this->tmp . '/history';
        $to = $this->tmp . '/' . $name;
        @mkdir($to . '/custom', 0777, true);
        foreach (glob($from . '/V*.json') as $json) {
            $base = basename($json, '.json');
            if (SchemaVersionFile::number(substr($base, 0, (int) strpos($base, '_'))) > $upTo || is_file("{$to}/{$base}.json")) {
                continue;
            }
            copy($json, "{$to}/{$base}.json");
            if (is_file("{$from}/custom/{$base}.php")) {
                copy("{$from}/custom/{$base}.php", "{$to}/custom/{$base}.php");
            }
        }
        return new SchemaDirectory($to);
    }

    private static function summary(SchemaDiff $diff): string
    {
        $ops = array_count_values(array_column($diff->changes, 'op'));
        return $ops === [] ? 'code only' : implode(', ', array_map(fn($op, $n) => "{$n} {$op}", array_keys($ops), $ops));
    }

    private static function rm(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
