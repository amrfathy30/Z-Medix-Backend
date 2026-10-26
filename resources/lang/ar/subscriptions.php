<?php

return [
    'request_type' => 'نوع الطلب',
    'request_types' => ['new' => 'اشتراك جديد', 'renewal' => 'تجديد'],
    'features' => [
        'library' => 'المكتبة',
        'question_bank' => 'بنك الأسئلة',
        'case_report' => 'تقارير الحالات',
        'ai' => 'المساعد الذكي',
    ],

    'billing_cycles' => [
        'monthly' => 'شهري',
        'yearly' => 'سنوي',
    ],

    'request_statuses' => [
        'pending' => 'قيد الانتظار',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض',
    ],

    'statuses' => [
        'none' => 'لا يوجد اشتراك',
        'active' => 'فعّال',
        'expired' => 'منتهي',
        'cancelled' => 'ملغي',
    ],

    'whatsapp_message' => 'مرحبًا، أرغب في الاشتراك في باقة :plan (:cycle).',
];
