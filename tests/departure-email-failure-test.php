<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-email-failure.php';

$checkFailure(static fn()=>App\Models\NotificationTemplate::where('key','domains_domain_departed')->update(['body'=>'{{ departure_email_missing_helper() }}']), 'template_failed', []);

$checkFailure(static function():void {
    // The first two subject renders succeed; rendering the body changes the template's dependency.
    App\Models\NotificationTemplate::where('key','domains_domain_departed')->firstOrFail()->update([
        'subject'=>'@if(!empty($GLOBALS["departureBodyRendered"])) @php throw new \RuntimeException("Subject dependency unavailable."); @endphp @endif {{ $domain }}',
        'body'=>'@php $GLOBALS["departureBodyRendered"]=true; @endphp Departure notice.',
    ]);
}, 'template_failed', ['handoff']);
unset($GLOBALS['departureBodyRendered']);

$checkFailure(static function():Closure{
    App\Models\NotificationTemplate::where('key','domains_domain_departed')->firstOrFail()->update(['mail_enabled'=>App\Enums\NotificationEnabledStatus::Force]);
    $off=config('settings.mail_disable');config(['settings.mail_disable'=>true]);return static fn()=>config(['settings.mail_disable'=>$off]);
}, 'template_failed', []);

$checkFailure(static fn()=>App\Models\NotificationTemplate::where('key','domains_domain_departed')->delete(), 'template_failed', []);

$checkFailure(static fn()=>App\Models\EmailLog::creating(static function():void{throw new RuntimeException('Mail handoff unavailable.');}), 'delivery_uncertain', ['uncertain']);

$nativeSuccessful = true;
