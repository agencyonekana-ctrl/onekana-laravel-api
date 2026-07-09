<?php

namespace Onekana\Api\Support;

final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function iso(): string
    {
        return gmdate('c');
    }
}
