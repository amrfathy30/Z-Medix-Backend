<?php

namespace App\Models;

use App\Enums\ContactMessageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'replied_by_admin_id',
        'replied_at',
        'admin_reply',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactMessageStatus::class,
            'metadata' => 'array',
            'replied_at' => 'datetime',
        ];
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'replied_by_admin_id');
    }
}
