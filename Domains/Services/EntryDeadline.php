<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\SystemClock;
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
/** Capture before local capture/claim waits; all nested requests inherit this absolute UTC cap. */
final class EntryDeadline
{
    public const BACKGROUND_SECONDS = ExecuteOperation::EXECUTION_SECONDS - ExecuteOperation::FINISH_SECONDS;
    public const INTERACTIVE_SECONDS = 250;
    public static function run(Clock $clock, \Closure $work, int $seconds = self::BACKGROUND_SECONDS): mixed
    {
        $entered = fn () => $work($clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+'.$seconds.' seconds'));
        return $clock instanceof SystemClock ? $clock->withinExecution($entered) : $entered();
    }
}
