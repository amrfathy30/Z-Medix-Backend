<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Delivers a one-time passcode by email.
 *
 * The plaintext code is held only for the lifetime of this notification and is
 * never persisted or logged. The copy is chosen from the OTP's purpose so a
 * password reset is never described as account verification.
 */
class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly Carbon $expiresAt,
        private readonly OtpPurpose $purpose = OtpPurpose::EmailVerification,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($this->expiresAt, absolute: true) / 60));

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting($this->greeting());

        foreach ($this->introLines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->line('**'.$this->code.'**')
            ->line("This code expires in {$minutes} minutes and can only be used once.")
            ->line($this->closingLine());
    }

    /**
     * The code is deliberately excluded so it is never written to a stored
     * notification record.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'purpose' => $this->purpose->value,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }

    private function subject(): string
    {
        return match ($this->purpose) {
            OtpPurpose::PasswordReset => 'Your '.config('app.name').' password reset code',
            default => 'Your '.config('app.name').' verification code',
        };
    }

    private function greeting(): string
    {
        return match ($this->purpose) {
            OtpPurpose::PasswordReset => 'Reset your password',
            default => 'Verify your email address',
        };
    }

    /** @return list<string> */
    private function introLines(): array
    {
        return match ($this->purpose) {
            OtpPurpose::PasswordReset => [
                'We received a request to reset the password for your account.',
                'Use the code below to continue resetting your password.',
            ],
            default => [
                'Use the code below to finish setting up your account.',
            ],
        };
    }

    private function closingLine(): string
    {
        return match ($this->purpose) {
            OtpPurpose::PasswordReset => 'If you did not request a password reset, you can safely ignore this email and your password will stay unchanged.',
            default => 'If you did not create an account, you can safely ignore this email.',
        };
    }
}
