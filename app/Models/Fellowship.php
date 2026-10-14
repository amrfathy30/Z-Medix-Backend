<?php

namespace App\Models;

use App\Services\Media\MediaUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A Fellowship question bank.
 *
 * The admin creates the Fellowship first and uploads questions into it
 * afterwards — every import, filter and question here is reached through this
 * record, so a Fellowship id is never typed by hand.
 *
 * Only an active Fellowship is offered to students on the Question Bank screen.
 */
class Fellowship extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const IMAGE_COLLECTION = 'fellowship_image';

    protected $fillable = [
        'name',
        'is_active',
        'performance_filter_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->useDisk('filament_public')
            ->singleFile();
    }

    /**
     * The filter whose values are this Fellowship's Performance Topics. Null
     * only until the first import designates one.
     */
    public function performanceFilter(): BelongsTo
    {
        return $this->belongsTo(FellowshipFilter::class, 'performance_filter_id');
    }

    public function filters(): HasMany
    {
        return $this->hasMany(FellowshipFilter::class)->orderBy('name');
    }

    /**
     * The dynamic filters a student may narrow a session by: every filter except
     * the Performance Topic, which is a required classification rather than an
     * optional one.
     */
    public function dynamicFilters(): HasMany
    {
        return $this->filters()->where('is_performance', false);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(FellowshipQuestion::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(FellowshipImport::class)->latest('id');
    }

    public function quizSessions(): HasMany
    {
        return $this->hasMany(FellowshipQuizSession::class);
    }

    /**
     * @param  Builder<Fellowship>  $query
     * @return Builder<Fellowship>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The Performance Topic values of this Fellowship, in display order — what
     * the Fellowship card is classified by.
     *
     * @return Collection<int, FellowshipFilterValue>
     */
    public function performanceTopics(): Collection
    {
        $filter = $this->performanceFilter;

        if ($filter === null) {
            return FellowshipFilterValue::query()->whereRaw('1 = 0')->get();
        }

        return $filter->values()->orderBy('value')->get();
    }

    /**
     * How many questions a student could be served from this bank. Soft-deleted
     * questions are excluded by the model's own scope.
     */
    public function availableQuestionsCount(): int
    {
        return $this->questions()->count();
    }

    public function imageUrl(): ?string
    {
        return app(MediaUploadService::class)->getUrl($this, self::IMAGE_COLLECTION);
    }
}
