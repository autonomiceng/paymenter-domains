<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Services\{HistoryRetention,RetentionSchema};
[$d,$invoice,$op]=$make(placeholderComFixtureName('retention-reminder-placeholder.com'));$enable();
expect($reminders->remind((int)$d->id),'Existing fixture produces an accepted reminder');
$receipt=DB::table('domains_reminder_deliveries')->sole();same('accepted',$receipt->state,'Actual native queue acceptance');
$money=DB::table('domains_operations')->orderBy('id')->get()->toJson();
$at=Carbon\CarbonImmutable::createFromTimestampUTC((int)$receipt->accepted_at)->addMonthsNoOverflow(13)->getTimestamp()+1;
if(is_file(dirname(__DIR__).'/Domains/Services/HistoryRetention.php')){HistoryRetention::tick($at);}
expect(!DB::table('domains_reminder_deliveries')->where('id',$receipt->id)->exists(),'Closed reminder receipt leaves active history after thirteen months');
expect(HistoryRetention::replayed('reminder',$receipt->delivery_key),'Reminder replay identity is separate from history');
expect(!$reminders->remind((int)$d->id),'Same reminder cannot be queued again after its receipt archives');
$copy=(array)$receipt;unset($copy['id']);$copy['state']='uncertain';$copy['accepted_at']=null;$copy['delivery_key']=str_repeat('f',64);
$uncertain=DB::table('domains_reminder_deliveries')->insertGetId($copy);
HistoryRetention::tick($at+8*366*86400);HistoryRetention::tick($at+8*366*86400);
expect(DB::table('domains_reminder_deliveries')->where('id',$uncertain)->exists(),'Uncertain reminder stays active beyond three and seven year boundaries');
expect(!$reminders->remind((int)$d->id),'Purging cold history still cannot replay the reminder');
same($money,DB::table('domains_operations')->orderBy('id')->get()->toJson(),'Financial operations stay byte-for-byte intact');
echo "Reminder receipts archive and expire without reopening delivery or deleting uncertain and financial evidence.\n";
$nativeSuccessful=true;
