<?php
// Synthetic transfer read replies; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, Transport};
$observe=(object)['name'=>'','fault'=>'','calls'=>[],'during'=>null,'beforeRead'=>null];
$wire=new class($observe) implements Transport {
    public function __construct(private $s){}
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $timeoutMs): string {
        $s=$this->s; if ($s->beforeRead) { ($s->beforeRead)(); } expect($timeoutMs>0 && $timeoutMs<=25000,'Bounded observation'); $s=$this->s; $s->calls[]=$command->value;
        if ($s->during) { $hook=$s->during; $s->during=null; $hook(); }
        if ($command === ReadCommand::Nameservers && $s->fault === 'dns-cap') { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit; }
        if ($command === ReadCommand::Nameservers && $s->fault === 'dns-transport') { throw new RuntimeException('Synthetic transport failure'); }
        if ($command === ReadCommand::Nameservers && in_array($s->fault, ['dns-refused', 'dns-refused-strict'], true)) {
            return '<interface-response><Command>GetDNS</Command><ErrCount>1</ErrCount><Done>true</Done><errors><Err1>DNS read refused</Err1></errors></interface-response>';
        }
        $expiry=$s->fault==='short'?'2027-01-01':'2028-01-01'; $owner=in_array($s->fault,['owner','reject','reject_charge'],true)?'other-account':'account-enom';
        $item='<Description>'.$s->name.'</Description><ProductType>Transfer</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime>';
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
            ReadCommand::Orders=>'<OrderCount>1</OrderCount><OrderList><Order><OrderID>9001</OrderID></Order></OrderList>',
            // A clean rejection has complete history whose only order is for another domain.
            ReadCommand::Order=>$s->fault==='reject'?'<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><BillDate>10/18/2026 5:00:01 AM</BillDate><OrderDetail>'.str_replace($s->name,'unrelated-'.$s->name,$item).'</OrderDetail></Order>'
                :(in_array($s->fault,['charge','reject_owned'],true)?'':'<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><BillDate>10/18/2026 '.($s->fault==='late'?'5:10:00':'5:00:01').' AM</BillDate><OrderDetail>'.$item.'</OrderDetail>'.($s->fault==='duplicate'?'<OrderDetail>'.$item.'</OrderDetail>':'').'</Order>'),
            default=>throw new LogicException('Unexpected observation command'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
