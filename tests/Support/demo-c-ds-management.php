<?php
// Synthetic port exchanges only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, ManagementRequest, RequestBudget, RequestContext, RequestOrigin, Support};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{DsManagement, Management};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{ManagementInput, ManagementRead};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, ResponseParser};
expect(is_file(dirname(__DIR__, 2).'/Domains/Registrars/NameSilo/DsManagement.php'), 'Dormant DS management port exists');
$clock = new class implements Clock {
    public DateTimeImmutable $instant;
    public function __construct() { $this->instant=new DateTimeImmutable('2026-11-04T12:00:00+00:00'); }
    public function now(): DateTimeImmutable { return $this->instant; }
};
$binding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic-account');
$foreign = new AccountBinding('namesilo', 2, Environment::Test, 'synthetic-other');
$row=['key_tag'=>123, 'algorithm'=>13, 'digest_type'=>2, 'digest'=>str_repeat('AB',32)];
$sibling=array_replace($row, ['key_tag'=>456]);
$fact=['suffix'=>'example', 'input'=>'ds', 'algorithms'=>[13], 'digest_types'=>[2],
    'combinations'=>[['algorithm'=>13,'digest_type'=>2]], 'source'=>'synthetic-registry', 'version'=>'rule-1',
    'reviewed_at'=>'2026-11-01T00:00:00+00:00', 'valid_until'=>'2026-12-01T00:00:00+00:00', 'expiry_policy'=>'refuse'];
$map=['format'=>1,'entries'=>['example'=>$fact]];
$state=(object)['rows'=>[$sibling], 'fault'=>'', 'calls'=>[], 'during'=>null];
$exchange=static function($origin,$cmd,$p,$timeout)use($state):string {
    same('https://ote.namesilo.com/api', $origin, 'Injected test route only');
    same(['domain'=>'child.example'], $p, 'Every DS/ownership read binds exact domain');
    expect($timeout>0 && $timeout<=25000, 'Read deadline remains bounded');
    $state->calls[]=$cmd;
    if ($state->during) { $f=$state->during; $state->during=null; $f(); }
    $reply=['code'=>300, 'detail'=>'synthetic'];
    if ($cmd==='getDomainInfo') { $reply+=['created'=>'2020-01-01', 'expires'=>'2028-01-01', 'status'=>'Active']; }
    elseif ($cmd==='dnsSecListRecords') {
        if ($state->fault!=='missing') { $reply['ds_record']=$state->rows; }
        if ($state->fault==='partial') { $reply['more']=true; }
    } else { throw new RuntimeException('No mutation grant in read-only fixture'); }
    return json_encode(['request'=>['operation'=>$cmd], 'reply'=>$reply], JSON_THROW_ON_ERROR);
};
$reject=static function(callable $f):void { try { $f(); } catch (DomainException|InvalidArgumentException) { return; } throw new RuntimeException('Expected DS refusal'); };
$context=static fn($b=null)=>new RequestContext($b??$binding, RequestOrigin::Interactive, $clock->now()->modify('+1 minute'), new RequestBudget(10), $clock);
$driver=new DsManagement($binding,$exchange,$map,$clock);
$off=new DsManagement($binding,$exchange,[],$clock);
same(Support::Unsupported,$off->capabilities()->get('ds')->support,'Empty deployment stays Off');
same(Support::Unsupported,(new Management($binding,$exchange,$map))->capabilities()->get('ds')->support,'Old runtime adapter remains Off despite dormant facts');
same(null,$off->dsMutationPolicy('child.example'),'No default mutation snapshot');
same(['format'=>1,...$fact],$driver->dsMutationPolicy('child.example'),'Policy available to C1 for new requests');
$reject(fn()=>$off->validate('ds_add',['ds'=>$row],'child.example'));
same([],$state->calls,'Off fails before wire reads');
$request=new ManagementRequest($binding,'ds-request','child.example','ds_add',['ds'=>$row]);
$plan=$driver->plan($request,$context());
same([['action'=>'ds_add','input'=>['ds'=>$row]]],$plan['steps'],'One exact tuple gets one ordinary child grant');
same(['format'=>1,...$fact],$plan['preimage']['ds_policy'],'Complete policy frozen in encrypted sequence preimage');
$step=new ManagementRequest($binding,'ds-request','child.example','ds_add',['ds'=>$row],sequence:['preimage'=>$plan['preimage']]);
same(['ds'=>$row],$driver->prepare($step,$context()),'Fresh unchanged baseline prepares the exact step');
expect(!$driver->observe($step,$context())->confirmed,'Unchanged read is never add proof');
$state->rows=[$row,$sibling];
expect($driver->observe($step,$context())->confirmed,'Independent complete exact delta confirms add');
$state->rows=[$row];
expect(!$driver->observe($step,$context())->confirmed,'Sibling loss cannot complete');
$state->rows=[$sibling]; $state->fault='missing';
$reject(fn()=>$driver->plan($request,$context()));
$state->fault='partial'; $reject(fn()=>$driver->plan($request,$context()));
expect(!$driver->observe($step,$context())->confirmed,'Partial readback remains pending');
$state->fault=''; $state->rows=[$row];
$delete=new ManagementRequest($binding,'ds-delete','child.example','ds_delete',['ds'=>$row]);
$reject(fn()=>$driver->plan($delete,$context()));
$delete=new ManagementRequest($binding,'ds-delete','child.example','ds_delete',['ds'=>$row,'confirm_last_delete'=>true]);
$last=$driver->plan($delete,$context());
$delete=new ManagementRequest($binding,'ds-delete','child.example','ds_delete',$delete->payload(),sequence:['preimage'=>$last['preimage']]);
$state->rows=[];
expect($driver->observe($delete,$context())->confirmed,'Final deletion requires independently read explicit empty list');
$state->fault='missing'; expect(!$driver->observe($delete,$context())->confirmed,'Missing is never confirmed empty'); $state->fault='';
$clock->instant=new DateTimeImmutable($fact['valid_until']);
$state->rows=[$sibling]; $reject(fn()=>$driver->prepare($step,$context()));
$state->rows=[$row,$sibling]; expect($driver->observe($step,$context())->confirmed,'Expired policy cannot erase read-only resolution of an already sent step');
$clock->instant=new DateTimeImmutable('2026-11-04T12:00:00+00:00');
$changed=$map; $changed['entries']['example']['version']='rule-2';
$reject(fn()=>(new DsManagement($binding,$exchange,$changed,$clock))->prepare($step,$context()));
$reject(fn()=>$driver->observe($step,$context($foreign)));
$wrong=new ManagementRequest($foreign,'ds-request','child.example','ds_add',['ds'=>$row],sequence:['preimage'=>$plan['preimage']]);
$reject(fn()=>$driver->observe($wrong,$context()));
$state->rows=[$row,$sibling]; $reject(fn()=>$driver->prepare($step,$context()));
// The paired Enom DS parser and validation keep their existing contract without registry-map gating.
$xml=static fn($s)=>'<interface-response><Command>GetDnsSec</Command><ErrCount>0</ErrCount><Done>true</Done>'.$s.'</interface-response>';
$parser=new ResponseParser;
$empty='<success>true</success><DnsSecDataCount>0</DnsSecDataCount><DnsSecData><result><ResponseCode>200</ResponseCode></result></DnsSecData>';
same([],ManagementRead::records($parser->parse(ReadCommand::Ds,$xml($empty)),'ds'),'Enom explicit empty inventory remains readable');
$reject(fn()=>ManagementRead::records($parser->parse(ReadCommand::Ds,$xml('')),'ds'));
same(ManagementInput::ds($row),ManagementInput::validate('ds_add',['ds'=>$row],'child.example')['ds'],'Enom DS accepts its existing payload without new policy metadata');
echo "DS sequence port and paired Enom contract passed\n";
