<?php
// Synthetic reviewed Enom transfer protocol; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$transferReply=static function(array $p)use($transferState,$clock):string {
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Transfer request outside financial locks');
    same('https://resellertest.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'Transfer TEST route');
    $cmd=$p['Command'];$name=isset($p['SLD1'])?$p['SLD1'].'.'.$p['TLD1']:(isset($p['SLD'])?$p['SLD'].'.'.$p['TLD']:$transferState->name);
    if($cmd==='TP_CreateOrder'){$transferState->name=$name;$transferState->bill=$clock->now()->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');}
    $expiry='2028-10-21';[$label,$ending]=array_pad(explode('.',$name,2),2,'com');
    $body=match($cmd){
        'Check'=>'<Domains><Domain><Name>'.$name.'</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Properties><Transferable>true</Transferable></Properties></Domain></Domains>',
        'PE_GetProductPrice'=>'<ProductPrice><ProductEnabled>true</ProductEnabled><Price>10.0000</Price></ProductPrice>',
        'GetBalance'=>'<AvailableBalance>100.00</AvailableBalance>',
        'TP_CreateOrder'=>'<transferorder><transferorderid>7001</transferorderid></transferorder>',
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$name.'</DomainName><Status><Belongs-To>'.($transferState->pending?'other-account':'test-uid').'</Belongs-To><Expiration>'.$expiry.'</Expiration></Status></GetDomainInfo>',
        'GetRenew'=>'<RenewName>0</RenewName>','GetRegLock'=>'<Reg-Lock>1</Reg-Lock>',
        'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
        'GetDomainExp'=>'<ExpirationDate>'.$expiry.'</ExpirationDate>',
        'TP_GetOrder'=>'<TransferOrder><TransferOrderID>7001</TransferOrderID><StatusID>'.($transferState->pending?'5':'3').'</StatusID><TransferOrderDetail><SLD>'.$label.'</SLD><TLD>'.$ending.'</TLD><StatusID>'.($transferState->pending?'100':'5').'</StatusID></TransferOrderDetail></TransferOrder>',
        'GetOrderList'=>'<OrderCount>1</OrderCount><OrderList><Order><OrderID>9001</OrderID></Order></OrderList>',
        'GetOrderDetail'=>$transferState->charge?'<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$10.00</OrderBillAmount><BillDate>'.$transferState->bill.'</BillDate><OrderDetail><Description>'.$name.'</Description><ProductType>Transfer</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>$10.00</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime></OrderDetail></Order>':'',
        default=>throw new LogicException('Unreviewed transfer fixture command '.$cmd),
    };
    return '<interface-response><Command>'.$cmd.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
