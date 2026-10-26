<?php

return [
    'request_type' => 'Request type',
    'request_types' => ['new' => 'New Subscription', 'renewal' => 'Renewal'],
    'features' => [
        'library' => 'Library',
        'question_bank' => 'Question Bank',
        'case_report' => 'Case Report',
        'ai' => 'AI Assistant',
    ],

    'billing_cycles' => [
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
    ],

    'request_statuses' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'statuses' => [
        'none' => 'No subscription',
        'active' => 'Active',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
    ],

    // Pre-filled into the WhatsApp chat the student opens after requesting a plan.
    'whatsapp_message' => 'Hello, I would like to subscribe to the :plan plan (:cycle).',
];
