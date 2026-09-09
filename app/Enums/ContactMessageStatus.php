<?php

namespace App\Enums;

enum ContactMessageStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Replied = 'replied';
    case Closed = 'closed';
    case Spam = 'spam';
}
