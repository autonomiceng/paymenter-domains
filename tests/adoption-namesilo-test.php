<?php
// Synthetic, socket-free provider evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ExpiryPrecision};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver,NameSiloAdoptionReader};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport,Environment as WireEnvironment,RegistrarRequest,RequestContext,TransportResponse};
$clock=new class implements Clock {
    public DateTimeImmutable $time;
    public function __construct() {$this->time=new DateTimeImmutable('2026-11-02T12:00:00Z');}
    public function now(): DateTimeImmutable {return $this->time;}
};
$binding=new AccountBinding('namesilo',8,Environment::Test,'synthetic-import-account');
$wire=new class implements DeadlineTransport {
    public array $calls=[];
    public array $reply=['code'=>300,'detail'=>'success','created'=>'2020-01-01','expires'=>'2027-11-02','status'=>'Active',
        'auto_renew'=>'No','private'=>'Yes','locked'=>'Yes','nameservers'=>[
            ['nameserver'=>'NS1.EXAMPLE.NET','position'=>1],['nameserver'=>'NS2.EXAMPLE.NET','position'=>2]]];
    public ?Closure $during=null;
    public bool $fail=false;
    public function send(WireEnvironment $e,RequestContext $c,RegistrarRequest $r,string $key): TransportResponse
    {throw new LogicException('Import requires a deadline');}
    public function sendWithin(WireEnvironment $e,RequestContext $c,RegistrarRequest $r,string $key,int $timeout): TransportResponse
    {
        $this->calls[]=[$e,$c,$r->command,$r->parameters(),$timeout];
        if($this->during){($this->during)();}
        if($this->fail){throw new RuntimeException('placeholder-private-key');}
        return new TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>$this->reply]));
    }
};
$reader=new NameSiloAdoptionReader(new Driver(new Client($binding,'placeholder-private-key',$wire),clock:$clock),$clock);
$facts=$reader->read('example.com','com')->facts();
same(null,$facts['refusal'],'Active owned NameSilo domain can be imported');
same(null,$facts['provider_id'],'No opaque provider domain reference is invented');
same(['2027-11-02',ExpiryPrecision::Date,null,'namesilo_domain_info'],
    [$facts['expiry']->value,$facts['expiry']->precision,$facts['expiry']->timezone,$facts['expiry']->source],'Date precision/provenance retained');
same([false,true,false,false],[$facts['auto_renew'],$facts['ordinary'],$facts['registrar_hold'],$facts['redemption']],'Explicit active/off state retained');
same([WireEnvironment::Sandbox,RequestContext::Interactive,'getDomainInfo',['domain'=>'example.com'],25000],$wire->calls[0],'One exact-name bounded interactive read');
same(['privacy'=>true,'locked'=>true,'nameservers'=>['ns1.example.net','ns2.example.net']],$facts['settings'],'Complete observed flags and nameservers retained');
$original=$wire->reply;
foreach ([['auto_renew','Yes','auto_renew_on'],['auto_renew',null,'read_failed'],['auto_renew','unknown','read_failed'],
    ['status','Expired','state_unconfirmed'],['status','Redemption','state_unconfirmed'],['status','Pending','state_unconfirmed'],
    ['expires','2026-11-02','expired'],['expires','2027-02-30','expiry_unconfirmed'],['expires','2027-11-02T00:00:00Z','expiry_unconfirmed'],
    ['expires','2037-11-02','state_unconfirmed'],['nameservers',[],'state_unconfirmed'],['private',null,'read_failed']] as [$field,$value,$reason]) {
    $wire->reply=$original;$wire->reply[$field]=$value;$before=count($wire->calls);
    same($reason,$reader->read('example.com','com')->refusal,'Incomplete or unsafe evidence refuses: '.$field);
    same($before+1,count($wire->calls),'No retry or corrective send');
}
$wire->reply=$original;$wire->fail=true;$before=count($wire->calls);
same('read_failed',$reader->read('example.com','com')->refusal,'Lost read stays failed');
same($before+1,count($wire->calls),'Lost read is not retried');$wire->fail=false;
$wire->during=function()use($clock):void {$clock->time=$clock->time->modify('+90 seconds');};
same('read_failed',$reader->read('example.com','com')->refusal,'Whole read deadline is enforced');$wire->during=null;
$before=count($wire->calls);
try {$reader->read('example.com','net');expect(false,'Wrong suffix refuses');}catch(InvalidArgumentException){}
same($before,count($wire->calls),'Invalid target sends nothing');
$factory=NameSiloAdoptionReader::factory(static fn($b)=>new Driver(new Client($b,'placeholder-private-key',$wire),clock:$clock));
same($binding->fingerprint,$factory($binding,[],$clock)->binding()->fingerprint,'Registry factory preserves exact account');
$foreign=new AccountBinding('namesilo',9,Environment::Test,'foreign-account');
$badFactory=NameSiloAdoptionReader::factory(static fn()=>new Driver(new Client($foreign,'placeholder-private-key',$wire)));
try {$badFactory($binding,[],$clock);expect(false,'Foreign runtime refuses');}catch(InvalidArgumentException|DomainException){}
same($before,count($wire->calls),'Factory construction and mismatch perform no reads');
expect(!str_contains(json_encode($reader->read('example.com','com')),'example.com'),'Readback projection hides private domain facts');
echo "NameSilo import normalizes owned state, nullable reference and date precision without writes.\n";
