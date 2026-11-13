<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
/** Privileged fixture setup also permits damaged retained values, without invoking the staff writer. */
function fixtureBusinessChoice(string $key,mixed $value,string $type='array',bool $encrypted=false): void
{
    $column=\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::COLUMNS[$key];
    \Illuminate\Support\Facades\DB::table('domains_business_settings')->where('id',1)->update([$column=>json_encode([
        'type'=>$type,'encrypted'=>$encrypted,'value'=>$type==='array'&&!$encrypted?json_encode($value,JSON_THROW_ON_ERROR):$value,
    ],JSON_THROW_ON_ERROR)]);
}
function fixtureBusinessRaw(string $key): ?array
{ return \Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::singleton()->{\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::COLUMNS[$key]}; }
function fixtureClearBusinessChoice(string $key): void
{ \Illuminate\Support\Facades\DB::table('domains_business_settings')->where('id',1)->update([\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::COLUMNS[$key]=>null]); }
