<?php
// Enom 444f31c TP_GetOrder vocabulary; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/FakeRegistrar.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DispatchSeal, Environment, ExecutionRecord, Query, RequestBudget, RequestContext, RequestOrigin, SendState, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, ResponseParser};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\TransferState;
$b=new AccountBinding('enom',1,Environment::Test,'account-enom'); $now=new DateTimeImmutable('2026-10-18T12:06:00Z');
$a=new AttemptEvidence($b,new Query('x.com',Action::Transfer,1),'transfer:1',1,ExecutionRecord::Recorded,SendState::Sent,$now->modify('-6 minutes'),$now->modify('-1 minute'),seal:DispatchSeal::Sealed,transferReference:'7001');
$parse=static function($h,$d,$id='7001',$name='x',$errors='0',$extra=''){
    return (new ResponseParser)->parse(ReadCommand::Transfer,'<interface-response><Command>TP_GetOrder</Command><ErrCount>'.$errors.'</ErrCount><Done>true</Done><TransferOrder><TransferOrderID>'.$id.'</TransferOrderID><StatusID>'.$h.'</StatusID><TransferOrderDetail><SLD>'.$name.'</SLD><TLD>com</TLD><StatusID>'.$d.'</StatusID></TransferOrderDetail>'.$extra.'</TransferOrder></interface-response>');
};
same(TransferStatus::Completed,TransferState::read($a,$parse('3','5')),'Exact paired completion');
foreach (['2','4','6','7','8','15','16','17','18','19','20','21','22','23','24','25','26','27','30','31','32','33','34','36','37','45','101'] as $d) { same(TransferStatus::Rejected,TransferState::read($a,$parse('2',$d)),'Terminal failed detail'); }
foreach (['0','1','4','5'] as $h) { foreach (['0','1','3','9','10','11','12','13','14','28','29','35','100'] as $d) { same(TransferStatus::Pending,TransferState::read($a,$parse($h,$d)),'Pending pair including draft/100'); } }
foreach ([['3','32'],['2','5'],['1','32'],['3','100'],['999','5'],['3','999']] as [$h,$d]) { same(TransferStatus::Unknown,TransferState::read($a,$parse($h,$d)),'Contradictory/unknown pair unresolved'); }
foreach ([$parse('3','5','7002'),$parse('3','5','7001','other'),$parse('3','5','7001','x','1'),$parse('3','5','7001','x','0','<TransferOrderDetail><SLD>x</SLD><TLD>com</TLD><StatusID>5</StatusID></TransferOrderDetail>')] as $reply) { same(TransferStatus::Unknown,TransferState::read($a,$reply),'Wrong ID/name/error/multiple unresolved'); }
// NameSilo vendor facts use exact strings. Owned Active is never transfer status.
$state=(object)['status'=>'Pending at Registry'];
$wire=new class($state) implements Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\DeadlineTransport {
    public function __construct(private $s){}
    public function send($e,$c,$r,#[SensitiveParameter] string $k): Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse { throw new LogicException; }
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $k,int $t): Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse {
        $reply=['code'=>300,'detail'=>'synthetic','date'=>'2026-10-18','status'=>$this->s->status,'message'=>'synthetic'];
        if ($r->command==='getDomainInfo') { $reply+=['created'=>'2020-01-01','expires'=>'2028-01-01','auto_renew'=>'No','private'=>'No','locked'=>'Yes']; $reply['status']='Active'; }
        return new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>$reply]));
    }
};
$b=new AccountBinding('namesilo',2,Environment::Test,'account-namesilo');
$driver=new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client($b,'synthetic',$wire));
$a=new AttemptEvidence($b,new Query('x.com',Action::Transfer,1),'transfer:2',1,ExecutionRecord::Recorded,SendState::Sent,$now->modify('-6 minutes'),$now->modify('-1 minute'),seal:DispatchSeal::Sealed);
// NameSilo transfer status list (account popup, captured 2026-10-18); status strings only.
$official=file(__DIR__.'/fixtures/namesilo-transfer-statuses.txt',FILE_IGNORE_NEW_LINES);
same(28,count($official),'Exactly 28 official NameSilo statuses');
same(28,count(array_unique($official)),'Official statuses are unique');
$statuses = ['Transfer Completed'=>TransferStatus::Completed];
foreach (['Retrieving Administrative Contact Email','Retrieving Administrative Contact Email (2)',
    'Pending Reply from Administrative Contact','Transfer Accepted','Approved','Approved (Auto)',
    'Checking Domain Status','Submitting Transfer Request to Registry','Pending at Registry'] as $status) { $statuses[$status]=TransferStatus::Pending; }
foreach (['Missing Authorization Code','Incorrect Authorization Code','Domain is Locked',
    'Wait Authorization Code','Domain is Private'] as $status) { $statuses[$status]=TransferStatus::NeedsAction; }
foreach (['Transfer Rejected','Registry Transfer Request Failed','Registrar Rejected','On Hold - Created in last 60 days',
    'On Hold - Transferred in last 60 days','Registry Rejected','Domain Transferred Elsewhere','User Cancelled',
    'Domain has a pendingDelete status','Domain has a pendingTransfer status'] as $status) { $statuses[$status]=TransferStatus::Rejected; }
// Deliberately unresolved vendor vocabulary, not missing mappings.
foreach (['Transfer Timed Out','Time Out','Transfer price changed'] as $status) { $statuses[$status]=TransferStatus::Unknown; }
$expectedNames=array_keys($statuses); sort($expectedNames); $fixtureNames=$official; sort($fixtureNames);
same($expectedNames,$fixtureNames,'Every official status has exactly one decided class');
foreach ($official as $status) {
    $expected=$statuses[$status];
    same($expected,Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TransferState::read($status),
        'NameSilo mapping: '.$status);
    foreach ([$status, strtolower($status), ' '.$status, $status.' ', $status.' extra'] as $variant) {
        $state->status=$variant; $ctx=new RequestContext($b,RequestOrigin::Background,$now->modify('+250 seconds'),new RequestBudget(10),new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now));
        same($variant===$status?$expected:TransferStatus::Unknown,$driver->reconcile($a,$ctx)->result()->evidence()->transfer,'Exact NameSilo vocabulary only');
    }
}
foreach (['Completed','Failed','Cancelled','Active','',null,0,[],false] as $status) {
    $state->status=$status; $ctx=new RequestContext($b,RequestOrigin::Background,$now->modify('+250 seconds'),new RequestBudget(10),new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now));
    same(TransferStatus::Unknown,$driver->reconcile($a,$ctx)->result()->evidence()->transfer,'No invented NameSilo terminal vocabulary');
}
echo "Transfer status vocabulary and contradictory evidence refusals passed.\n";
