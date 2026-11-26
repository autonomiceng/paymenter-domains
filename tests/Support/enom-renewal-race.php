<?php
// Overlapping installed processes, before issuance/admission; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,DomainBilling,NativeDomainGuard,RuntimePorts,RuntimeWork};
$data=json_decode(file_get_contents($receipt.'.renewal'),true,512,JSON_THROW_ON_ERROR);$worker=(int)getenv('PACKAGE_RACE_WORKER');
$clock=new class implements Clock {public string $day='2027-09-30';public function now():DateTimeImmutable{return new DateTimeImmutable($this->day.'T12:00:00Z');}};
app()->instance(Clock::class,$clock);config(['domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000,'settings.credits_auto_use'=>false]);
$wait=static function(string $path):void{$until=microtime(true)+20;while(!is_file($path)&&microtime(true)<$until){usleep(25000);}expect(is_file($path),'Competing process reached its barrier');};
if($phase==='enom-test-renewal-race-issue'){
    file_put_contents($receipt.'.issue-ready-'.$worker,'ready');$wait($receipt.'.issue-ready-'.(3-$worker));
    $billing=new DomainBilling(app(AccountBindings::class),app(NativeDomainGuard::class),$clock,catalogue: $GLOBALS['caps']??null);$invoice=null;$deferred=false;
    // SQLite can refuse a competing deferred transaction's write upgrade. Retry only that local contention.
    for($i=0;$i<20;$i++){
        try{
            if(!$deferred){
                $invoice=$billing->invoice($data['issuance_domain']);
                if($invoice===null){
                    $domain=Domain::findOrFail($data['issuance_domain']);
                    expect(DB::table('domains_money_work')->where('server_id',$domain->server_id)->where('kind',DomainBilling::KIND)->where('subject_id',$domain->id)->exists(),'Deferred issuance retains queued renewal work');
                    $deferred=true;
                }
            }
            // Exhausted money decisions resume through their queued account work.
            if($deferred){
                $billing->sweep(Account::where('server_id',$domain->server_id)->sole());
                $invoice=App\Models\Invoice::find(Operation::where('domain_id',$domain->id)->where('action','renew')->value('invoice_id'));
            }
            if($invoice!==null){break;}
            usleep(50000);
        }
        catch(PDOException $e){if(!str_contains(strtolower($e->getMessage()),'locked')){throw $e;}usleep(50000);}
    }
    expect($invoice!==null,'Competing issuance resolves');file_put_contents($receipt.'.issue-'.$worker,(string)$invoice->id);
    same(1,Operation::where('domain_id',$data['issuance_domain'])->where('action','renew')->count(),'Competing issuance retains one cycle obligation');
}elseif($phase==='enom-test-renewal-race-admit'){
    $clock->day='2027-10-20';$op=Operation::findOrFail($data['race_operation']);
    $s=(object)['name'=>$op->domain->domain,'after'=>false,'auto'=>'0','expiry'=>'10/21/2027 5:00:00 AM','day'=>'10/21/2027','price'=>'10.00','order'=>'9002','confirmed'=>'2028-10-21','charge'=>'10.00','orders'=>[],'bill'=>''];
    require __DIR__.'/enom-renewal-wire.php';Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$renewReply]);Cache::flush();
    if($worker===1){
        DB::table('domains_read_budgets')->where('server_id',$op->server_id)->delete();
        $GLOBALS['enomWire']['during']=static function(array $p)use($receipt,$wait):void{
            if($p['Command']==='GetDomainInfo'){$GLOBALS['enomWire']['during']=null;file_put_contents($receipt.'.admission-ready','ready');$wait($receipt.'.admission-competed');}
        };
    }else{$wait($receipt.'.admission-ready');}
    (new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
    if($worker===2){same([],$GLOBALS['enomWire']['forms'],'Competing admission sees retained claim before any send');same(false,\Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt::of($op->fresh())->sealed(),'Competition occurs before the seal');file_put_contents($receipt.'.admission-competed','done');}
    file_put_contents($receipt.'.admit-'.$worker,json_encode(array_column($GLOBALS['enomWire']['forms'],'Command'),JSON_THROW_ON_ERROR));
}else{
    same(file_get_contents($receipt.'.issue-1'),file_get_contents($receipt.'.issue-2'),'Overlapping issuance workers return the same debt');
    $commands=[...json_decode(file_get_contents($receipt.'.admit-1'),true),...json_decode(file_get_contents($receipt.'.admit-2'),true)];
    same(13,count($commands),'One competing admission performs two assessments and the write');same(1,count(array_filter($commands,static fn($c)=>$c==='Extend')),'Overlapping admission sends one Extend');
    same('pending',Operation::findOrFail($data['race_operation'])->state,'One sealed outcome retained');
    same(1,Operation::where('domain_id',$data['issuance_domain'])->where('action','renew')->count(),'One retained issued cycle');
    same(count($data['money'][0])+1,App\Models\Invoice::count(),'Overlapping issuance creates exactly one invoice');
    same(0,DB::table('domains_money_work')->where('kind',DomainBilling::KIND)->where('subject_id',$data['issuance_domain'])->count(),'Deferred issuance work is swept');
    same($data['money'][2],DB::table('invoice_transactions')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),'Competing issuance adds no payment transactions');
    same($data['money'][3],DB::table('credits')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),'Competing issuance keeps credits unchanged');
    echo "Installed overlapping issuance/admission workers retain one obligation and one Extend.\n";
}
