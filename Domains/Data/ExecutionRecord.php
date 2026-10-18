<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum ExecutionRecord: string
{
    case Absent = 'absent';
    case RecordedNull = 'recorded_null';
    case Recorded = 'recorded';
}
