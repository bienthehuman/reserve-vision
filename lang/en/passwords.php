<?php

declare(strict_types=1);

// Laravel's password broker statuses, in the project's voice.
return [
    'reset' => 'Your password is changed. Log in with the new one.',
    // Same answer whether or not the email has an account, so the form can't be used to find accounts.
    'sent' => 'If there is an account for that email, we\'ve sent it a link to reset the password.',
    'throttled' => 'We sent a link a moment ago. Wait a minute before asking for another.',
    'token' => 'This reset link has expired or was already used. Ask for a new one below.',
    'user' => 'If there is an account for that email, we\'ve sent it a link to reset the password.',
];
