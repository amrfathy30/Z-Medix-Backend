<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chapter page a student has marked as read. Reading progress is counted from
 * these rows, never stored as a percentage.
 */
class StudentChapterPageProgress extends Model
{
    use HasFactory;

    protected $table = 'student_chapter_page_progress';

    protected $fillable = [
        'user_id',
        'chapter_page_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chapterPage(): BelongsTo
    {
        return $this->belongsTo(ChapterPage::class);
    }
}
