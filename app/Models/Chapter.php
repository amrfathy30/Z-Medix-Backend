<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasSequentialOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chapter extends Model
{
    use HasFactory, HasSequentialOrder, SoftDeletes;

    protected $fillable = [
        'subject_id',
        'title',
        'status',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'order' => 'integer',
        ];
    }

    /**
     * The quiz follows the chapter's lifecycle: a chapter is never left with a
     * live quiz after it is soft-deleted, and restoring the chapter brings its
     * quiz back. A force delete is left to the database's cascade.
     */
    protected static function booted(): void
    {
        static::deleted(function (Chapter $chapter): void {
            if ($chapter->isForceDeleting()) {
                return;
            }

            $chapter->quiz()->first()?->delete();
        });

        static::restored(function (Chapter $chapter): void {
            $chapter->trashedQuiz()?->restore();
            $chapter->unsetRelation('quiz');
        });
    }

    public function orderParentColumn(): string
    {
        return 'subject_id';
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ChapterPage::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /**
     * A soft-deleted quiz still occupies the unique chapter_id slot, which is why
     * the quiz is restored with its chapter rather than created again.
     */
    public function trashedQuiz(): ?Quiz
    {
        return $this->quiz()->onlyTrashed()->first();
    }

    /**
     * Completion records of this chapter, one per student who finished it.
     */
    public function studentProgress(): HasMany
    {
        return $this->hasMany(StudentChapterProgress::class);
    }

    /**
     * Only published chapters are study content.
     *
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    public function quizQuestionCount(): int
    {
        return $this->quiz?->questions()->count() ?? 0;
    }

    /**
     * A chapter is only usable for student study once its quiz holds at least
     * one question, so it cannot be published before then.
     */
    public function isPublishable(): bool
    {
        return $this->quizQuestionCount() > 0;
    }
}
