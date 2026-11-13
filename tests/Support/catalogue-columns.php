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

/** Arrange the same synthetic intent after removal of the old TLD flags. */
function tldColumns(array $values,?\Paymenter\Extensions\Servers\Domains\Models\Tld $row=null): array
{
    if(\Illuminate\Support\Facades\Schema::hasColumn('domains_tlds','enabled')){return $values;}
    $keys=['enabled','register_enabled','renew_enabled','transfer_enabled','min_years','max_years'];
    if(!array_intersect(array_keys($values),$keys)){return $values;}
    $intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($row?->catalogue_intent);
    $stops=$intent['stops']??[];
    if(isset($values['enabled'])){$stops=$values['enabled']?[]:['register','renew','transfer'];}
    foreach(['register','renew','transfer'] as $action){
        if(array_key_exists($action.'_enabled',$values)&&(!isset($values['enabled'])||$values['enabled'])){
            $stops=array_values(array_diff($stops,[$action]));if(!$values[$action.'_enabled']){$stops[]=$action;}
        }
    }
    $intent['stops']=$stops;
    if(isset($values['max_years'])){$intent['longest']=(int)$values['max_years'];}
    return [...array_diff_key($values,array_flip($keys)),'catalogue_intent'=>$values['catalogue_intent']??json_encode($intent,JSON_THROW_ON_ERROR)];
}
