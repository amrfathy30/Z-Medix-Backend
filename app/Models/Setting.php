<?php

namespace App\Models;

use App\Enums\SettingValueType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'value_type',
        'group',
        'is_public',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'value_type' => SettingValueType::class,
            'is_public' => 'boolean',
        ];
    }
}
