<?php
// Enom444f31c reminders scenarios plus neutral/fair safety regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reminders.php';
use App\Models\{NotificationTemplate,ServiceCancellation};
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,ReminderSchema,ReminderSchedules,ReminderTemplates};
[$d,$invoice,$op]=$make(placeholderComFixtureName('neutral-reminder-placeholder.com'),true,3);
same(false,$reminders->remind((int)$d->id),'Enrollment defaults off');Mail::assertNothingOutgoing();
$enable();expect($reminders->remind((int)$d->id),'Known multi-year invoice sends');
Mail::assertQueued(DomainNotice::class,fn($mail)=>$mail->data['years']===3&&$mail->data['domain']===$d->domain&&$mail->data['invoice']===true);
same(false,$reminders->remind((int)$d->id),'One notice per domain/generation/expiry/window');
$clock->instant=$clock->now()->modify('+2 days');same(false,$reminders->remind((int)$d->id),'No duplicate catch-up');
$marker=DB::table('domains_reminder_deliveries')->sole();same('accepted',$marker->state,'Queue acceptance distinct from delivery');
// Template upgrades and schedule enrollment retain staff edits and history.
$template=NotificationTemplate::where('key','domains_domain_expiring')->sole();$template->update(['subject'=>'Customized {{ $domain }}']);
ReminderTemplates::ensure();same('Customized {{ $domain }}',$template->fresh()->subject,'Custom template survives upgrade');
// Only exact previous defaults change; each content field and staff setting is independent.
require_once __DIR__.'/Support/reminder-defaults.php';
$previous=previousReminderDefaults();
$current=ReminderTemplates::definitions()['domains_domain_expiring'];
foreach(['defaults','custom-body','custom-app','near-default'] as $case){
    $values=$previous;
    if($case==='custom-body'){$values['body']='Custom email';}
    if($case==='custom-app'){$values['in_app_body']='Custom app';}
    if($case==='near-default'){$values=array_map(fn($value)=>$value."\n",$values);}
    $template->forceFill([...$values,'subject'=>'Custom subject','enabled'=>false,
        'mail_enabled'=>'never','in_app_enabled'=>'never','in_app_title'=>'Custom title','in_app_url'=>'/custom',
        'edit_preference_message'=>'Custom preference'])->save();
    $before=$template->fresh()->getAttributes();
    ReminderTemplates::ensure();$after=$template->fresh()->getAttributes();
    foreach(['body','in_app_body'] as $field){
        same($values[$field]===$previous[$field]?$current[$field]:$values[$field],$after[$field],$case.' preserves or updates '.$field);
        unset($before[$field],$after[$field]);
    }
    unset($before['updated_at'],$after['updated_at']);
    same($before,$after,'Template upgrade retains every other attribute: '.$case);
    $stable=NotificationTemplate::orderBy('id')->get()->map->getAttributes()->all();
    ReminderTemplates::ensure();
    same($stable,NotificationTemplate::orderBy('id')->get()->map->getAttributes()->all(),'Repeated template upgrade is unchanged: '.$case);
}
$template->forceFill(array_intersect_key($current,$template->getAttributes()))->save();
$siblings=NotificationTemplate::where('key','!=','domains_domain_expiring')->orderBy('id')->get();
foreach($siblings as $sibling){$sibling->update(['body'=>'Custom sibling email','in_app_body'=>'Custom sibling app']);}
$before=$siblings->map(fn($row)=>$row->fresh()->getAttributes())->all();ReminderTemplates::ensure();
same($before,$siblings->map(fn($row)=>$row->fresh()->getAttributes())->all(),'Other managed templates retain custom content and settings');

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
    [$x,$bill,$renew]=$make(placeholderComFixtureName('unknown-'.$case.'-placeholder.com'),$case!=='missing');
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
// Owner Off sends expiry information without promising scheduled renewal or a new invoice.
[$ownerOff]=$make(placeholderComFixtureName('owner-off-reminder-placeholder.com'),false);$ownerOff->update(['renewal_policy'=>[...$ownerOff->renewal_policy,'owner_auto_renew'=>false]]);
$enable(0,'30,7','3');$clock->instant=new DateTimeImmutable('2027-09-18T12:00:00Z');
expect($reminders->remind($ownerOff->id),'Owner Off still receives expiry information');
Mail::assertQueued(DomainNotice::class,fn($mail)=>$mail->data['domain']===$ownerOff->domain&&!$mail->data['invoice']&&$mail->data['invoice_on']===null&&!$mail->data['complimentary']);
[$ownerIssued,$ownerInvoice]=$make(placeholderComFixtureName('owner-off-issued-reminder-placeholder.com'));$ownerIssued->update(['renewal_policy'=>[...$ownerIssued->renewal_policy,'owner_auto_renew'=>false]]);
expect($reminders->remind($ownerIssued->id),'Owner Off preserves issued invoice reminder');
Mail::assertQueued(DomainNotice::class,fn($mail)=>$mail->data['domain']===$ownerIssued->domain&&$mail->data['invoice']&&$mail->data['invoice_url']===route('invoices.show',$ownerInvoice));
// A failed render has no send claim and can retry safely after a staff template correction.
[$x]=$make(placeholderComFixtureName('render-failure-placeholder.com'));$template->update(['body'=>'{{ $missing->property }}']);
same(false,$reminders->remind((int)$x->id),'Bad staff template cannot be marked sent');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$x->id)->where('code','template_failed')->exists(),'Render problem visible');
$template->update(['body'=>ReminderTemplates::definitions()['domains_domain_expiring']['body']]);
expect($reminders->remind((int)$x->id),'Corrected template retries before queue handoff');
// Two-day catch-up, never three; closest window wins without backfilling older offsets.
foreach([1=>true,2=>true,3=>false] as $late=>$expected){[$x]=$make(placeholderComFixtureName('catchup-'.$late.'-placeholder.com'));$clock->instant=$clock->now()->modify('+'.$late.' days');same($expected,$reminders->remind((int)$x->id),'Bounded catch-up day '.$late);}
// Retained per-obligation margin alert survives replacement of the latest health projection.
[$x,$bill,$renew]=$make(placeholderComFixtureName('retained-margin-placeholder.com'));
$x->update(['observation'=>[]]);DB::table('domains_operations')->where('id',$renew->id)->update(['policy'=>encrypt(json_encode([...$renew->policy,'price'=>[...$renew->policy['price'],'staff_alert'=>'below_cost','retained'=>false]]),false)]);
expect($reminders->remind((int)$x->id),'Below-cost invoice is never blocked by margin');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$renew->id)->where('code','below_cost')->exists(),'Original operation margin alert retained');
// Fair durable cursors: a broken first account still gives the other its next turn.
$enable(1);$clock->instant=new DateTimeImmutable('2027-10-11T12:00:00Z');
$a=ReminderSchedules::next($clock->now()->getTimestamp(),1);$b=ReminderSchedules::next($clock->now()->getTimestamp(),1);
$turns=DB::table('domains_reminder_schedules')->orderBy('server_id')->pluck('turn')->all();expect($turns[0]>0&&$turns[1]>0,'Both accounts take independent durable turns');
same([],ReminderSchedules::next($clock->now()->getTimestamp()),'Five-minute cadence bounds repeat sweeps');
// Exhausted saved observation work escalates from retained phase evidence, once per holding.
[$x]=$make(placeholderComFixtureName('observation-exhausted-placeholder.com'));$follow=$x->observation['followup'];
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
