<?php

namespace App\Models;

use App\Enums\QuizDifficulty;
use App\Support\Fellowship\QuestionTextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A question in a Fellowship's bank.
 *
 * Deletes are soft, and sessions already generated are unaffected either way:
 * they serve their own snapshots, so a question removed or reworded here
 * changes nothing a student has already been shown or scored.
 *
 * `content_hash` is kept in step with the question text by the model itself, so
 * every write path — importer, admin form, factory — leaves duplicate detection
 * able to find this row.
 */
class FellowshipQuestion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'fellowship_id',
        'question_text',
        'explanation',
        'difficulty',
        'performance_filter_value_id',
    ];

    protected function casts(): array
    {
        return [
            'difficulty' => QuizDifficulty::class,
        ];
    }

    /**
     * The hash is derived data, never set by a caller: keeping it in a saving
     * hook means no write path can forget it.
     */
    protected static function booted(): void
    {
        static::saving(function (FellowshipQuestion $question): void {
            $question->content_hash = QuestionTextNormalizer::hash($question->question_text);
        });
    }

    public function fellowship(): BelongsTo
    {
        return $this->belongsTo(Fellowship::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FellowshipQuestionOption::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * The question's single Performance Topic.
     */
    public function performanceTopic(): BelongsTo
    {
        return $this->belongsTo(FellowshipFilterValue::class, 'performance_filter_value_id');
    }

    /**
     * The question's values for the optional dynamic filters. The Performance
     * Topic is not among them — it is {@see performanceTopic()}.
     */
    public function filterValues(): HasMany
    {
        return $this->hasMany(FellowshipQuestionFilterValue::class);
    }

    public function correctOption(): ?FellowshipQuestionOption
    {
        return $this->options->firstWhere('is_correct', true);
    }

    /**
     * @param  Builder<FellowshipQuestion>  $query
     * @return Builder<FellowshipQuestion>
     */
    public function scopeForFellowship(Builder $query, int $fellowshipId): Builder
    {
        return $query->where('fellowship_id', $fellowshipId);
    }

    /**
     * @param  Builder<FellowshipQuestion>  $query
     * @return Builder<FellowshipQuestion>
     */
    public function scopeWithContentHash(Builder $query, string $hash): Builder
    {
        return $query->where('content_hash', $hash);
    }

    /**
     * The value of one dynamic filter on this question, or null when it carries
     * none — which is normal for a filter introduced after the question was.
     */
    public function valueForFilter(int $filterId): ?FellowshipQuestionFilterValue
    {
        return $this->filterValues->firstWhere('fellowship_filter_id', $filterId);
    }
}
