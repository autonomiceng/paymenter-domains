<?php
// Enom444f31c reminders scenarios plus neutral/fair safety regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
use App\Models\{NotificationTemplate,ServiceCancellation};
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,ReminderSchema,ReminderSchedules,ReminderTemplates};
[$d,$invoice,$op]=$make('neutral-reminder.com',true,3);
same(false,$reminders->remind((int)$d->id),'Enrollment defaults off');Mail::assertNothingOutgoing();
$enable();expect($reminders->remind((int)$d->id),'Known multi-year invoice sends');
Mail::assertQueued(DomainNotice::class,fn($mail)=>$mail->data['years']===3&&$mail->data['domain']===$d->domain&&$mail->data['invoice']===true);
same(false,$reminders->remind((int)$d->id),'One notice per domain/generation/expiry/window');
$clock->instant=$clock->now()->modify('+2 days');same(false,$reminders->remind((int)$d->id),'No duplicate catch-up');
$marker=DB::table('domains_reminder_deliveries')->sole();same('accepted',$marker->state,'Queue acceptance distinct from delivery');
// Create-only template upgrades and schedule enrollment retain staff edits and history.
$template=NotificationTemplate::where('key','domains_domain_expiring')->sole();$template->update(['subject'=>'Customized {{ $domain }}']);
ReminderTemplates::ensure();same('Customized {{ $domain }}',$template->fresh()->subject,'Custom template survives upgrade');
$row=DB::table('domains_reminder_schedules')->where('server_id',$d->server_id)->first();ReminderSchedules::enroll((int)$d->server_id);
same((array)$row,(array)DB::table('domains_reminder_schedules')->where('server_id',$d->server_id)->first(),'Custom schedule survives upgrade');
same(1,count(decrypt($row->audit)),'Schedule change has retained actor/reason/before/after audit');
commerceReject(fn()=>ReminderSchedules::save($staff,(int)$d->server_id,1,true,'7','3','test_stale'));
foreach([str_repeat('é',4),str_repeat('é',501),"valid\xff"] as $bad){commerceReject(fn()=>ReminderSchedules::save($staff,(int)$d->server_id,$row->revision,true,'7','3',$bad));}
ReminderSchedules::save($staff,(int)$d->server_id,$row->revision,true,'7','3',str_repeat('🦦',500));
$unicodeRow=DB::table('domains_reminder_schedules')->where('server_id',$d->server_id)->first();$unicodeAudit=decrypt($unicodeRow->audit);same(str_repeat('🦦',500),end($unicodeAudit)['reason'],'Schedule accepts 500 Unicode characters');
commerceReject(fn()=>ReminderSchedules::offsets('bogus,7'));ReminderSchema::install();ReminderSchema::verify();
// Unknowns and missing/held obligations create staff alerts without a customer guess.
foreach(['expiry','cost','price','held','missing'] as $case){
    [$x,$bill,$renew]=$make('unknown-'.$case.'.com',$case!=='missing');
    if($case==='expiry'){$x->update(['provider_expires_at'=>null]);}
    if($case==='held'){$renew->update(['state'=>'held']);}
    $rate=Price::where('server_id',$x->server_id)->where('action','renew')->sole();$original=$rate->getAttributes();
    if($case==='cost'){$rate->update(['revision'=>$rate->revision+1,'wholesale'=>null]);}if($case==='price'){$rate->update(['revision'=>$rate->revision+1,'retail'=>null]);}
    $before=Mail::queued(DomainNotice::class)->count();same(in_array($case,['cost','price'],true),$reminders->remind((int)$x->id),'Known issued invoice is independent of current quote/cost: '.$case);
    same($before+(in_array($case,['cost','price'],true)?1:0),Mail::queued(DomainNotice::class)->count(),'No customer guess for '.$case);
    $code=['expiry'=>'unknown_expiry','cost'=>'unknown_cost','price'=>'unknown_price','held'=>'held_obligation','missing'=>'missing_obligation'][$case];
    expect(DB::table('domains_obligation_alerts')->where('domain_id',$x->id)->where('code',$code)->exists(),'Durable staff alert: '.$case);
    DB::table('domains_prices')->where('id',$rate->id)->update($original);
}
// A failed render has no send claim and can retry safely after a staff template correction.
[$x]=$make('render-failure.com');$template->update(['body'=>'{{ $missing->property }}']);
same(false,$reminders->remind((int)$x->id),'Bad staff template cannot be marked sent');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$x->id)->where('code','template_failed')->exists(),'Render problem visible');
$template->update(['body'=>ReminderTemplates::definitions()['domains_domain_expiring']['body']]);
expect($reminders->remind((int)$x->id),'Corrected template retries before queue handoff');
// Two-day catch-up, never three; closest window wins without backfilling older offsets.
foreach([1=>true,2=>true,3=>false] as $late=>$expected){[$x]=$make('catchup-'.$late.'.com');$clock->instant=$clock->now()->modify('+'.$late.' days');same($expected,$reminders->remind((int)$x->id),'Bounded catch-up day '.$late);}
// Retained per-obligation margin alert survives replacement of the latest health projection.
[$x,$bill,$renew]=$make('retained-margin.com');
$x->update(['observation'=>[]]);DB::table('domains_operations')->where('id',$renew->id)->update(['policy'=>encrypt(json_encode([...$renew->policy,'price'=>[...$renew->policy['price'],'staff_alert'=>'below_cost','retained'=>false]]),false)]);
expect($reminders->remind((int)$x->id),'Below-cost invoice is never blocked by margin');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$renew->id)->where('code','below_cost')->exists(),'Original operation margin alert retained');
// Fair durable cursors: a broken first account still gives the other its next turn.
$enable(1);$clock->instant=new DateTimeImmutable('2027-10-11T12:00:00Z');
$a=ReminderSchedules::next($clock->now()->getTimestamp(),1);$b=ReminderSchedules::next($clock->now()->getTimestamp(),1);
$turns=DB::table('domains_reminder_schedules')->orderBy('server_id')->pluck('turn')->all();expect($turns[0]>0&&$turns[1]>0,'Both accounts take independent durable turns');
same([],ReminderSchedules::next($clock->now()->getTimestamp()),'Five-minute cadence bounds repeat sweeps');
// Exhausted saved observation work escalates from retained phase evidence, once per holding.
[$x]=$make('observation-exhausted.com');$follow=$x->observation['followup'];
DB::table('domains_observation_work')->insert(['server_id'=>$x->server_id,'account_fingerprint'=>$x->account_fingerprint,
    'domain_id'=>$x->id,'generation'=>$x->authority_generation,'phase'=>hash('sha256',$follow['operation_key']),
    'due_at'=>$clock->now()->getTimestamp(),'safe_code'=>'budget_exhausted']);
$reminders->remind($x->id);$reminders->remind($x->id);
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$x->id)->where('code','observation_review')->count(),'Once-only pending/read escalation');
// Offsets/customer data never contain registrar, account IDs, costs, payload or credentials.
foreach(Mail::queued(DomainNotice::class) as $mail){$rendered=$mail->render().view('domains::mail-text',$mail->content()->with)->render();
    expect(!preg_match('/enom|namesilo|PRIVATE-|account-enom|account-namesilo/i',$rendered),'Neutral safe HTML and text');}
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains',false),'No installed runtime');
echo "Neutral renewal reminders, unknown/held/missing alerts, retained customization and bounded catch-up passed.\n";$nativeSuccessful=true;
