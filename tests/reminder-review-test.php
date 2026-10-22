<?php
// Saved-evidence and alert regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
use App\Models\{NotificationTemplate,ServiceCancellation};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,ReminderTemplates};
[$d]=$make(placeholderComFixtureName('disabled-alert-placeholder.com'),false);$d->update(['provider_expires_at'=>null]);
same(false,$reminders->remind($d->id),'Disabled delivery still inspects');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','unknown_expiry')->exists(),'Disabled schedule gets expiry alert');
$enable();[$d]=$make(placeholderComFixtureName('binding-drift-placeholder.com'));
$q=DB::table('domains_reminder_schedules')->where('server_id',$d->server_id);$old=(clone $q)->first();$q->update(['account_fingerprint'=>str_repeat('b',64)]);
same(false,$reminders->remind($d->id),'Binding drift blocks customer notice');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','schedule_binding')->exists(),'Binding drift alerts staff');
expect(in_array($d->id,ReminderSchedules::next($clock->now()->getTimestamp()),true),'Inspection cursor sees current holdings despite drift');$q->update(['account_fingerprint'=>$old->account_fingerprint]);
[$d]=$make(placeholderComFixtureName('complimentary-notice-placeholder.com'),false);DB::table('domains_domains')->where('id',$d->id)->update(['price_mode'=>'complimentary']);
$clock->instant=new DateTimeImmutable('2027-09-18T12:00:00Z');expect($reminders->remind($d->id),'Complimentary month-out has no invoice promise');
$clock->instant=new DateTimeImmutable('2027-10-05T12:00:00Z');same(false,$reminders->remind($d->id),'No false invoice alert for complimentary pricing');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','missing_obligation')->count(),'Complimentary invoice is not expected');
$clock->instant=new DateTimeImmutable('2027-10-11T12:00:00Z');$reminders->remind($d->id);
expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','missing_execution')->exists(),'Missing complimentary execution is visible at due date');
[$d]=$make(placeholderComFixtureName('failure-retained-placeholder.com'));$template=NotificationTemplate::where('key','domains_domain_expiring')->sole();$body=$template->body;
$template->update(['body'=>'{{ $unknown->property }}']);same(false,$reminders->remind($d->id),'Render error has no handoff');
$clock->instant=$clock->now()->modify('+3 days');$reminders->remind($d->id);
expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->whereNull('resolved_at')->exists(),'Unsent failure does not resolve outside its window');
$template->update(['body'=>$body]);$clock->instant=$clock->now()->modify('-3 days');expect($reminders->remind($d->id),'Fixed template retries');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->whereNull('resolved_at')->count(),'Accepted notice resolves prior rendering failure');
[$d]=$make(placeholderComFixtureName('issued-calendar-placeholder.com'));$setting=$d->service->product->server->settings()->updateOrCreate(['key'=>'domains_issue_days'],['value'=>'invalid','type'=>'string']);
expect($reminders->remind($d->id),'Issued invoice keeps immutable calendar despite later malformed settings');$setting->delete();
[$d]=$make(placeholderComFixtureName('missing-template-placeholder.com'));$template->delete();same(false,$reminders->remind($d->id),'Missing template never becomes a preference suppression');
same(0,DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->count(),'No claim for absent template');ReminderTemplates::ensure();expect($reminders->remind($d->id),'Restored template remains retryable');
[$d,$invoice,$op]=$make(placeholderComFixtureName('retired-unpaid-expiry-placeholder.com'));(new \Paymenter\Extensions\Servers\Domains\Services\RenewalCycles($bindings,$clock))->retireUnpaid($op->id);
$clock->instant=new DateTimeImmutable('2027-10-21T12:00:00Z');expect($reminders->remind($d->id),'Retired unpaid cycle still gets expiry notice');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','renewal_unpaid')->exists(),'Retired unpaid debt has an accurate label');
[$d]=$make(placeholderComFixtureName('overdue-reminder-placeholder.com'));$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');expect($reminders->remind($d->id),'Overdue invoice still has safe notice');
expect(\Illuminate\Support\Facades\Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->data['overdue'],'One-day notice never asks customer to meet a past due date');
// Future-only stop preserves the existing invoice reminder and its original execution path.
[$d]=$make(placeholderComFixtureName('issued-stop-reminder-placeholder.com'));ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Stop future cycles']);
expect($reminders->remind($d->id),'Issued debt reminder continues under end-of-period stop');
same(true,\Illuminate\Support\Facades\Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->data['invoice'],'Stopped holding reminder still links retained debt');
// An existing invoice must not promise a renewal that current authority refuses.
foreach(['cancelled','paused','anchor','revision'] as $case){
    [$d]=$make('invoice-authority-'.$case.'.com');
    if($case==='cancelled'){ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'immediate','reason'=>'Synthetic request']);}
    if($case==='paused'){$d->update(['renewal_policy'=>[...$d->renewal_policy,'automatic_spend_paused'=>true]]);}
    if($case==='anchor'){$p=$d->renewal_policy;unset($p['billing_anchor']);$d->update(['renewal_policy'=>$p]);}
    if($case==='revision'){$d->update(['confirmed_revision'=>$d->confirmed_revision+1]);}
    same(false,$reminders->remind($d->id),'No misleading invoice payment promise: '.$case);
    expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','held_obligation')->exists(),'Current authority refusal alerts staff: '.$case);
}
// A recorded privacy credit must not wait behind an accepted renewal window.
[$d,$invoice,$renew]=$make(placeholderComFixtureName('credit-during-renewal-placeholder.com'));expect($reminders->remind($d->id),'Renewal window already accepted');
$credit=\App\Models\Credit::firstOrCreate(['user_id'=>$d->user_id,'currency_code'=>'USD'],['amount'=>'12.00']);
$privacy=\Paymenter\Extensions\Servers\Domains\Models\Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,
    'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'privacy-credit-notice',
    'action'=>'privacy_purchase','term'=>1,'funding_basis'=>'paid_invoice','state'=>'rejected','amount'=>'12.00','currency'=>'USD','payload'=>[],
    'funding'=>['refund'=>['to'=>'account_credit','credit_id'=>$credit->id,'currency'=>'USD','amount'=>'12.00','at'=>$clock->now()->format(DATE_ATOM)]]]);
expect($reminders->remind($d->id),'Recorded credit sends during an already accepted renewal window');
same(false,$reminders->remind($d->id),'Credit remains idempotent');
$clock->instant=$clock->now()->modify('+3 days');$reminders->remind($d->id);
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','credit_notice_overdue')->count(),'Accepted credit never becomes overdue');
[$d]=$make(placeholderComFixtureName('expired-without-invoice-placeholder.com'),false);$clock->instant=new DateTimeImmutable('2027-10-21T12:00:00Z');
expect($reminders->remind($d->id),'Expiry notice needs no live invoice or price promise');
[$d]=$make(placeholderComFixtureName('complimentary-off-placeholder.com'),false);DB::table('domains_domains')->where('id',$d->id)->update(['price_mode'=>'complimentary']);
ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Synthetic request']);expect($reminders->remind($d->id),'Complimentary cancellation still receives renewal-off notice');
same(false,\Illuminate\Support\Facades\Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->data['complimentary'],'Renewal-off takes precedence over complimentary pricing');
echo "Independent inspection, account drift, complimentary notices, retained failures and template recovery passed.\n";$nativeSuccessful=true;
