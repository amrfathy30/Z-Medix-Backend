<?php

namespace App\Support\Content\Enums;

enum ContentInputType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case RichText = 'rich_text';
    case Url = 'url';
    case Email = 'email';
    case Password = 'password';
    case Phone = 'phone';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Percentage = 'percentage';
    case Boolean = 'boolean';
    case CheckboxList = 'checkbox_list';
    case Radio = 'radio';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Image = 'image';
    case MultiImage = 'multi_image';
    case File = 'file';
    case VideoUpload = 'video_upload';
    case VideoUrl = 'video_url';
    case AudioUpload = 'audio_upload';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Color = 'color';
    case Icon = 'icon';
    case Cta = 'cta';
    case Repeater = 'repeater';
    case KeyValue = 'key_value';
    case Hidden = 'hidden';
}
