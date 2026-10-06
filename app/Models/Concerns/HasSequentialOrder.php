<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gives a child model a deterministic `order` within its parent.
 *
 * On create, a model without an explicit `order` is appended after its current
 * siblings, so the same numeric order can exist under different parents while
 * remaining unique and stable inside one parent.
 */
trait HasSequentialOrder
{
    protected static function bootHasSequentialOrder(): void
    {
        static::creating(function (Model $model): void {
            if ($model->order === null) {
                $model->order = $model->nextOrderValue();
            }
        });
    }

    /**
     * The foreign key the `order` sequence is scoped to.
     */
    abstract public function orderParentColumn(): string;

    public function nextOrderValue(): int
    {
        $column = $this->orderParentColumn();

        $query = static::query()->where($column, $this->{$column});

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        return ((int) $query->max('order')) + 1;
    }
}
