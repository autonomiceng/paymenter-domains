<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native app, no network.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
require __DIR__.'/Support/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,AttemptEvidence,Disposition,DriverResult,Evidence,Money,Query,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{BalanceReads,BalanceSchema,CatalogueAcceptances,EnomTestCallLimit,FoundationSchema,ReadDeferred};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
FoundationSchema::install();BalanceSchema::install();
fixtureAccount(1,'enom','synthetic-recording-a');fixtureAccount(2,'enom','synthetic-recording-b');
$binding=CatalogueAcceptances::binding(Account::where('server_id',1)->sole());
$other=CatalogueAcceptances::binding(Account::where('server_id',2)->sole());
$clock=new FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z'));
$row=static fn($b)=>(array)DB::table(BalanceSchema::TABLE)->where('server_id',$b->serverId)->where('account_fingerprint',$b->fingerprint)->sole();
$context=static fn($b)=>new RequestContext($b,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock);
BalanceReads::record($other,new Money('77.00','USD'),$clock->now(),'manual');$otherBefore=$row($other);
foreach([
    ['accepted without a new request',false,new Money('8.25','USD'),null,true],
    ['accepted physical read',true,new Money('8.25','USD'),null,true],
    ['consumed malformed reply',true,null,null,true],
    ['unconsumed refusal',false,null,null,false],
    ['consumed exception',true,null,new RuntimeException('Synthetic read failure'),true],
    ['unconsumed exception',false,null,new RuntimeException('Synthetic local failure'),false],
    ['consumed deferral',true,null,new ReadDeferred('Synthetic deferral'),false],
    ['consumed TEST cap',true,null,new EnomTestCallLimit,false],
]as[$label,$consumed,$money,$error,$recorded]){
    BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'manual');$before=$row($binding);
    $clock->instant=$clock->now()->modify('+1 second');$c=$context($binding);$remaining=$c->budget->remaining();
    if($consumed){$c->claimRead($binding);}same($consumed?0:1,$c->budget->remaining(),$label.' reaches the intended budget boundary');
    BalanceReads::recordRead($binding,$c,$remaining,'preflight',$money,$error);$after=$row($binding);
    if(!$recorded){same($before,$after,$label.' preserves the entire successful row');}
    else{
        same($money?'8.25':'7.99',$after['amount'],$label.' records money or retains the last amount');same('USD',$after['currency'],$label.' retains currency');
        same('preflight',$after['source'],$label.' records attempted source');same($clock->now()->getTimestamp(),(int)$after['attempted_at'],$label.' uses context epoch seconds');
        same($money?$clock->now()->getTimestamp():(int)$before['read_at'],(int)$after['read_at'],$label.' updates only successful read time');
        same($money?null:'read_failed',$after['last_error_code'],$label.' records the expected failure category');
    }
    same($otherBefore,$row($other),$label.' retains the other account');
}
// An accepted cached result remains recordable without consuming another provider request.
$result=new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(balance:new Money('9.50','USD')));
$cached=new class($binding,$result) implements Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver {
    public int $calls=0;
    public function __construct(private AccountBinding $account,private DriverResult $result){}
    public function binding():AccountBinding{return $this->account;}
    public function balance(RequestContext $c):DriverResult{$this->calls++;return $this->result;}
    public function accountCapabilities(RequestContext $c):never{throw new LogicException('Unused');}
    public function quote(Query $q,RequestContext $c):never{throw new LogicException('Unused');}
    public function availability(Query $q,RequestContext $c):never{throw new LogicException('Unused');}
    public function readDomain(string $name,RequestContext $c):never{throw new LogicException('Unused');}
    public function readRenewalDomain(string $name,RequestContext $c):never{throw new LogicException('Unused');}
    public function renewalAssessment(Query $q,RequestContext $c):never{throw new LogicException('Unused');}
    public function reconcile(AttemptEvidence $a,RequestContext $c):never{throw new LogicException('Unused');}
};
$clock->instant=$clock->now()->modify('+1 second');$c=$context($binding);
expect(BalanceReads::read($cached,$c,'renewal')===$result,'Recording returns the exact accepted cached result');
same(1,$cached->calls,'Cached balance is requested once');same(1,$c->budget->remaining(),'Cached result consumes no new request');
same('9.50',$row($binding)['amount'],'Accepted cached money is recorded');same('renewal',$row($binding)['source'],'Cached recording retains caller source');
same($clock->now()->getTimestamp(),(int)$row($binding)['read_at'],'Cached recording uses the context clock');same($otherBefore,$row($other),'Cached recording retains the other account');
echo "Shared balance recording preserves budget, refusal, cached result and account boundaries.\n";$nativeSuccessful=true;
