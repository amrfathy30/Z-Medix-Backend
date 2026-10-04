<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case PhoneVerification = 'phone_verification';
    case EmailVerification = 'email_verification';
    case LoginVerification = 'login_verification';
    case PasswordReset = 'password_reset';
    case PhoneChange = 'phone_change';
}
