<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chapter a student has finished. The row's existence is the completion, so
 * there is no status column to keep in step with it.
 */
class StudentChapterProgress extends Model
{
    use HasFactory;

    protected $table = 'student_chapter_progress';

    protected $fillable = [
        'user_id',
        'chapter_id',
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

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
