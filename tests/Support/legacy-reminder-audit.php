<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{DB,Schema};
/** Restore the reminder batch column only for an explicit pre-retirement fixture. */
function legacyReminderAuditColumn(): bool
{
    if(Schema::hasColumn('domains_reminder_schedules','audit')){return false;}
    Schema::table('domains_reminder_schedules',static fn($t)=>$t->text('audit')->nullable());
    DB::table('domains_reminder_schedules')->update(['audit'=>encrypt([])]);
    return true;
}
