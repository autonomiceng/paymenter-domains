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
