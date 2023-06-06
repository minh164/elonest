<?php

namespace Minh164\EloNest\Jobs;

use Illuminate\Support\Facades\Log;

abstract class Job
{
    public static function logError(string $message): void
    {
        Log::error(static::class . ": " . $message);
    }

    public static function logInfo(string $message): void
    {
        Log::info(static::class . ": " . $message);
    }
}
