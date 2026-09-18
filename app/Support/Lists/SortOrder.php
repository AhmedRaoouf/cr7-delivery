<?php

namespace App\Support\Lists;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A whitelisted `sort` (ADMIN-LISTS.md §0.4), and the `ORDER BY` it becomes.
 *
 * Every sort ends on the primary key in the same direction. Without that tiebreak MySQL
 * may order two rows with the same `created_at` differently on page 1 and page 2, which
 * is how a paged list shows one row twice and never shows another.
 */
final class SortOrder
{
    public function __construct(
        public readonly string $field,
        public readonly bool $descending,
    ) {}

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    /** What `meta.sort` echoes: `-created_at`, `name`. */
    public function __toString(): string
    {
        return ($this->descending ? '-' : '').$this->field;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string|Closure(Builder<TModel>, string): mixed>  $columns  public field → column, or a closure given the direction
     * @return Builder<TModel>
     */
    public function apply(Builder $query, array $columns, bool $tiebreak = true): Builder
    {
        $target = $columns[$this->field] ?? $this->field;

        if ($target instanceof Closure) {
            $target($query, $this->direction());
        } else {
            $query->orderBy($target, $this->direction());
        }

        if ($tiebreak) {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), $this->direction());
        }

        return $query;
    }
}
