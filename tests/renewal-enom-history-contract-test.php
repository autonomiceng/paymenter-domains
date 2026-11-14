<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, RegistrarRegistry, RenewalCompletion};

// Keep the normal funded/sealed renewal and its lost reply; replace only recovery reads.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-history-contract-placeholder.com'));
[$engine,$writer]=$compose($b); $state->lost=true; $writes=$state->writes;
same('uncertain',$engine->run($op->id,$writer)->state,'The original renewal reply is lost');
$sent=new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']);
$state->expiry='2028-10-18'; $clock->instant=$sent->modify('+10 minutes');
HistoryAcceptances::save(new HistoryAcceptance($b,'America/Los_Angeles',0,86400,'enom_renewal_v1'));
$scenario=(object)['fault'=>'','forms'=>[]];
$wire=new class($transport,$scenario,$state,$sent) implements Transport {
    public function __construct(private Transport $domain, private object $scenario, private object $state, private DateTimeImmutable $sent) {}
    public function send($env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        $this->scenario->forms[]=[$command,$parameters];
        if (!in_array($command,[ReadCommand::Orders,ReadCommand::Order],true)) { return $this->domain->send($env,$command,$parameters,$uid,$token,$timeoutMs); }
        if ($command===ReadCommand::Orders) {
            $rows=[];
            for ($i=0;$i<81;$i++) {
                $offset=match($i){
                    0=>'+1 second',
                    1=>in_array($this->scenario->fault,['ambiguous','duplicate'],true)?'+1 second':'-17 minutes',
                    2=>'-10 minutes',
                    default=>'-3 days',
                };
                $rows[]=['id'=>(string)(901+$i),'date'=>$this->sent->modify($offset)->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A')];
            }
            if ($this->scenario->fault==='missing') { $rows[0]['date']=$rows[3]['date']; }
            if ($this->scenario->fault==='page_missing'&&$parameters['Start']===26) { return '<broken>'; }
            return enomHistoryEnvelope(enomHistoryPage(array_slice($rows,$parameters['Start']-1,25),$parameters['Start'],count($rows)));
        }
        $registration=$parameters['OrderID']==='902'&&!in_array($this->scenario->fault,['ambiguous','duplicate'],true);
        $offset=$parameters['OrderID']==='903'?'-10 minutes':($registration?'-17 minutes':'+1 second');
        $placed=$this->sent->modify($offset)->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        $bill=($this->scenario->fault==='ambiguous'&&$parameters['OrderID']==='902')
            ||($this->scenario->fault==='unknown_registration_date'&&$registration)
            ||($this->scenario->fault==='unknown_older_renewal_date'&&$parameters['OrderID']==='903')?'':$placed;
        $body=enomHistoryDetail($parameters['OrderID'],$this->state->name,$bill,$parameters['OrderID']==='901'?'2028-10-18':'2027-10-18','8.00',1,$placed);
        if($registration){$body=str_replace('<ProductType>Renew</ProductType>','<ProductType>Register</ProductType>',$body);}
        if($this->scenario->fault==='unsupported_registration'&&$registration){$body=str_replace('<AmountPaid>8.00</AmountPaid>','<AmountPaid>invalid</AmountPaid>',$body);}
        return $body;
    }
};
$registry=new RegistrarRegistry;
$registry->register('enom',['token'],$verify,fn($binding,$credentials)=>new Driver(new Client($binding,'account-enom',$credentials['token'],$wire),['com']));
$complete=new RenewalCompletion(new AccountBindings($registry),$clock); $money=$nativeMoney();
foreach (['page_missing','missing','ambiguous','duplicate','unknown_registration_date','unknown_older_renewal_date','unsupported_registration',''] as $fault) {
    $scenario->fault=$fault; $scenario->forms=[]; Cache::flush();
    // Each scenario gives the same order IDs different details. A completed Enom order does not change, so each starts its own scan.
    $op->fresh()->update(['policy'=>array_diff_key($op->fresh()->policy,['history_scan'=>true])]);
    $complete->run($op->id);
    same($fault===''?'succeeded':'uncertain',$op->fresh()->state,'A recent registration and older renewal permit only the unique original renewal to settle: '.$fault);
    same($money,$nativeMoney(),'History recovery preserves invoices, payments and credits');
    same($writes+1,$state->writes,'History recovery never sends another renewal');
    if ($fault!=='') { same(null,$op->fresh()->cost['actual']??null,'Unresolved history records no charge'); }
}
same([1,26,51,76],array_column(array_column(array_values(array_filter($scenario->forms,fn($r)=>$r[0]===ReadCommand::Orders)),1),'Start'),'Renewal reads every list page');
same(3,count(array_filter($scenario->forms,fn($r)=>$r[0]===ReadCommand::Order)),'Recent same-domain registration and older renewal are validated alongside the matching renewal');
same(11,count($scenario->forms),'Four holding reads and seven history reads stay within the 25-read budget');
same(['amount'=>'8.00','currency'=>'USD'],$op->fresh()->cost['actual'],'Confirmed actual charge retained');
same('accepted_history',$op->fresh()->attempt['reconciliation']['source'],'Lost reply settles from accepted history');
same('2028-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'Confirmed renewal advances expiry');

// A renewal stuck before 30-day TEST history: five used reads, the saved 24-hour contract, and a receipt day too dense for one turn.
[$stuck,$d2,$b2]=$makeRenewal(placeholderComFixtureName('renew-history-stuck-placeholder.com'));
[$engine,$writer]=$compose($b2); $state->lost=true; $writes=$state->writes;
same('uncertain',$engine->run($stuck->id,$writer)->state,'The second renewal reply is lost');
$sent=new DateTimeImmutable($stuck->fresh()->attempt['dispatch_attempted_at']); $state->expiry='2028-10-18';
$stuck->fresh()->update(['policy'=>[...$stuck->fresh()->policy,'renewal_polls'=>5]]);
$dense=new class($transport,$state,$sent) implements Transport {
    public array $forms=[];
    public function __construct(private Transport $domain, private object $state, private DateTimeImmutable $sent) {}
    public function send($env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        if (!in_array($command,[ReadCommand::Orders,ReadCommand::Order],true)) { return $this->domain->send($env,$command,$parameters,$uid,$token,$timeoutMs); }
        $this->forms[]=$command; $at=fn($i)=>$this->sent->modify($i===0?'+1 second':'-'.(10*$i).' minutes')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        if ($command===ReadCommand::Orders) {
            $rows=array_map(fn($i)=>['id'=>(string)(7001+$i),'date'=>$at($i)],range(0,29));
            return enomHistoryEnvelope(enomHistoryPage(array_slice($rows,$parameters['Start']-1,25),$parameters['Start'],count($rows)));
        }
        $i=(int)$parameters['OrderID']-7001;
        $body=enomHistoryDetail($parameters['OrderID'],$i===0?$this->state->name:'other-'.$i.'-placeholder.com',$at($i),'2028-10-18','8.00',1,$at($i));
        return $i===0?$body:str_replace('<ProductType>Renew</ProductType>','<ProductType>Register</ProductType>',$body);
    }
};
$denseRegistry=new RegistrarRegistry;
$denseRegistry->register('enom',['token'],$verify,fn($binding,$credentials)=>new Driver(new Client($binding,'account-enom',$credentials['token'],$dense),['com']));
$recover=new RenewalCompletion(new AccountBindings($denseRegistry),$clock); $money=$nativeMoney();
// TEST holds new renewal invoices, so the environment is set once the renewal is already in flight.
config(['domains.enom_identity.environment'=>'test']);
$clock->instant=$sent->modify('+2 days'); Cache::flush();
same('uncertain',$recover->run($stuck->id)->state,'The 24-hour contract no longer covers the two-day-old reply');
same([],$dense->forms,'An uncovered lost reply reads no history');
\Paymenter\Extensions\Servers\Domains\Registrars\Enom\TestOrderHistory::upgrade(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$b2->serverId)->sole());
same([2592000,0],[HistoryAcceptances::load($b2,\Paymenter\Extensions\Servers\Domains\Data\Action::Renew)->retentionSeconds,HistoryAcceptances::load($b2,\Paymenter\Extensions\Servers\Domains\Data\Action::Renew)->lagSeconds],'The saved TEST contract is raised to 30 days and keeps its delay');
Cache::flush(); $first=$recover->run($stuck->id)->fresh();
same(['uncertain',0,5,21],[$first->state,$first->policy['renewal_polls'],$first->policy['history_resumption']['prior_polls'],count($dense->forms)],'The new contract resumes the exhausted reads once and the first turn reads both pages and 19 orders');
same(['resolved'=>19,'complete'=>false],array_intersect_key($first->policy['history_scan']['state'],['resolved'=>1,'complete'=>1]),'Scan progress is saved in the operation policy');
same($clock->now()->getTimestamp()+60,(int)\Illuminate\Support\Facades\DB::table('domains_runtime_operations')->where('operation_id',$stuck->id)->value('due_at'),'The scan continues on the next turn');
$first->update(['policy'=>[...$first->policy,'renewal_polls'=>5]]); $dense->forms=[]; Cache::flush();
$again=$recover->run($stuck->id)->fresh();
same([[],$first->policy['history_resumption']],[$dense->forms,$again->policy['history_resumption']],'The same contract never resumes the same attempt twice');
$again->update(['policy'=>[...$again->policy,'renewal_polls'=>0]]); $dense->forms=[]; Cache::flush();
same('succeeded',$recover->run($stuck->id)->state,'The second turn finishes the scan and completes the renewal');
same(14,count($dense->forms),'Both pages, the 11 remaining orders and the saved match by its ID');
same('accepted_history',$stuck->fresh()->attempt['reconciliation']['source'],'Completion comes from accepted history');
same([$writes+1,$money],[$state->writes,$nativeMoney()],'Only the original lost Extend was sent; invoices, payments and credits are unchanged');
config(['domains.enom_identity.environment'=>null]);
$nativeSuccessful=true; echo "Captured Enom history recovers a lost paid renewal without replay or money changes.\n";
