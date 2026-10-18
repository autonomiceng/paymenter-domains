<?php
// Synthetic offline fair catalogue draining; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport,TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,AccountReadBudget,CatalogueSync,DomainObservation,ObservationSchema,ObservationWork,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture());ObservationSchema::install();$clock=new FakeClock($now);$enom=$tlds[0]->server_id;$ns=$tlds[1]->server_id;
$state=(object)['calls'=>0,'fail'=>false,'during'=>null,'quarantine'=>false];
$wire=new class($state) implements DeadlineTransport {
    public function __construct(private object $s){}
    public function send($e,$c,$r,#[SensitiveParameter] string $key):TransportResponse{throw new LogicException;}
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $key,int $timeout):TransportResponse{
        same(0,DB::transactionLevel(),'Catalogue reads outside locks');same('getPrices',$r->command,'Catalogue named request');$this->s->calls++;
        if($this->s->during){$hook=$this->s->during;$this->s->during=null;$hook();}
        if($this->s->fail){throw new RuntimeException('Synthetic failure');}
        $row=['registration'=>'8.00','renew'=>'9.00','transfer'=>'8.00'];
        return new TransportResponse(200,json_encode(['request'=>['operation'=>'getPrices'],'reply'=>['code'=>300,'detail'=>'synthetic','com'=>$row,
            'net'=>$this->s->quarantine?array_diff_key($row,['registration'=>true]):$row,'org'=>$row]]));
    }
};
$registry=new RegistrarRegistry;$verifier=new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier{public function verify(Environment $e,array $c):string{return $c['token'];}};
$registry->register('namesilo',['token'],$verifier,fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),[],$clock));
$reads=new AccountBindings($registry);$b=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$ns)->sole());
$row=['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true];CatalogueAcceptances::save($b,1,['com'=>$row,'net'=>$row,'org'=>$row]);
$budget=new AccountReadBudget($clock);$observer=new DomainObservation($reads,$clock,$budget);$work=new ObservationWork($observer,$clock,$budget,new CatalogueSync($reads,$prices));
$work->enroll($enom);$work->enroll($ns);$bindings->setProcessing($ns,false);$work->tick(1);same(0,$state->calls,'Broken first account bounded');
$work->tick(1);same(1,$state->calls,'Second account refreshes while paused and not current seller');
$work->tick(2);same(1,$state->calls,'Catalogue due time durable');
$state->quarantine=true;
$clock->instant=$now->modify('+600 seconds');$work->tick(2);expect(Price::where('server_id',$ns)->where('tld','net')->exists(),'Next bounded suffix drains');
same(3,Price::where('server_id',$ns)->where('tld','net')->whereNull('wholesale')->count(),'Quarantined suffix has unknown cost');
same(null,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_hold'),'Persisted quarantine never holds healthy account refresh');
same(2,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_cursor'),'Quarantine advances durable cursor');
$clock->instant=$now->modify('+1200 seconds');$work->tick(2);expect(Price::where('server_id',$ns)->where('tld','org')->exists(),'Tail suffix drains within cadence');
$prior=DB::table('domains_prices')->where('server_id',$ns)->orderBy('id')->get()->toJson();$state->fail=true;
$clock->instant=$now->modify('+1800 seconds');$work->tick(2);same($prior,DB::table('domains_prices')->where('server_id',$ns)->orderBy('id')->get()->toJson(),'Failed refresh preserves price bytes/freshness');
$calls=$state->calls;$clock->instant=$now->modify('+1 day');(new ObservationWork($observer,$clock,$budget,new CatalogueSync($reads,$prices)))->tick(2);same($calls,$state->calls,'Vendor failure remains held across due time and restart');
$state->fail=false;$work->resumeCatalogue($ns,'staff_catalogue_resume');
DB::table('domains_read_budgets')->where('server_id',$ns)->update(['used'=>60,'window_at'=>$clock->now()->getTimestamp()*1000000]);$work->tick(2);same($calls,$state->calls,'Local quota denial sends nothing');
same(null,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_hold'),'Local admission denial never becomes vendor failure hold');
$clock->instant=$clock->now()->modify('+60 seconds');$work->tick(2);same($calls+1,$state->calls,'Local reset resumes without a second explicit authorization');
same(1,DB::table('domains_observation_queues')->where('server_id',$ns)->value('catalogue_resumes'),'Catalogue resume is bounded and audited');
$nativeSuccessful=true;echo "Fair bounded catalogue cursor and failure retention passed\n";
