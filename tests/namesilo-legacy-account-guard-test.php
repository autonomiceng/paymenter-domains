<?php
// Retained legacy default-contact guard; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Legacy accounts cannot admit new offers. Exercise their retained guard directly, beside real funded native finance.
$fixtureNameSiloLocal=false;
require __DIR__.'/Support/operation-fixture.php';
require_once __DIR__.'/Support/namesilo-account-guard.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, NameSiloAccountGuard, ReadDeferred, WriteHeld};
$op=$new(placeholderComFixtureName('legacy-guard-finance-placeholder.com'));$finance=nativeFinancialSnapshot($op);
$binding=AccountBindings::binding(Account::where('server_id',$tlds[1]->server_id)->sole());
$server=App\Models\Server::findOrFail($binding->serverId);
$guard=(object)['email'=>'wrong@example.com','id'=>'900','default'=>'1','mode'=>'email','reads'=>0];
$exchange=static function($origin,$command,$params,$timeout)use($guard):string {
    same('https://ote.namesilo.com/apibatch',$origin,'Legacy guard uses the bounded background route');
    same(['contactList',[]],[$command,$params],'The guard sends only the default-contact read');
    expect($timeout>0&&$timeout<=25000,'Legacy guard read has a physical request cap');return nameSiloGuardWire($guard);
};
$context=static fn($limiter=null)=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock,$limiter);
$held=static function()use($binding,$context,$exchange,$op,$finance):void {
    try{NameSiloAccountGuard::check($binding,$context(),$exchange);expect(false,'Invalid legacy account must refuse');}
    catch(WriteHeld $error){same('namesilo_account_guard',$error->reason,'Legacy refusal retains the typed guard reason');}
    same($finance,nativeFinancialSnapshot($op),'Legacy guard refusal preserves all native and financial state');
    expect(!isset($op->fresh()->attempt['dispatch_attempted_at']),'Legacy guard creates no possible-send seal');
};
nameSiloGuardExpected($binding,'expected@example.com');
$handler=new class extends \Monolog\Handler\AbstractHandler {
    public array $warnings=[];public bool $fail=false;
    public function handle(\Monolog\LogRecord $record):bool {
        if($record->message!=='NameSilo account guard held a request.'){return false;}
        $this->warnings[]=['level'=>$record->level,'message'=>$record->message,'context'=>$record->context];
        if($this->fail){throw new RuntimeException('Synthetic private logger failure');}return true;
    }
};
$logger=logger()->getLogger();$logger->pushHandler($handler);
try{$held();$handler->fail=true;$guard->mode='failure';$held();}finally{$logger->popHandler();}
same(array_map(static fn($class)=>['level'=>\Monolog\Level::Warning,'message'=>'NameSilo account guard held a request.','context'=>['exception_class'=>$class]],[DomainException::class,RuntimeException::class]),$handler->warnings,'Warnings expose only exception classes even when the logger throws');
expect(!$server->settings()->where('key',NameSiloAccountGuard::PIN)->exists(),'Failed first checks record no pin');
$guard->email=' EXPECTED@example.com ';$guard->mode='singleton';NameSiloAccountGuard::check($binding,$context(),$exchange);
foreach(['email','id','no-default','failure','blank','malformed','duplicate','rejected','pin-email'] as $case){
    $guard->email='expected@example.com';$guard->id='900';$guard->default='1';$guard->mode=$case;
    nameSiloGuardExpected($binding,$case==='blank'?'  ':($case==='pin-email'?'new@example.com':'expected@example.com'));
    if($case==='email'){$guard->email='other@example.com';}if($case==='id'){$guard->id='901';}if($case==='no-default'){$guard->default='0';}if($case==='pin-email'){$guard->email='new@example.com';}
    $reads=$guard->reads;$held();same($case==='blank'?0:1,$guard->reads-$reads,'One guard read, none for blank settings: '.$case);
    $pin=$server->settings()->where('key',NameSiloAccountGuard::PIN)->sole();expect($pin->encrypted,'Legacy pin remains encrypted');
    expect(!str_contains((string)$pin->getRawOriginal('value'),'expected@example.com'),'Stored pin does not expose the contact email');
    same(['binding'=>$binding->fingerprint,'contact_id'=>'900','email'=>'expected@example.com'],json_decode($pin->value,true,8,JSON_THROW_ON_ERROR),'A refusal never replaces the first pin');
}
nameSiloGuardExpected($binding,' expected@EXAMPLE.com ');$guard->email=' EXPECTED@example.com ';$guard->id='900';$guard->default='1';$guard->mode='singleton';
$pin=$server->settings()->where('key',NameSiloAccountGuard::PIN)->sole()->getRawOriginal();$reads=$guard->reads;
withNameSiloGuardLimiter(0,static function($limiter)use($binding,$context,$exchange,$guard,$reads):void {
    try{NameSiloAccountGuard::check($binding,$context($limiter),$exchange);expect(false,'Exhausted guard capacity must defer');}catch(ReadDeferred){}
    same(1,$limiter->calls,'Capacity is checked before the legacy guard exchange');same($reads,$guard->reads,'No default-contact read without capacity');
    $limiter->remaining=1;NameSiloAccountGuard::check($binding,$context($limiter),$exchange);same($reads+1,$guard->reads,'Recovered capacity performs one fresh guard read');
});
same($pin,$server->settings()->where('key',NameSiloAccountGuard::PIN)->sole()->getRawOriginal(),'Deferral and recovery preserve the pin');
same($finance,nativeFinancialSnapshot($op),'Every guard outcome preserves native finance and holding state');
echo "Legacy NameSilo guard, logging, encrypted pin and capacity passed\n";$nativeSuccessful=true;
