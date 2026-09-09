<?php

namespace App\Enums;

enum SettingValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Json = 'json';
    case Text = 'text';
    case Image = 'image';
}
