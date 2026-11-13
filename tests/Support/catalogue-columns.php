<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Synthetic fixture adapters for the columns this cutover changes. No runtime compatibility writes.
/** Current price columns. */
function costEvidence(?object $row): ?string
{
    if($row===null||$row->cost_enabled===null){return null;}
    $utc=new DateTimeZone('UTC');
    return json_encode(['action'=>$row->action,'term'=>(int)$row->term,'enabled'=>(bool)$row->cost_enabled,
        'total'=>$row->wholesale===null?null:\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::money((string)$row->wholesale,4),
        'currency'=>$row->currency,'source_unit'=>$row->cost_source_unit,'source'=>$row->source,
        'observed_at'=>(new DateTimeImmutable((string)$row->observed_at,$utc))->format(DATE_ATOM),
        'fresh_until'=>(new DateTimeImmutable((string)$row->fresh_until,$utc))->format(DATE_ATOM)],JSON_THROW_ON_ERROR);
}
function costColumns(mixed $json): array
{
    if($json===null){return ['cost_enabled'=>null];}
    try{return \Paymenter\Extensions\Servers\Domains\Catalogue\CurrentCost::values(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::cost(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($json)));}
    catch(\Throwable){return ['cost_enabled'=>true,'cost_source_unit'=>'invalid'];}
}
/** Restore the reminder batch column only for an explicit pre-retirement fixture. */
function legacyReminderAuditColumn(): bool
{
    if(\Illuminate\Support\Facades\Schema::hasColumn('domains_reminder_schedules','audit')){return false;}
    \Illuminate\Support\Facades\Schema::table('domains_reminder_schedules',static fn($t)=>$t->text('audit')->nullable());
    \Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->update(['audit'=>encrypt([])]);
    return true;
}
