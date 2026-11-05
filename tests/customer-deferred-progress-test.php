<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native management completion and customer Blade share durable operation evidence.
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Services\ManagementProgress;

[$d,$driver]=$holding(placeholderComFixtureName('customer-deferred-placeholder.com'));
$op=$submit($d,$driver,'nameservers',['nameservers'=>['ns1.example.net','ns2.example.net']]);
// Replay a retained unsent deferral, without a provider call or a real wait.
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'management_deferred']);
Carbon\Carbon::setTestNow($clock->now()->modify('+45 seconds'));
$row=ManagementProgress::for($d->fresh())['nameservers'];
expect($row['pending']&&!$row['slow']&&!str_contains($row['text'],'longer'),'A deferred change is waiting, not already taking longer');
Carbon\Carbon::setTestNow($clock->now()->modify('+3 minutes'));
$row=ManagementProgress::for($d->fresh())['nameservers'];
expect(!$row['slow'],'Time waiting for another operation does not imply a slow send');
$nativeSuccessful=true; echo "Customer deferred progress passed.\n";
