<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\Auth\ResetPasswordMail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The "reset your password" mail, as a Markdown mailable. Queued, like VerifyEmailNotification. */
final class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): ResetPasswordMail
    {
        $minutes = config('auth.passwords.users.expire');

        return (new ResetPasswordMail($notifiable, $this->resetUrl($notifiable), is_int($minutes) ? $minutes : 60))
            ->to($notifiable->email);
    }
}
