<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native management completion and customer Blade share durable operation evidence.
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Services\ManagementProgress;

[$d,$driver]=$holding(placeholderComFixtureName('customer-deferred-placeholder.com'));
$op=$submit($d,$driver,'nameservers',['nameservers'=>['ns1.example.net','ns2.example.net']]);
Carbon\Carbon::setTestNow($clock->now()->modify('+45 seconds'));
expect(!ManagementProgress::for($d->fresh())['nameservers']['slow'],'A new request without deferrals has a grace period');
Carbon\Carbon::setTestNow($clock->now()->modify('+120 seconds'));
expect(ManagementProgress::for($d->fresh())['nameservers']['slow'],'Without deferrals the grace period starts at creation');
// An old request gets a fresh grace period after its latest unsent deferral.
$deferred=$clock->now()->modify('+10 minutes');
$op->update(['safe_code'=>'management_deferred','attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent',
    'management_deferrals'=>[$clock->now()->format(DATE_ATOM),$deferred->format(DATE_ATOM)]]]);
Carbon\Carbon::setTestNow($deferred->modify('+45 seconds'));
$row=ManagementProgress::for($d->fresh())['nameservers'];
expect($row['pending']&&!$row['slow']&&!str_contains($row['text'],'longer'),'Latest deferral grants a fresh grace period despite the old creation time');
Carbon\Carbon::setTestNow($deferred->modify('+120 seconds'));
$row=ManagementProgress::for($d->fresh())['nameservers'];
expect($row['slow']&&str_contains($row['text'],'longer'),'A queued deferral taking 120 seconds warns the customer');
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'running']);
Carbon\Carbon::setTestNow($deferred->modify('+3 minutes'));
$row=ManagementProgress::for($d->fresh())['nameservers'];
expect($row['slow'],'Reclaiming a deferred change cannot hide a long wait');
$nativeSuccessful=true; echo "Customer deferred progress passed.\n";
