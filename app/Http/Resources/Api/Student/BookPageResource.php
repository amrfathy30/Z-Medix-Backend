<?php

namespace App\Http\Resources\Api\Student;

use App\Models\BookPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One page of a content book.
 *
 * The caller supplies the position from the ordered index or the page's
 * positionInBook(). Index entries omit the body, just like chapter pages.
 *
 * @mixin BookPage
 */
class BookPageResource extends JsonResource
{
    public function __construct($resource, private int $position, private bool $withContent = true)
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
            ...$this->withContent ? ['content' => $this->content] : [],
        ];
    }
}
