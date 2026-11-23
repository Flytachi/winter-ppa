<?php

declare(strict_types=1);

// Entities with every index the attributes can declare — for SchemaCheck and for the
// scenarios that build them on a live server.

namespace Flytachi\Winter\Ppa\Tests\Schema\Fixtures;

use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\ForeignKey;
use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\{Integer, Json, Varchar};
use Flytachi\Winter\Ppa\Mapping\Constants\{FKAction, IndexMethod};

/** What every driver builds — with notices on MySQL / SQLite, never an error. */
#[Table]
class ArticlePortable
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    #[Index]                                                    // B-tree
    #[Index(method: IndexMethod::HASH)]                         // beside it: articles_title_hash_idx
    public string $title = '';

    #[Integer]
    #[Index(where: 'views > 0')]                                // partial — whole on MySQL
    #[Index(['title'], include: ['id'], name: 'views_cover')]   // INCLUDE — PostgreSQL only
    public int $views = 0;

    #[Varchar(40)]
    #[Unique]
    public string $slug = '';
}

/** PostgreSQL: text search on a VARCHAR (pg_trgm) and GIN over JSONB. */
#[Table]
class ArticleSearch
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    #[Index(method: IndexMethod::GIN, opClass: 'gin_trgm_ops')]
    public string $title = '';

    #[Json]
    #[Index(method: IndexMethod::GIN)]
    public ?array $tags = null;
}

/** GIN on a plain VARCHAR: PostgreSQL has no operator class for it. */
#[Table]
class ArticleGinText
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    #[Index(method: IndexMethod::GIN)]
    public string $title = '';
}

/** GiST on an INT: needs btree_gist. */
#[Table]
class ArticleGistInt
{
    #[Id]
    public ?int $id = null;

    #[Integer]
    #[Index(method: IndexMethod::GIST)]
    public int $views = 0;
}

#[Table]
class ArticleHashTwoColumns
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    #[Index(['views'], method: IndexMethod::HASH)]
    public string $title = '';

    #[Integer]
    public int $views = 0;
}

#[Table]
class ArticleUniqueHash
{
    #[Id]
    public ?int $id = null;

    #[Varchar(40)]
    #[Unique(method: IndexMethod::HASH)]
    public string $slug = '';
}

/** A partial unique index: on MySQL it would be unique over every row. */
#[Table]
class ArticleUniqueWhere
{
    #[Id]
    public ?int $id = null;

    #[Varchar(40)]
    #[Unique(where: "slug <> ''")]
    public string $slug = '';
}

/** Two indexes of one name: the same column, the same method. */
#[Table]
class ArticleTwinIndexes
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    #[Index]
    #[Index]
    public string $title = '';
}

/** ArticlePortable before its indexes: the upgrade adds them to a table with rows. */
#[Table]
class ArticleBare
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    public string $title = '';

    #[Integer]
    public int $views = 0;

    #[Varchar(40)]
    public string $slug = '';
}

/** ArticleSearch before its indexes. */
#[Table]
class ArticleSearchBare
{
    #[Id]
    public ?int $id = null;

    #[Varchar(100)]
    public string $title = '';

    #[Json]
    public ?array $tags = null;
}

/** A key that sets its default: InnoDB makes it without the action. */
#[Table]
class ArticleSetDefault
{
    #[Id]
    public ?int $id = null;

    #[Integer]
    #[DefaultVal('1')]
    #[ForeignKey('authors', 'id', onUpdate: FKAction::SET_DEFAULT, onDelete: FKAction::SET_NULL)]
    public ?int $author_id = null;
}

/** An index of a column the entity has not — renamed away, the index left naming it. */
#[Table]
class ArticleIndexOfNoColumn
{
    #[Id]
    public ?int $id = null;

    #[Varchar(40)]
    #[Index(['slug_old'])]
    public string $slug = '';
}
