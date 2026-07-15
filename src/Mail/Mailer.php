<?php

namespace Onekana\Api\Mail;

interface Mailer
{
    public function sendPasswordLink(string $email, string $name, string $token, bool $invitation = false): void;
}
