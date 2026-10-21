<?php
// Native payment matrix through genuine bridges and offline read evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{CostUnit,Environment,QuoteEvidence};
use Paymenter\Extensions\Servers\Domains\Services\{Rdap,RegistrarRegistry,TransferPrecheck};
$writer=(object)['calls'=>0];
$paidRegistry=new RegistrarRegistry;
$paidRegistry->register('enom',['token'],new class implements AccountVerifier {
    public function verify(Environment $environment,array $credentials):string{return $credentials['token'];}
},static function($binding)use($clock,$preflight){
    $op=Paymenter\Extensions\Servers\Domains\Models\Operation::where('server_id',$binding->serverId)->where('state','running')->sole();
    $query=Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op,$op->domain);
    $quote=new QuoteEvidence($query,new Paymenter\Extensions\Servers\Domains\Data\Money($op->action==='transfer'?'7.00':'6.00','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
    return new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,new Paymenter\Extensions\Servers\Domains\Data\Capabilities($binding,[]),
        new Paymenter\Extensions\Servers\Domains\Data\DriverResult($binding,Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,
            Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new Paymenter\Extensions\Servers\Domains\Data\Evidence(quote:$quote,balance:new Paymenter\Extensions\Servers\Domains\Data\Money('100.00','USD'),available:true,premium:!$preflight->supported)));
});
$paidBindings=new Paymenter\Extensions\Servers\Domains\Services\AccountBindings($paidRegistry);
$paidExchange=static function($origin,$command,$params,$timeout)use($writer):never {
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Native payment exchange outside locks');
    expect(in_array($command,['Purchase','TP_CreateOrder'],true),'Only the intended acquisition command');
    expect($timeout>0&&$timeout<=25000,'Native payment bounded acquisition');$writer->calls++;
    throw new RuntimeException('Synthetic response lost after send');
};
$paidPrecheck=new TransferPrecheck(new Rdap(static function($url){
    return ['status'=>200,'body'=>json_encode(str_contains($url,'dns.json')?['services'=>[[['com'],['https://rdap.example.com/']]]]:
        ['objectClassName'=>'domain','ldhName'=>basename($url),'status'=>['active'],'events'=>[
            ['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];
}));
