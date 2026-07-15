<?php

namespace Tests\Support;

use Onekana\Api\Mail\Mailer;

final class FakeMailer implements Mailer
{
    public array $messages = [];

    public function sendPasswordLink(string $email, string $name, string $token, bool $invitation = false): void
    {
        $this->messages[] = compact('email', 'name', 'token', 'invitation');
    }

    public function latestToken(): string
    {
        return (string) ($this->messages[array_key_last($this->messages)]['token'] ?? '');
    }
}
