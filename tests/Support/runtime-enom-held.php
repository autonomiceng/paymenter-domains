<?php
// Installed Enom holding lifecycle; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client,ReadCommand,Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,DomainBilling,HolderPricing,NativeDomainGuard,OperationClaims,OperationReconciliation,ReconciliationEvidence,RegistrarRegistry,RenewalAcceptance,WriteFeatures};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
// Complete a synthetically paid registration through the captured-order read stub.
// Binding drift prevented release of its old physical lease; let that lease expire.
$clock->at=$clock->at->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$s=(object)['name'=>$registered->domain->domain,'bill'=>(new DateTimeImmutable($registered->attempt['dispatch_attempted_at']))->modify('+1 second')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A'),'locked'=>true,'renewal'=>false];
$readWire=new class($s) implements Transport {
    public function __construct(private $s){}
    public function send(Environment $env,ReadCommand $command,array $p,string $uid,string $token,int $timeoutMs):string {
        same(0,DB::transactionLevel(),'Holding fresh reads outside locks');$s=$this->s;
        $body=match($command){
            ReadCommand::Info=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>account-enom</Belongs-To><Expiration>'.($s->renewal?'10/18/2027 5:00:00 AM':'2027-10-18').'</Expiration></Status></GetDomainInfo>',
            ReadCommand::Expiry=>'<ExpirationDate>2027-10-18</ExpirationDate>',
            ReadCommand::Renewal=>'<RenewName>0</RenewName>',ReadCommand::Lock=>'<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Order=>'<Order><OrderID>1234</OrderID><Result>true</Result><OrderBillAmount>$99.00</OrderBillAmount><BillDate>'.$s->bill.'</BillDate><OrderDetail><Description>'.$s->name.'</Description><ProductType>Register</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>99.00</AmountPaid><ExpiryDateTime>2027-10-18</ExpiryDateTime></OrderDetail></Order>',
            ReadCommand::RenewalInfo=>'<Expiration>10/18/2027</Expiration><Price>8.00</Price><RegistrarHold>False</RegistrarHold><MaxExtension>8</MaxExtension>',
            ReadCommand::Check=>'<Domains><Domain><Name>'.$s->name.'</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Prices><Currency/><Renewal>8.00</Renewal></Prices></Domain></Domains>',
            ReadCommand::Balance=>'<AvailableBalance>100.00</AvailableBalance>',
            default=>throw new LogicException('Unreviewed synthetic read'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
    }
};
$registry=new RegistrarRegistry;$registry->register('enom',['uid','token'],$verify,fn($b,$c)=>new Driver(new Client($b,$c['uid'],$c['token'],$readWire),['com']));
$heldBindings=new AccountBindings($registry);Cache::flush();
$reconcile=new OperationReconciliation(new OperationClaims($heldBindings,$clock),$heldBindings,$clock);
same('succeeded',$reconcile->run($registered->id)->state,'Captured original order completes genuine holding');$d=$registered->domain()->firstOrFail();
$heldBindings->setProcessing($binding->serverId,true,static fn()=>null);
$heldFeatures=new WriteFeatures(renewal:true,management:true);$heldAccepted=new RenewalAcceptance($binding,true,true,25);
$heldFactory=$heldBindings->enomRuntimeFactory($binding,$clock,$heldFeatures,$accepted,$heldAccepted,$precheck,true);$held=$heldFactory();
// A response lost after a management mutation must reconcile through reads even while spending is paused.
$managementReply=static function($p)use($s):string {
    $body=match($p['Command']){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>account-enom</Belongs-To></Status></GetDomainInfo>',
        'GetRegLock'=>'<Reg-Lock>'.(int)$s->locked.'</Reg-Lock>',
        'SetRegLock'=>'<InfoSynched>true</InfoSynched>',default=>throw new LogicException('Unexpected management command'),
    };
    return '<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
Cache::flush();resetWire(['reply'=>$managementReply,'during'=>static function($p)use($s){if($p['Command']==='SetRegLock'){$s->locked=false;$GLOBALS['enomWire']['timeout']=true;}}]);
$manage=$held['management']->request($user,$d->id,'lock',['locked'=>false],hash('sha256','wire-lock'),$held['management_protocol']);
same('uncertain',$held['management']->run($manage->id,$held['management_protocol'])->state,'Lost management reply stays uncertain');
same(1,count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='SetRegLock')),'One genuine management mutation');
$heldBindings->setProcessing($binding->serverId,false);Cache::flush();resetWire(['reply'=>$managementReply]);$fresh=$heldFactory();
same('succeeded',$fresh['management']->reconcile($manage->id,$fresh['management_protocol'])->state,'Paused processing still allows authenticated readback');
same(['GetDomainInfo','GetRegLock'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Paused reconciliation sends only bound reads');
$heldBindings->setProcessing($binding->serverId,true,static fn()=>null);Cache::flush();
$late=$held['management']->request($user,$d->id,'lock',['locked'=>false],hash('sha256','paused-after-seal'),$held['management_protocol']);
$pause=static function()use(&$pause,$late,$account):void {
    if(($late->fresh()->attempt['seal']??null)!=='sealed'){$GLOBALS['enomWire']['before']=$pause;return;}
    DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
};
resetWire(['reply'=>$managementReply,'before'=>$pause]);
same('uncertain',$held['management']->run($late->id,$held['management_protocol'])->state,'Post-seal management pause remains uncertain');
same(['GetDomainInfo'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Post-seal management pause refuses mutation before cURL');
Cache::flush();resetWire(['reply'=>$managementReply]);$fresh=$heldFactory();
same('succeeded',$fresh['management']->reconcile($late->id,$fresh['management_protocol'])->state,'Paused readback confirms already desired lock state');
$heldBindings->setProcessing($binding->serverId,true,static fn()=>null);$s->renewal=true;
// Genuine retained native invoice, fresh renewal admission and one-use writer share this same factory.
$clock->at=new DateTimeImmutable('2027-09-27T12:00:00Z');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
App\Models\Server::find($binding->serverId)->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
config(['settings.credits_auto_use'=>false]);$billing=new DomainBilling($heldBindings,app(NativeDomainGuard::class),$clock);$invoice=$billing->invoice($d->id,$user);
$renew=Operation::where('invoice_id',$invoice->id)->sole();DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>$renew->amount,'fee'=>'0.00','status'=>'succeeded']);
$clock->at=new DateTimeImmutable('2027-10-18T11:59:00Z');Cache::flush();resetWire(['timeout'=>true]);
same('uncertain',$held['renewal']->run($renew->id,$held['renewal_writer'])->state,'Renewal timeout after send stays uncertain');
same(['Extend'],array_column($GLOBALS['enomWire']['forms'],'Command'),'One genuine renewal monetary send');
same('no',$GLOBALS['enomWire']['forms'][0]['UseCreditCard'],'Renewal never charges a card');
$fresh=$heldFactory();Cache::flush();$fresh['renewal']->run($renew->id,$fresh['renewal_writer']);
same(1,count($GLOBALS['enomWire']['forms']),'Reconstructed renewal worker cannot replay consumed grant');
echo "Installed Enom holding: captured-order anchor, management lost reply, paused authenticated readback and funded renewal uncertainty passed.\n";
