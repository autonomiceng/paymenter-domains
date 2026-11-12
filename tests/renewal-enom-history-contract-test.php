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
                $matching=$i===0||($i===1&&in_array($this->scenario->fault,['ambiguous','duplicate'],true));
                $rows[]=['id'=>(string)(901+$i),'date'=>$this->sent->modify($matching?'+1 second':'-3 days')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A')];
            }
            if ($this->scenario->fault==='missing') { $rows[0]['date']=$rows[2]['date']; }
            if ($this->scenario->fault==='page_missing'&&$parameters['Start']===26) { return '<broken>'; }
            return enomHistoryEnvelope(enomHistoryPage(array_slice($rows,$parameters['Start']-1,25),$parameters['Start'],count($rows)));
        }
        $bill=$this->scenario->fault==='ambiguous'&&$parameters['OrderID']==='902'?'':$this->sent->modify('+1 second')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        return enomHistoryDetail($parameters['OrderID'],$this->state->name,$bill,'2028-10-18','8.00',1,$this->sent->modify('+1 second')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A'));
    }
};
$registry=new RegistrarRegistry;
$registry->register('enom',['token'],$verify,fn($binding,$credentials)=>new Driver(new Client($binding,'account-enom',$credentials['token'],$wire),['com']));
$complete=new RenewalCompletion(new AccountBindings($registry),$clock); $money=$nativeMoney();
foreach (['page_missing','missing','ambiguous','duplicate',''] as $fault) {
    $scenario->fault=$fault; $scenario->forms=[]; Cache::flush();
    $complete->run($op->id);
    same($fault===''?'succeeded':'uncertain',$op->fresh()->state,'Only complete unique history settles the paid renewal: '.$fault);
    same($money,$nativeMoney(),'History recovery preserves invoices, payments and credits');
    same($writes+1,$state->writes,'History recovery never sends another renewal');
    if ($fault!=='') { same(null,$op->fresh()->cost['actual']??null,'Unresolved history records no charge'); }
}
same([1,26,51,76],array_column(array_column(array_values(array_filter($scenario->forms,fn($r)=>$r[0]===ReadCommand::Orders)),1),'Start'),'Renewal reads every list page');
same(1,count(array_filter($scenario->forms,fn($r)=>$r[0]===ReadCommand::Order)),'81 list rows need one detail read');
same(9,count($scenario->forms),'Four holding reads and five history reads stay within the 25-read budget');
same(['amount'=>'8.00','currency'=>'USD'],$op->fresh()->cost['actual'],'Confirmed actual charge retained');
same('accepted_history',$op->fresh()->attempt['reconciliation']['source'],'Lost reply settles from accepted history');
same('2028-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'Confirmed renewal advances expiry');
$nativeSuccessful=true; echo "Captured Enom history recovers a lost paid renewal without replay or money changes.\n";
