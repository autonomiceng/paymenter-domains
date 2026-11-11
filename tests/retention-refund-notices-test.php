<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';
require __DIR__.'/Support/reminder-mail.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryRetention,ReminderSchedules,Reminders,RetentionSchema};

$op=$newTransfer(placeholderComFixtureName('retention-refund-placeholder.com'),0,true,false);
ReminderSchedules::enroll($op->server_id);
$reminders=new Reminders($clock,$bindings);
$clock->instant=$now->modify('+30 days');$inputs->expireMissingCode($op->id);
expect($reminders->remind($op->domain_id),'Initial account credit notice is accepted');
$first=DB::table('domains_reminder_deliveries')->where('domain_id',$op->domain_id)->sole();
same('accepted',$first->state,'Initial refund handoff completed');
same(hash('sha256',$op->domain_id.':refund:'.$op->id),$first->delivery_key,'Initial refund identity');

$clock->instant=Carbon\CarbonImmutable::createFromTimestampUTC((int)$first->accepted_at)->addMonthsNoOverflow(13)->addSecond()->toDateTimeImmutable();
HistoryRetention::tick($clock->now()->getTimestamp());
expect(!DB::table('domains_reminder_deliveries')->where('id',$first->id)->exists(),'Initial refund receipt leaves active history');
expect(HistoryRetention::replayed('reminder',$first->delivery_key),'Initial refund identity remains remembered');
expect(DB::table(RetentionSchema::COLD)->where('family','reminders')->where('source_id',$first->id)->exists(),'Initial refund receipt is in cold storage');

$credited=$op->fresh();$credited->update(['funding'=>[...$credited->funding,'late_credits'=>[
    [...$credited->funding['refund'],'amount'=>'1.00','at'=>$clock->now()->format(DATE_ATOM)],
]]]);
$queued=Mail::queued(DomainNotice::class)->count();
expect($reminders->remind($op->domain_id),'A later credit is notified after the initial refund receipt archives');
same($queued+1,Mail::queued(DomainNotice::class)->count(),'Exactly one later credit notice is queued');
$second=DB::table('domains_reminder_deliveries')->where('domain_id',$op->domain_id)->sole();
same(['accepted',hash('sha256',$op->domain_id.':refund:'.$op->id.':0')],[$second->state,$second->delivery_key],'Later credit has its own accepted identity');
same(false,$reminders->remind($op->domain_id),'Later credit notice does not repeat');

$clock->instant=Carbon\CarbonImmutable::createFromTimestampUTC((int)$second->accepted_at)->addYearsNoOverflow(4)->toDateTimeImmutable();
HistoryRetention::tick($clock->now()->getTimestamp());HistoryRetention::tick($clock->now()->getTimestamp());
same(0,DB::table('domains_reminder_deliveries')->where('domain_id',$op->domain_id)->count(),'Both refund receipts leave active storage');
same(0,DB::table(RetentionSchema::COLD)->where('family','reminders')->whereIn('source_id',[$first->id,$second->id])->count(),'Both cold refund receipts expire');
foreach([$first,$second] as $receipt){expect(HistoryRetention::replayed('reminder',$receipt->delivery_key),'Each refund identity survives cold expiry');}
same(false,(new Reminders($clock,$bindings))->remind($op->domain_id),'Neither credit notice replays after cold expiry and restart');
same($queued+1,Mail::queued(DomainNotice::class)->count(),'Cold expiry queues no duplicate credit notices');
echo "Archived refund receipts allow later credit notices without replay after cold expiry.\n";
$nativeSuccessful=true;
