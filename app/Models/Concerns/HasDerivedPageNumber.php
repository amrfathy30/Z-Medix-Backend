<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Gives a title-less page its admin-facing identity: a 1-based position among
 * its siblings, read as "Page 1", "Page 2", and so on.
 *
 * The position is derived from the live `order, id` sequence rather than stored,
 * so dragging a page to a new slot renumbers every sibling with it. Requires
 * {@see HasSequentialOrder} for the parent column the sequence is scoped to.
 */
trait HasDerivedPageNumber
{
    /**
     * The page's 1-based position among its siblings, matching the `order, id`
     * sequence the parent's pages relation returns.
     */
    public function positionAmongPages(): int
    {
        $column = $this->orderParentColumn();

        return static::query()
            ->where($column, $this->{$column})
            ->where(function (Builder $query): void {
                $query->where('order', '<', $this->order)
                    ->orWhere(function (Builder $tie): void {
                        $tie->where('order', $this->order)->where('id', '<', $this->getKey());
                    });
            })
            ->count() + 1;
    }

    /**
     * The admin-facing label for this page, e.g. "Page 2".
     */
    public function pageLabel(): string
    {
        return __('admin.learning.page_number', ['number' => $this->positionAmongPages()]);
    }
}
