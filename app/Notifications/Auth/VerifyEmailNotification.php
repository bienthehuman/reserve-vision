<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\Auth\VerifyEmailMail;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The "confirm your email" mail. Laravel builds the signed link; the mail itself
 * is a Markdown mailable. Queued, so a slow mail server never slows sign-up.
 */
final class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): VerifyEmailMail
    {
        return (new VerifyEmailMail($notifiable, $this->verificationUrl($notifiable)))->to($notifiable->email);
    }
}
