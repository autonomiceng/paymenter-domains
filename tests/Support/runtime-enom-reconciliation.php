<?php
// Synthetic installed charge recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{Action,Environment,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client,ReadCommand,Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,HistoryAcceptances,OperationClaims,OperationReconciliation,PricingRule,Refunds,RegistrarRegistry,RuntimePorts,RuntimeWork,StaffOperationReview,StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require_once __DIR__.'/staff-panel.php';
// These shapes repeat the existing synthetic history/holding fixtures. They do
// not establish a provider history contract or enable acceptance in a real app.
(static function()use($clock,$binding,$verify,$auth,$new,$worker,$reply,$factory,$account):void {
    same(null,HistoryAcceptances::load($binding),'Installed registration history acceptance defaults closed');
    same(null,HistoryAcceptances::load($binding,Action::Renew),'Renewal history stays independently closed');
    same(null,HistoryAcceptances::load($binding,Action::Transfer),'Transfer history stays independently closed');
    $s=(object)['name'=>'','bill'=>'','fault'=>'unique','calls'=>[],'drift'=>null];
    $wire=new class($s) implements Transport {
        public function __construct(private $s){}
        public function send(Environment $env,ReadCommand $command,array $p,string $uid,string $token,int $timeoutMs):string {
            same(0,DB::transactionLevel(),'Recovery provider reads run outside financial locks');
            $s=$this->s;$s->calls[]=$command->value;
            $body=match($command){
                ReadCommand::Info=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>account-enom</Belongs-To><Expiration>2027-10-18</Expiration></Status></GetDomainInfo>',
                ReadCommand::Expiry=>'<ExpirationDate>2027-10-18</ExpirationDate>',
                ReadCommand::Nameservers=>'<dns>ns1.provider.example</dns><dns>ns2.provider.example</dns>',
                ReadCommand::Renewal=>'<RenewName>0</RenewName>',ReadCommand::Lock=>'<Reg-Lock>1</Reg-Lock>',
                ReadCommand::Privacy=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
                ReadCommand::Orders=>'<OrderCount>'.($s->fault==='none'?'0':(in_array($s->fault,['duplicate','ambiguous'],true)?'2':'1')).'</OrderCount><OrderList>'.($s->fault==='none'?'':'<Order><OrderID>901</OrderID></Order>').(in_array($s->fault,['duplicate','ambiguous'],true)?'<Order><OrderID>902</OrderID></Order>':'').'</OrderList>',
                ReadCommand::Order=>'<Order><OrderID>'.$p['OrderID'].'</OrderID><Result>true</Result><OrderBillAmount>$99.00</OrderBillAmount>'.($s->fault==='ambiguous'&&$p['OrderID']==='902'?'':'<BillDate>'.$s->bill.'</BillDate>').'<OrderDetail><Description>'.($s->fault==='unrelated'?'unrelated-placeholder.com':$s->name).'</Description><ProductType>Register</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>99.00</AmountPaid><ExpiryDateTime>2027-10-18</ExpiryDateTime></OrderDetail></Order>',
                default=>throw new LogicException('Unexpected recovery command'),
            };
            if($command===ReadCommand::Order&&$s->drift){$drift=$s->drift;$s->drift=null;$drift();}
            return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
        }
    };
    $registry=new RegistrarRegistry;$registry->register('enom',['uid','token'],$verify,fn($b,$c)=>new Driver(new Client($b,$c['uid'],$c['token'],$wire),['com']));
    $reads=new AccountBindings($registry);
    $rebuild=fn()=>new OperationReconciliation(new OperationClaims($reads,$clock),$reads,$clock);
    $prepare=static function(string $name,bool $captured=false)use($clock,$auth,$binding,$new,$worker,$reply,$s){
        $auth->setProcessing($binding->serverId,true,static fn()=>null);$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
        $op=$new($name);resetWire($captured?['reply'=>$reply]:['timeout'=>true]);$worker->operation($op->id);$op->refresh();
        same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn($form)=>($form['Command']??null)==='Purchase')),'Recovery begins with exactly one monetary send');
        same($captured?'pending':'uncertain',$op->state,'Only acknowledgement or uncertainty retained after send');
        $s->name=$name;$s->bill=(new DateTimeImmutable($op->attempt['dispatch_attempted_at']))->modify('+1 second')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');
        $s->fault='unique';$s->calls=[];$clock->at=$clock->at->modify('+301 seconds');
        Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
        $auth->setProcessing($binding->serverId,false);Cache::flush();return $op;
    };
    $money=static fn()=>array_map(static fn($table)=>DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),['invoices','invoice_items','invoice_transactions','credits']);
    $unresolved=static function($op,string $why)use($account){
        $op->refresh();$d=$op->domain()->firstOrFail();
        same('registration_pending',$d->status,$why.' keeps customer processing');same($d->domain,$d->reservation_name,$why.' retains reservation');
        same([null,null,null,null,null],[$op->cost['actual']??null,$op->confirmed_expires_at,$d->provider_expires_at,$d->provider_domain_id,$d->renewal_policy['billing_anchor']??null],$why.' records no financial completion');
        expect(!isset($op->funding['refund']),$why.' grants no refund');expect(!$account->fresh()->processing_enabled,'Read recovery leaves processing paused');
        same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn($form)=>($form['Command']??null)==='Purchase')),$why.' never resends Purchase');
    };
    $op=$prepare(placeholderComFixtureName('history-uncertain-placeholder.com'));$before=$money();$rebuild()->run($op->id);
    same([],$s->calls,'Missing original OrderID without accepted history makes no provider reads');$unresolved($op,'Closed history gate');
    // Test-only acceptance of the synthetic profile, never a provider acceptance claim.
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_registration_v1'));
    foreach(['none','unrelated','ambiguous','duplicate'] as $fault){
        $polls=$op->fresh()->policy['reconciliation']['polls'];
        $s->fault=$fault;$s->calls=[];Cache::flush();$rebuild()->run($op->id);$unresolved($op,$fault);
        expect(in_array('GetOrderList',$s->calls,true),'Missing OrderID requires history reads');same($before,$money(),'Unresolved history changes no native money');
        same(null,(new Refunds($clock))->creditRejected($op->id),'Uncertain sent attempts grant no account credit');same($before,$money(),'Credit recovery changes no money');
        if(in_array($fault,['unrelated','duplicate'],true)){same($polls+1,$op->fresh()->policy['reconciliation']['polls'],'Complete history reaches zero/multiple-candidate completion guard');}
        if($fault==='duplicate'){same(2,count(array_filter($s->calls,fn($c)=>$c==='GetOrderDetail')),'Both competing charges read');}
        $auth->setProcessing($binding->serverId,true,static fn()=>null);
        $ports=new RuntimePorts;$ports->register($binding->fingerprint,$factory);(new RuntimeWork($clock,$ports))->operation($op->id);
        // Enter the reconstructed acquisition engine directly as well as its worker router.
        $factory()['acquisition']->execute($op->id);
        same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn($form)=>($form['Command']??null)==='Purchase')),'Reconstructed worker and engine cannot replay uncertain Purchase');
        $auth->setProcessing($binding->serverId,false);
    }
    // Staff can restart exhausted read polling with read permissions alone.
    $op->refresh();$op->update(['policy'=>[...$op->policy,'reconciliation'=>[...$op->policy['reconciliation'],'polls'=>5]]]);
    $s->fault='unique';$s->calls=[];$rebuild()->run($op->id);same([],$s->calls,'Exhausted polling makes no provider reads');
    $staff=staffUser(['admin.domains.view','admin.domains.operations.resume_reads','admin.domains.operations.recheck']);
    $review=static function(string $action)use($staff,$op,$clock):string{return StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op->fresh(),$op->domain()->firstOrFail()),$clock);};
    app()->instance(OperationReconciliation::class,$rebuild());$staffWork=new StaffOperations($clock);
    $staffWork->run($staff,$op->domain_id,$op->id,'resume_reads',$review('resume_reads'),'Reviewed synthetic charge evidence');
    same([],$s->calls,'Staff resume only changes audited read eligibility');$unresolved($op,'Staff resume');
    same(0,$op->fresh()->policy['reconciliation']['polls'],'Staff resume resets bounded read polling');
    $staffWork->run($staff,$op->domain_id,$op->id,'recheck',$review('recheck'),'Reviewed synthetic charge evidence');
    $op->refresh();$d=$op->domain()->firstOrFail();same('succeeded',$op->state,'Unique accepted synthetic charge completes uncertain attempt');
    same('accepted_history',$op->attempt['reconciliation']['source'],'Missing original ID completes only through accepted history');
    same(['amount'=>'99.00','currency'=>'USD'],$op->cost['actual'],'Actual charged cost retained');
    expect(PricingRule::minor((string)$op->amount)<9900,'Fixture retail is below recovered charge');same('below_cost',$op->policy['reconciliation']['reason'],'Recovered below-cost charge alerts staff without holding completion');same(true,$op->policy['reconciliation']['staff_alert'],'Below-cost recovery alerts staff');same(null,$op->safe_code,'Matching authorized charge has no discrepancy code');same('901',$op->provider_order_id,'Recovered original order retained');
    same('2027-10-18',$d->provider_expires_at->format('Y-m-d'),'Owned expiry retained with unique charge');same('active',$d->status,'Unique proof ends customer processing');
    same(['expiry'=>'2027-10-18','operation_key'=>$op->operation_key],$d->renewal_policy['billing_anchor'],'Billing anchor bound to proven original attempt');
    same(null,App\Models\Service::find($op->service_id)->expires_at,'No native annual expiry');same($before,$money(),'Completion changes no invoices, transactions or credits');
    same(1,count($op->policy['reconciliation_resumptions']),'Staff resume audit retained');same(3,count($op->policy['staff_actions']),'Resume and read request/finish audits retained');
    $calls=$s->calls;$rebuild()->run($op->id);same($calls,$s->calls,'Rebuilt reconciler does not repeat completed reads');
    // A captured original ID bypasses history, even with history acceptance revoked.
    DB::transaction(fn()=>HistoryAcceptances::revokeLocked($binding,Action::Register));
    $captured=$prepare(placeholderComFixtureName('captured-charge-placeholder.com'),true);$rebuild()->run($captured->id);$captured->refresh();
    same('succeeded',$captured->state,'Captured order recovers with history closed');same('captured_order',$captured->attempt['reconciliation']['source'],'Captured proof provenance retained');
    same(8,count($s->calls),'Captured completion uses bounded proof reads and one nameserver read');same(1,count(array_filter($s->calls,fn($c)=>$c==='GetOrderDetail')),'Captured original order read exactly once');same('GetDNS',$s->calls[array_key_last($s->calls)],'Nameservers are read only after completion proof');expect(!in_array('GetOrderList',$s->calls,true),'Captured ID never searches account history');
    $capturedDomain=$captured->domain()->firstOrFail();same(['ns1.provider.example','ns2.provider.example'],$capturedDomain->observation['nameservers']['nameservers']??null,'Captured completion stores observed nameservers');
    // Funding is checked both before provider reads and again before completion.
    $drift=$prepare(placeholderComFixtureName('funding-charge-placeholder.com'),true);$before=$money();DB::table('invoices')->where('id',$drift->invoice_id)->update(['status'=>'pending']);
    $rebuild()->run($drift->id);same([],$s->calls,'Funding lost before recovery refuses all reads');$unresolved($drift,'Unfunded recovery');
    DB::table('invoices')->where('id',$drift->invoice_id)->update(['status'=>'paid']);
    $s->drift=static fn()=>DB::table('invoices')->where('id',$drift->invoice_id)->update(['status'=>'pending']);Cache::flush();$rebuild()->run($drift->id);
    expect(in_array('GetOrderDetail',$s->calls,true),'Funding drift occurs after charge read');$unresolved($drift,'Funding changed during read');
    DB::table('invoices')->where('id',$drift->invoice_id)->update(['status'=>'paid']);Cache::flush();$rebuild()->run($drift->id);
    same('succeeded',$drift->fresh()->state,'Restored funding permits read-only unique completion');same($before,$money(),'No native money mutation after funding restoration');
    same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn($form)=>($form['Command']??null)==='Purchase')),'Funding recovery never repeats monetary send');same(null,HistoryAcceptances::load($binding),'Synthetic history acceptance removed');
    expect(!$account->fresh()->processing_enabled,'All read-only recovery leaves processing off');
    app()->forgetInstance(OperationReconciliation::class);
    echo "Installed Enom reconciliation: closed history, no charge, ambiguity, duplicate charge, staff reads, captured ID, restart and funding drift passed.\n";
})();
