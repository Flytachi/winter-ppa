<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * One snapshot of the application's life: the state its entities reach, what the developer
 * passes to `call db snapshot` (`--drop`, `--keep`, `custom`), the code of a custom version,
 * what the snapshot must record, and what the data must look like once it is applied.
 */
final readonly class Step
{
    /**
     * @param list<string> $drop
     * @param list<string> $keep
     * @param array<string, int> $expect op => how many of it the version records (the ops not named are free)
     * @param ?string $before The body of before(CDO $db) — `$db` is there.
     * @param ?string $after The body of after(CDO $db).
     * @param ?string $props Declarations in the class, beside before() and after().
     * @param ?\Closure(\PDO): void $check Run right after the version, on a database with data.
     */
    public function __construct(
        public int $stage,
        public string $name,
        public State $state,
        public string $why,
        public array $drop = [],
        public array $keep = [],
        public bool $custom = false,
        public array $expect = [],
        public ?string $before = null,
        public ?string $after = null,
        public ?\Closure $check = null,
        public ?string $props = null,
    ) {
    }
}
