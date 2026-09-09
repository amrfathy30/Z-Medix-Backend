<?php

namespace App\Models;

use App\Enums\NotificationPriority;
use App\Enums\NotificationTemplateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

#[Translatable('title', 'body')]
class NotificationTemplate extends Model
{
    use HasFactory, HasTranslations, SoftDeletes;

    protected $fillable = [
        'key',
        'title',
        'body',
        'channels',
        'priority',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'priority' => NotificationPriority::class,
            'status' => NotificationTemplateStatus::class,
            'channels' => 'array',
        ];
    }
}
