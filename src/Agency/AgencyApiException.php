<?php

namespace Onekana\Api\Agency;

use RuntimeException;

final class AgencyApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}
