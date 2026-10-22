<?php
// Synthetic native transfer deadlines, credit notices and channel semantics. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/reminder-mail.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
$createTransfer = $newTransfer;
$newTransfer = static function (string $name, int $i = 0, bool $paid = true, bool $code = true) use ($createTransfer) {
    return $createTransfer(placeholderComFixtureName($name), $i, $paid, $code);
};
use App\Models\{EmailLog,Notification,NotificationTemplate};
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,Reminders,TransferInputs};
$staff=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY]);
foreach($tlds as $t){ReminderSchedules::enroll($t->server_id);ReminderSchedules::save($staff,$t->server_id,1,true,'60,30,7,1','3','transfer_test');}
$reminders=new Reminders($clock,$bindings);$renders=[];
foreach([0,1] as $i){
    $clock->instant=$now;$op=$newTransfer('transfer-notice-placeholder-'.$i.'.com',$i,true,false);$d=$op->domain;if($i===1){$op->update(['state'=>'held','safe_code'=>'contact_details_required']);}
    expect($reminders->remind($d->id),'Paid transfer with missing code is notified for each holder');
    same(false,$reminders->remind($d->id),'Paid notice deduplicates');
    $mail=Mail::queued(DomainNotice::class)->last();same('domains_transfer_code_paid',$mail->emailTemplate->key,'Initial paid template');
    $renders[]=str_replace([$d->domain,url('/domains/'.$d->id.'/transfer-code')],['example.com','https://example.test/domain'],$mail->render());
    $clock->instant=$now->modify('+23 days');expect($reminders->remind($d->id),'Final-week notice');
    same(false,$reminders->remind($d->id),'Final-week notice deduplicates');
    $clock->instant=$now->modify('+30 days');same(false,$reminders->remind($d->id),'No code request at cutoff');
    $inputs->expireMissingCode($op->id);config(['settings.mail_disable'=>true]);
    $queued=Mail::queued(DomainNotice::class)->count();$appCount=Notification::where('user_id',$d->user_id)->count();
    same(false,$reminders->remind($d->id),'Forced credit mail cannot fall back to app-only acceptance');
    same(0,DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->where('template','domains_domain_order_refunded')->count(),'Unavailable forced credit mail consumes no delivery identity');
    expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->whereNull('resolved_at')->exists(),'Unavailable forced credit mail alerts staff');
    same($queued,Mail::queued(DomainNotice::class)->count(),'Unavailable credit mail queues nothing');
    same($appCount,Notification::where('user_id',$d->user_id)->count(),'Credit app notice waits for mandatory mail');
    config(['settings.mail_disable'=>false]);expect($reminders->remind($d->id),'Only actual account credit is notified');
    same($queued+1,Mail::queued(DomainNotice::class)->count(),'Restored forced credit mail queues exactly once');
    same('accepted',DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->where('template','domains_domain_order_refunded')->sole()->state,'Restored credit claim is accepted');
    same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->whereNull('resolved_at')->count(),'Accepted credit resolves unavailable-mail alert');
    same(false,$reminders->remind($d->id),'Credit notice deduplicates');
    $last=Mail::queued(DomainNotice::class)->last();same('domains_domain_order_refunded',$last->emailTemplate->key,'Account credit wording');
    expect(!str_contains($last->render(),'registry did not accept'),'No invented registry rejection');
}
same($renders[0],$renders[1],'Identical customer message for both holders');
// Native USD formatting belongs to the administrator, including separators and both affixes.
$currency = App\Models\Currency::where('code', 'USD')->sole();
$currencyFormat = $currency->only(['format', 'prefix', 'suffix']);
$tld = $tlds[0]; $rule = $tld->only(['use_default_rule', 'pricing_mode', 'fixed_register', 'fixed_renew', 'fixed_transfer']);
try {
    $currency->update(['format' => '1.000,00', 'prefix' => 'US$', 'suffix' => 'USD']);
    $tld->update(['use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => '20.00', 'fixed_renew' => '20.00', 'fixed_transfer' => '1234.56']);
    $prices->republish($tld->fresh()); $native->publish($tld->id, $now);
    $clock->instant = $now; $op = $newTransfer(placeholderComFixtureName('custom-native-money-placeholder.com'), 0, true, false);
    expect($reminders->remind($op->domain_id), 'M1 custom-format paid transfer queues its real notice');
    $mail = Mail::queued(DomainNotice::class)->last();
    same(['domains_transfer_code_paid', true], [$mail->emailTemplate->key,
        str_contains(html_entity_decode(strip_tags($mail->render()), ENT_QUOTES | ENT_HTML5), 'US$1.234,56USD')],
        'M1 real queued transfer template uses exact native USD format');
    $queued = Mail::queued(DomainNotice::class)->count();
    same([false, $queued], [$reminders->remind($op->domain_id), Mail::queued(DomainNotice::class)->count()], 'M1 formatted notice remains deduplicated');
} finally {
    $currency->update($currencyFormat); $tld->update($rule);
    $prices->republish($tld->fresh()); $native->publish($tld->id, $now);
}
$clock->instant=$now;$op=$newTransfer(placeholderComFixtureName('unknown-payment-time-placeholder.com'),0,true,false);
$p=$op->policy;unset($p['paid_at']);DB::table('domains_operations')->where('id',$op->id)->update(['policy'=>encrypt(json_encode($p),false)]);
same(false,$reminders->remind($op->domain_id),'Unknown payment timestamp never guesses deadline');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','unknown_payment_time')->exists(),'Payment time staff alert');
$op=$newTransfer(placeholderComFixtureName('already-has-code-placeholder.com'),0,true,true);same(false,$reminders->remind($op->domain_id),'No missing-code email after valid code');
// Temporary mail unavailability never consumes a mandatory notice.
$op=$newTransfer(placeholderComFixtureName('mail-unavailable-placeholder.com'),0,true,false);config(['settings.mail_disable'=>true]);
same(false,$reminders->remind($op->domain_id),'Unavailable forced channel alerts without a claim');
same(0,DB::table('domains_reminder_deliveries')->where('domain_id',$op->domain_id)->count(),'Temporary unavailability is retryable');
config(['settings.mail_disable'=>false]);expect($reminders->remind($op->domain_id),'Restored forced channel can send');
$op=$newTransfer(placeholderComFixtureName('historical-credit-placeholder.com'),0,true,false);$clock->instant=$now->modify('+30 days');$inputs->expireMissingCode($op->id);
$clock->instant=$now->modify('+33 days');same(false,$reminders->remind($op->domain_id),'Old credit cannot backfill customer mail');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','credit_notice_overdue')->exists(),'Overdue credit is staff-visible');
$clock->instant=$now;
// Uncertain handoff is durable and cannot replay, even after a process restart.
$op=$newTransfer(placeholderComFixtureName('uncertain-mail-placeholder.com'),0,true,false);$before=Mail::queued(DomainNotice::class)->count();
EmailLog::creating(static function(){throw new RuntimeException('Synthetic queue boundary failure');});
same(false,$reminders->remind($op->domain_id),'Handoff failure returns safely');
same(false,(new Reminders($clock,$bindings))->remind($op->domain_id),'Restart never resends uncertain handoff');
same($before,Mail::queued(DomainNotice::class)->count(),'No replay after handoff failure');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','delivery_uncertain')->exists(),'Handoff uncertainty visible');
same(0,count($state->calls),'No provider writes from reminders');same(0,$state->reads,'No provider reads from reminders');
echo "Both holders: native paid/code cutoff, actual credit notices, neutral content and uncertain handoff passed.\n";$nativeSuccessful=true;
