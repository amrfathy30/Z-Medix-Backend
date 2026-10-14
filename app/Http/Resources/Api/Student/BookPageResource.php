<?php

namespace App\Http\Resources\Api\Student;

use App\Models\BookPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One page of a content book.
 *
 * `position` is supplied by the caller, which already knows where the page sits
 * in the listing it is building. Deriving it here would cost a count query per
 * page, which is exactly what a paginated reader must not do.
 *
 * @mixin BookPage
 */
class BookPageResource extends JsonResource
{
    public function __construct($resource, private int $position)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'position' => $this->position,
            'content' => $this->content,
        ];
    }
}
