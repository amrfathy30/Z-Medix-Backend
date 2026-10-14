<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar-day boundaries
    |--------------------------------------------------------------------------
    |
    | Day Streak, Daily Study Time and the midnight split of activity periods
    | all need one agreed answer to "which day was that?". Timestamps are stored
    | in UTC like everywhere else in the app; this is the timezone those stored
    | instants are bucketed into before a date is taken from them, so a student
    | and the analytics they are shown always agree on where a day ends.
    |
    */

    'timezone' => env('FELLOWSHIP_TIMEZONE', 'Africa/Cairo'),

    /*
    |--------------------------------------------------------------------------
    | Per-question time limit
    |--------------------------------------------------------------------------
    |
    | The platform-wide advisory limit for one Fellowship question, in seconds.
    | The admin edits the live value from the Fellowship Settings page, which
    | stores it as a Setting row; this is the fallback used before one has ever
    | been saved. There is no total session limit.
    |
    */

    'question_time_limit_seconds' => env('FELLOWSHIP_QUESTION_TIME_LIMIT_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Import limits
    |--------------------------------------------------------------------------
    */

    'import' => [
        'max_file_size_kb' => env('FELLOWSHIP_IMPORT_MAX_FILE_SIZE_KB', 10 * 1024),

        'mime_types' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'text/plain',
        ],

        'extensions' => ['xlsx', 'xls', 'csv'],

        /**
         * The one directory on the import disk uploads land in. Both the upload
         * field and the check that a stored path really came from it read this,
         * so a crafted request cannot point the wizard at a file elsewhere on
         * the disk.
         */
        'directory' => env('FELLOWSHIP_IMPORT_DIRECTORY', 'fellowship-imports'),

        /** Hard ceiling on rows staged from one file, to keep staging bounded. */
        'max_rows' => env('FELLOWSHIP_IMPORT_MAX_ROWS', 5000),

        /** Disk the uploaded workbook is parked on while the wizard runs. */
        'disk' => env('FELLOWSHIP_IMPORT_DISK', 'local'),

        /**
         * How many answer-option columns the mapping step offers slots for.
         * Two are required; the rest are optional. The step never offers more
         * slots than the file has spare columns, so this is only the ceiling.
         */
        'max_answer_option_columns' => env('FELLOWSHIP_IMPORT_MAX_ANSWER_OPTION_COLUMNS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session generation
    |--------------------------------------------------------------------------
    */

    'session' => [
        'min_question_count' => 1,

        'max_question_count' => env('FELLOWSHIP_SESSION_MAX_QUESTION_COUNT', 200),
    ],

];
