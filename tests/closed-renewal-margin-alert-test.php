<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/reminder-mail.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Query};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,ObligationAlerts,ReminderSchedules,Reminders};

$belowCost=static function($d)use($ports):void {
    $b=AccountBindings::binding(Account::where('server_id',$d->server_id)->sole());
    $offer=$ports->readOffers(new Query($b,['com'],'renew'),Context::Staff)[0];
    $ports->apply(Edit::retainRenewal($b,'com','1.00'),$offer->revision);
};
foreach($tlds as $t){ReminderSchedules::enroll((int)$t->server_id);}
$reminders=new Reminders($clock,$bindings);
$sweep=static function()use($reminders):void {
    DB::table('domains_reminder_schedules')->update(['due_at'=>0,'cursor'=>0]);
    $reminders->sweep();
};
$alert=static fn($op)=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','below_cost');

// An unpaid, open renewal still alerts; a real completed loss keeps both existing alerts.
[$op,$d,$binding,$invoice]=$makeRenewal(placeholderComFixtureName('open-margin-placeholder.com'),false,beforeBill:$belowCost);
same('below_cost',$op->policy['price']['staff_alert'],'Invoice records the below-cost price');
$sweep();same(null,$alert($op)->sole()->resolved_at,'An open below-cost renewal alerts');
\App\Helpers\ExtensionHelper::addPayment($invoice,null,$op->amount);
[$engine,$writer,$completion]=$compose($binding);
same('pending',$engine->run($op->id,$writer)->state,'Paid below-cost renewal sends');
$state->expiry='2028-10-18';$state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Confirmed below-cost charge completes');
$op=$op->fresh();
same(['1.00','8.00','below_cost'],[$op->amount,$op->cost['actual']['amount'],$op->policy['renewal_execution']['reason']],'Completion retains the actual loss');
$sweep();same(1,$alert($op)->whereNull('resolved_at')->count(),'Completed below-cost renewal retains its margin alert');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','operation_review')->whereNull('resolved_at')->exists(),'Completed loss retains its execution review');
// A later renewal of the same domain, cancelled unpaid, does not inherit that loss on its own Cost row.
require_once __DIR__.'/Support/staff-panel.php';
Illuminate\Support\Facades\Auth::setUser(staffUser([Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::COSTS]));
$later=$op->replicate();
$later->fill(['operation_key'=>hash('sha256','later-cancelled-'.$op->id),'state'=>'rejected','safe_code'=>'renewal_cancelled_unpaid','cost'=>[],'provider_order_id'=>null,'invoice_id'=>null,'invoice_item_id'=>null,
    'started_at'=>null,'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent'],'policy'=>['price'=>$op->policy['price']]])->save();
ObligationAlerts::record($d->fresh(),'below_cost',$clock->now()->getTimestamp()-300,(int)$later->id);
$sweep();
$costSub=static fn($request)=>(string)Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($request->fresh())['cost_sub'];
expect(str_contains($costSub($op),ObligationAlerts::LABELS['below_cost']),'The completed loss keeps its Cost row warning');
expect(!str_contains($costSub($later),ObligationAlerts::LABELS['below_cost']),'The cancelled renewal has no margin warning of its own: '.$costSub($later));
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','below_cost')->whereNull('resolved_at')->count(),'Only the completed loss stays open');
// Price evidence that can't be read keeps the loss and its review open, though both were recorded at an earlier expiry.
$originalReview=DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','operation_review')->whereNull('resolved_at')->sole();
$policy=$op->fresh()->getRawOriginal('policy');$expiry=$d->fresh()->provider_expires_at;
DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>$expiry->addYear()->format('Y-m-d')]);
DB::table('domains_operations')->where('id',$op->id)->update(['policy'=>'unreadable-price-fixture']);
$moved=$d->fresh();$now=$clock->now()->getTimestamp();
ObligationAlerts::finish($moved,ObligationAlerts::retained(['domain'=>$moved,'operations'=>$moved->operations()->get()],$now),$now);
expect($alert($op)->whereNull('resolved_at')->exists(),'Unreadable price evidence keeps the open loss alert');
same(null,DB::table('domains_obligation_alerts')->where('id',$originalReview->id)->sole()->resolved_at,'Unreadable evidence keeps the original execution review open at its earlier expiry');
same(ObligationAlerts::UNKNOWN,ObligationAlerts::review($op->fresh(),$moved->operations()->get(),$moved,$now),'Unreadable succeeded renewal policy has an unknown review state');
expect(!ObligationAlerts::outdated($originalReview,$op->fresh(),$moved->operations()->get(),$moved),'Unreadable renewal policy cannot make the review outdated or interrupt inspection');
DB::table('domains_operations')->where('id',$op->id)->update(['policy'=>$policy]);DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>$expiry->format('Y-m-d')]);

// Cancelled unpaid, refused and other finished requests must settle an already-open margin alert.
foreach([['rejected','renewal_cancelled_unpaid'],['rejected','renewal_term_refused'],['disposed',null],['cancelled',null]] as $i=>[$status,$code]){
    [$closed,$domain,,$bill]=$makeRenewal(placeholderComFixtureName('closed-margin-'.$i.'-placeholder.com'),false,beforeBill:$belowCost);
    $seen=$clock->now()->getTimestamp()-300;
    ObligationAlerts::record($domain,'below_cost',$seen,(int)$closed->id);
    $bill->update(['status'=>'cancelled']);
    $closed->update(['state'=>$status,'safe_code'=>$code]);
    $sweep();$row=$alert($closed)->sole();
    same($clock->now()->getTimestamp(),$row->resolved_at,'Normal sweep resolves the closed request margin alert: '.$status.' '.$code);
    same($seen,$row->last_seen,'Closed request does not re-record the old invoice margin');
    $sweep();same((array)$row,(array)$alert($closed)->sole(),'Later sweeps keep the historical alert resolved');
}
$nativeSuccessful=true;echo "Closed renewal margin alerts settle; open and completed losses remain visible.\n";
