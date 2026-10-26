<?php

return [
    'adapter' => env('AI_CHAT_ADAPTER', 'unavailable'),
    'mock_failure' => env('AI_CHAT_MOCK_FAILURE', false),
    'queue_connection' => env('AI_CHAT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('AI_CHAT_QUEUE', 'default'),
    'max_attachments' => 10,
    'max_attachment_snapshot_bytes' => 100000,
    'max_message_length' => 10000,
    // Keep complete responses within Reverb's configured request size.
    'max_response_bytes' => 20000,
];
