<?php

namespace App\Support\Smoke;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Reads the verification code the application actually sent.
 *
 * `email_verification_otps` stores only a hash of the code, by design, so the
 * plaintext exists exactly once: inside the {@see EmailOtpNotification} the
 * registration request dispatched. Intercepting notification delivery lets the
 * smoke run pick that code up and submit it to the real `verify-otp` endpoint,
 * so the whole verification path — the pending OTP row, the attempt counter, the
 * hash comparison, the activation transaction — runs unchanged.
 *
 * This is not a verification bypass: no production code is touched, the
 * configured test code is not used, and a wrong code would still be rejected.
 * Only the mail transport is stubbed out, which is what a local smoke run wants
 * anyway.
 */
class SmokeOtpInbox
{
    /**
     * Start intercepting notifications. Must be called before the registration
     * request is dispatched.
     */
    public function intercept(): void
    {
        Notification::fake();
    }

    /**
     * The code from the newest email-verification notification sent to this
     * student.
     *
     * @throws RuntimeException when no verification code was sent
     */
    public function latestVerificationCodeFor(User $student): string
    {
        $notifications = Notification::sent($student, EmailOtpNotification::class);

        /** @var EmailOtpNotification|null $notification */
        $notification = $notifications
            ->filter(fn (EmailOtpNotification $sent): bool => ($sent->toArray($student)['purpose'] ?? null) === OtpPurpose::EmailVerification->value)
            ->last();

        if ($notification === null) {
            throw new RuntimeException('No email verification code was sent to '.$student->email.'.');
        }

        return $this->codeFrom($notification->toMail($student));
    }

    /**
     * The code as the student reads it out of the email body.
     */
    private function codeFrom(MailMessage $mail): string
    {
        $lines = array_map(
            fn (mixed $line): string => is_string($line) ? $line : '',
            [...$mail->introLines, ...$mail->outroLines],
        );

        foreach ($lines as $line) {
            if (preg_match('/\*\*(\d{4,10})\*\*/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        throw new RuntimeException('The verification email carried no readable code.');
    }
}
