<?php
// Enom 444f31c/f7175ff transfer reconciliation, stricter unique charge; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require_once dirname(__DIR__).'/bootstrap.php';
$transferEnvironment??=Paymenter\Extensions\Servers\Domains\Data\Environment::Live; // All transports remain synthetic.
require __DIR__.'/transfer-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, RegistrarRegistry, HistoryAcceptances, OperationReconciliation, RenewalAuthority};
$observe=(object)['name'=>'','fault'=>'','calls'=>[],'during'=>null,'orders'=>1,'unowned'=>false,'countSends'=>false,'beforeSend'=>null];
$wire=new class($observe) implements Transport {
    public function __construct(private $s){}
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $timeoutMs): string {
        same(0,DB::transactionLevel(),'Observation outside locks'); expect($timeoutMs>0 && $timeoutMs<=25000,'Bounded observation'); $s=$this->s;
        if($s->beforeSend){$hook=$s->beforeSend;$s->beforeSend=null;$hook();}
        if($s->countSends){\Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimit::admit($e,$uid,$command->value);}
        $s->calls[]=$command->value;
        if ($s->fault === 'down') { throw new RuntimeException('Synthetic outage'); }
        if ($s->during) { $hook=$s->during; $s->during=null; $hook(); }
        if ($command === ReadCommand::Nameservers && $s->fault === 'dns-cap') { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit; }
        if ($command === ReadCommand::Nameservers && $s->fault === 'dns-transport') { throw new RuntimeException('Synthetic transport failure'); }
        if ($command === ReadCommand::Nameservers && in_array($s->fault, ['dns-refused', 'dns-refused-strict'], true)) {
            return '<interface-response><Command>GetDNS</Command><ErrCount>1</ErrCount><Done>true</Done><errors><Err1>DNS read refused</Err1></errors></interface-response>';
        }
        $expiry=$s->fault==='short'?'2027-01-01':'2028-01-01'; $owner=$s->unowned||in_array($s->fault,['owner','reject','reject_charge'],true)?'other-account':'account-enom';
        $id=$p['OrderID']??'9001';$charge=$s->charge??'8.95';
        $item='<Description>'.$s->name.'</Description><ProductType>Transfer</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>'.$charge.'</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime>';
        if($id!=='9001'){$item=str_replace($s->name,'unrelated-'.$s->name,$item);}
        [$label,$tld]=explode('.',$s->name,2); $header=str_starts_with($s->fault,'reject')?'2':($s->fault==='pending'?'5':'3'); $detail=str_starts_with($s->fault,'reject')?'32':($s->fault==='pending'?'100':'5');
        $data=match($command){
            ReadCommand::Info=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>'.$owner.'</Belongs-To><Expiration>'.$expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::Nameservers=>match ($s->fault) {
                'dns-malformed' => '<dns>invalid hostname</dns>',
                'dns-duplicate' => '<dns>ns1.provider.example</dns><dns>ns1.provider.example</dns>',
                'dns-shape' => '<dns><host>ns1.provider.example</host></dns>',
                default => '<dns>ns1.provider.example</dns><dns>ns2.provider.example</dns>',
            },
            ReadCommand::Expiry=>$s->fault==='expiry'?'':'<ExpirationDate>'.$expiry.'</ExpirationDate>',
            ReadCommand::Renewal=>'<RenewName>0</RenewName>', ReadCommand::Lock=>'<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Transfer=>$s->fault==='status'?'':'<TransferOrder><TransferOrderID>7001</TransferOrderID><StatusID>'.$header.'</StatusID><TransferOrderDetail><SLD>'.$label.'</SLD><TLD>'.$tld.'</TLD><StatusID>'.$detail.'</StatusID></TransferOrderDetail></TransferOrder>',
            ReadCommand::Orders=>'<OrderCount>'.$s->orders.'</OrderCount><OrderList>'.implode('',array_map(fn($i)=>'<Order><OrderID>'.(9001+$i).'</OrderID></Order>',range(0,$s->orders-1))).'</OrderList>',
            // A clean rejection has complete history whose only order is for another domain.
            ReadCommand::Order=>$s->fault==='reject'?'<Order><OrderID>'.$id.'</OrderID><Result>true</Result><OrderBillAmount>$'.$charge.'</OrderBillAmount><BillDate>10/18/2026 5:00:01 AM</BillDate><OrderDetail>'.str_replace($s->name,'unrelated-'.$s->name,$item).'</OrderDetail></Order>'
                :(in_array($s->fault,['charge','reject_owned'],true)?'':'<Order><OrderID>'.$id.'</OrderID><Result>true</Result><OrderBillAmount>$'.$charge.'</OrderBillAmount><BillDate>'.($s->billDate??'10/18/2026 '.($s->fault==='late'?'5:10:00':'5:00:01').' AM').'</BillDate><OrderDetail>'.$item.'</OrderDetail>'.($s->fault==='duplicate'?'<OrderDetail>'.$item.'</OrderDetail>':'').'</Order>'),
            default=>throw new LogicException('Unexpected observation command'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$registry=new RegistrarRegistry; $registry->register('enom',['token'],$verifier,fn($b,$c)=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(new Client($b,'account-enom',$c['token'],$wire),['com']));
$readBindings=new AccountBindings($registry); $reconcile=new OperationReconciliation($claims,$readBindings,$clock);
$prepare=static function($name)use($newTransfer,$makeTransfer,&$engine,&$binding,$clock,$now,$state,$observe){
    DB::table('domains_read_budgets')->delete(); // Each synthetic transfer starts an independent account-minute scenario.
    $clock->instant=$now; $state->rdapUnknown=false; $state->mode=''; $op=$newTransfer($name); $makeTransfer(); $r=$engine->execute($op->id);
    same('7001',$r->attempt['evidence']['transferReference'],'Transfer ID retained separately'); same(null,$r->provider_order_id,'Transfer ID never billing OrderID');
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_transfer_v1'));
    $observe->name=$name; $observe->fault=''; $observe->calls=[]; $clock->instant=$now->modify('+6 minutes'); return $r;
};
