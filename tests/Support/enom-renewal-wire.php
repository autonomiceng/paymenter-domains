<?php
// Socket-free reviewed renewal responses; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
$renewReply=static function(array $p)use($s):string {
    same(0,DB::transactionLevel(),'Renewal reads/write outside financial locks');
    same('https://resellertest.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'TEST only');
    $body=match($p['Command']){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>test-uid</Belongs-To><Expiration>'.($s->after?$s->confirmed:$s->expiry).'</Expiration></Status></GetDomainInfo>',
        'GetExtendInfo'=>'<GetExtendInfo><Expiration>'.$s->day.'</Expiration><Price>'.$s->price.'</Price><RegistrarHold>False</RegistrarHold><MaxExtension>8</MaxExtension></GetExtendInfo>',
        'Check'=>'<Domains><Domain><Name>'.$s->name.'</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Prices><Currency/><Renewal>'.$s->price.'</Renewal></Prices></Domain></Domains>',
        'GetRenew'=>'<RenewName>'.$s->auto.'</RenewName>','GetRegLock'=>'<Reg-Lock>1</Reg-Lock>',
        'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
        'GetBalance'=>'<AvailableBalance>100.00</AvailableBalance>',
        'Extend'=>'<RRPCode>200</RRPCode><OrderID>'.$s->order.'</OrderID>',
        'GetDomainExp'=>'<ExpirationDate>'.$s->confirmed.'</ExpirationDate>',
        'GetOrderDetail'=>'<Order><OrderID>'.($p['OrderID']??$s->order).'</OrderID><Result>true</Result><OrderBillAmount>$'.$s->charge.'</OrderBillAmount><BillDate>'.$s->bill.'</BillDate><OrderDetail><Description>'.$s->name.'</Description><ProductType>Renew</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>'.$s->charge.'</AmountPaid><ExpiryDateTime>'.$s->confirmed.'</ExpiryDateTime></OrderDetail></Order>',
        'GetOrderList'=>'<OrderCount>'.count($s->orders).'</OrderCount><OrderList>'.implode('',array_map(static fn($id)=>'<Order><OrderID>'.$id.'</OrderID></Order>',$s->orders)).'</OrderList>',
        'GetExpiredDomains'=>'<DomainCount>1</DomainCount><DomainDetail><DomainName>'.$s->name.'</DomainName><DomainNameID>456</DomainNameID><Status>Expired</Status><Expiration-Date>'.$s->day.'</Expiration-Date></DomainDetail>',
        default=>throw new LogicException('Unreviewed renewal command '.$p['Command']),
    };return '<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
