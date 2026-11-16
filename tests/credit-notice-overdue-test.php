<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\DisputeRecoveries;

[$domain]=$make(placeholderComFixtureName('credit-notice-overdue-placeholder.com'),false);
$op=Operation::where('domain_id',$domain->id)->where('action','register')->sole();
$dispute='synthetic_notice_overdue';
$committed=$clock->now()->modify('-59 minutes');
$op->update(['funding'=>[...($op->funding??[]),'dispute_holds'=>[
    ['dispute_id'=>$dispute,'payment_id'=>1,'lost_minor'=>100,'owed_minor'=>100,'removed_minor'=>100,
        'remainder_minor'=>0,'credit_id'=>null,'at'=>$committed->format(DATE_ATOM)],
]]]);
DB::table('domains_obligation_alerts')->insert(['alert_key'=>DisputeRecoveries::key('account_money_hold',$dispute),
    'domain_id'=>$domain->id,'generation'=>$domain->authority_generation,'code'=>'account_money_hold',
    'operation_id'=>$op->id,'first_seen'=>$committed->getTimestamp(),'last_seen'=>$committed->getTimestamp()]);
// A paused account keeps delivery pending while reminder checks still evaluate its alerts.
Server::whereKey($domain->server_id)->update(['enabled'=>false]);
$delivery=static fn()=>DB::table('domains_reminder_deliveries')->where('delivery_key',hash('sha256',$domain->id.':recovery:'.$dispute));
$alerts=static fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','credit_notice_overdue');
same(false,$reminders->remind((int)$domain->id),'Paused account leaves the 59-minute notice undelivered');
same(0,$delivery()->count(),'No delivery recorded before the overdue threshold');
same(0,$alerts()->whereNull('resolved_at')->count(),'A 59-minute credit notice is on time');

$clock->instant=$clock->now()->modify('+2 minutes');
same(false,$reminders->remind((int)$domain->id),'Paused account leaves the 61-minute notice undelivered');
same(0,$delivery()->count(),'The overdue notice still has no recorded delivery');
same(1,$alerts()->whereNull('resolved_at')->count(),'An undelivered 61-minute credit notice raises an alert');

Server::whereKey($domain->server_id)->update(['enabled'=>true]);
same(true,$reminders->remind((int)$domain->id),'Resuming the account delivers the pending credit notice');
same('accepted',$delivery()->sole()->state,'The credit notice has a recorded delivery');
$reminders->remind((int)$domain->id);
same(0,$alerts()->whereNull('resolved_at')->count(),'A recorded delivery clears the overdue alert');
same($clock->now()->getTimestamp(),(int)$alerts()->sole()->resolved_at,'The overdue alert retains its resolution');
same(1,$delivery()->count(),'Repeated reminder checks do not duplicate the credit notice');
$nativeSuccessful=true;
