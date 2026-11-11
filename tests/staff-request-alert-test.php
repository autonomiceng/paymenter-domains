<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A domain change that fails, or that a registrar read leaves unconfirmed, puts one notice in the staff bell that links to
// the request. Later reads of the same request do not notify again, and staff who can't open requests get none.
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/staff-alerts.php';
use App\Models\Notification as NativeNotification;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
staffPanel();staffAlertFixture();
$viewer=staffUser([Staff::VIEW]);$blind=staffUser(['admin.domains.watch.view']);
$notices=static fn($user,string $title)=>NativeNotification::where('user_id',$user->id)->where('title',$title)->get()->map(static fn($row)=>[$row->body,$row->url])->all();
$link=static fn($op)=>route('filament.admin.resources.registrar-requests.view',['record'=>$op->id]);
$noRequestNotice=static function($op,string $case)use($viewer,$link):void {
    same([],NativeNotification::where('user_id',$viewer->id)->where('url',$link($op))
        ->whereIn('title',['Registrar request needs a check','Registrar request failed'])->pluck('title')->all(),$case.' sends no request-specific staff notice');
};
// A lost write response before any completed registrar read still has automatic checks ahead.
[$d,$driver]=$holding(placeholderComFixtureName('request-alert-before-read-placeholder.com'));
$state->afterWrite=static fn()=>throw new RuntimeException('Synthetic lost write response');
$op=$submit($d,$driver,'lock',['locked'=>false]);
same('uncertain',$management->run($op->id,$driver)->state,'The lost response leaves the send uncertain');
same([],\Paymenter\Extensions\Servers\Domains\Services\ManagementReadRecovery::latest($op->fresh()),'No completed registrar read exists yet');
$noRequestNotice($op,'Uncertain send before a registrar read');
// A failed preparation is held without sending and retries by itself.
[$d,$driver]=$holding(placeholderComFixtureName('request-alert-retry-placeholder.com'));
$state->fault='GetDomainInfo';$op=$submit($d,$driver,'lock',['locked'=>false]);
same('held',$management->run($op->id,$driver)->state,'Failed preparation holds the management request');
expect(\Paymenter\Extensions\Servers\Domains\Services\ManagementEvidence::recoverable($op->fresh()),'The held preparation remains automatically recoverable');
$noRequestNotice($op,'Recoverable held request');
// Acquisition state changes have their own alerts and no management policy.
[$d,$driver,$plain]=$holding(placeholderComFixtureName('request-alert-acquisition-placeholder.com'));
expect(!isset($plain->policy['management']),'The acquisition control has no management policy');
$plain->update(['state'=>'uncertain']);expect($plain->wasChanged('state'),'The control actually changes state');
$noRequestNotice($plain,'Changed acquisition without management policy');
// An unconfirmed write after its read: Under review.
[$d,$driver]=$holding(placeholderComFixtureName('request-alert-unknown-placeholder.com'));
$state->fault='SetRegLock';$op=$submit($d,$driver,'lock',['locked'=>false]);
same('uncertain',$management->run($op->id,$driver)->state,'Fixture: the lock change is unconfirmed after its read');
same([['Transfer lock for '.$d->domain.' is under review.',$link($op)]],$notices($viewer,'Registrar request needs a check'),'Staff get one notice that links to the request');
same([],$notices($blind,'Registrar request needs a check'),'Staff who cannot open requests get no notice');
$state->fault='';$clock->instant=$clock->now()->modify('+16 minutes');Cache::flush();
same('uncertain',$management->reconcile($op->id,$driver)->state,'Fixture: a later read still cannot confirm it');
same(1,count($notices($viewer,'Registrar request needs a check')),'A later read of the same request does not notify again');
// A registrar refusal: Failed.
[$d2,$driver]=$holding(placeholderComFixtureName('request-alert-refused-placeholder.com'));
$state->fault='refused';$refused=$submit($d2,$driver,'lock',['locked'=>false]);
same('rejected',$management->run($refused->id,$driver)->state,'Fixture: the registrar refuses the change');
same([['Transfer lock for '.$d2->domain.' failed.',$link($refused)]],$notices($viewer,'Registrar request failed'),'A failed request notifies once with its link');
$nativeSuccessful=true;echo "Staff request alert passed.\n";
