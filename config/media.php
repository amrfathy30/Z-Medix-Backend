<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits and accepted MIME types
    |--------------------------------------------------------------------------
    |
    | Shared by MediaUploadService consumers (Filament resources/pages) so
    | upload constraints aren't hardcoded in each place a file is uploaded.
    | This is app-level config, separate from Spatie's own media-library.php.
    |
    */

    'max_image_size_kb' => env('MEDIA_MAX_IMAGE_SIZE_KB', 5 * 1024),

    'max_document_size_kb' => env('MEDIA_MAX_DOCUMENT_SIZE_KB', 10 * 1024),

    'max_video_size_kb' => env('MEDIA_MAX_VIDEO_SIZE_KB', 50 * 1024),

    'max_audio_size_kb' => env('MEDIA_MAX_AUDIO_SIZE_KB', 20 * 1024),

    'image_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/svg+xml',
    ],

    'document_mime_types' => [
        'application/pdf',
    ],

    'video_mime_types' => [
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ],

    'audio_mime_types' => [
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/ogg',
    ],

];
