<?php

namespace App\Enums;

/**
 * The optional Answered/Unanswered narrowing a student may apply when
 * generating a session.
 *
 * "Answered" means this student has already confirmed an answer to that
 * question in any session of the Fellowship — not in this session, and not
 * merely that the question was once served to them.
 */
enum FellowshipQuestionStatusFilter: string
{
    case All = 'all';
    case Answered = 'answered';
    case Unanswered = 'unanswered';
}
