<?php
// Existing reviewed synthetic read shapes only; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/enom-identity.php';
$GLOBALS['catalogueCalls']=[];
/** The documented Text reply. Each row: tld => [register, renew, transfer]; a null price comes back disabled at 0.00. */
function catalogueBulkText(array $rows): string
{
    $lines=[];$i=0;
    foreach($rows as $tld=>[$register,$renew,$transfer]){
        ++$i;$lines[]='tld'.$i.': '.$tld;
        // Retail fields carry a number we must never read as a cost.
        foreach(['register'=>['reg',$register],'renew'=>['renew',$renew],'transfer'=>['tran',$transfer]] as $name=>[$short,$price]){
            $lines[]=$name.'price'.$i.': 99.00';$lines[]='resellerprice'.$short.$i.': '.($price??'0.00');$lines[]=$name.'enabled'.$i.': '.($price===null?'false':'true');
        }
    }
    return implode("\n",[...$lines,'Command: PE_GETRETAILPRICING','ErrCount: 0','MaxPeriod: 10','Done: true']);
}
$GLOBALS['catalogueWire']=static function($handle,array $options,array $form): bool {
    same('https://resellertest.enom.com/interface.asp',$options[CURLOPT_URL],'Catalogue uses exact TEST endpoint');
    same(false,$options[CURLOPT_FOLLOWLOCATION],'No redirect');same('',$options[CURLOPT_PROXY],'No proxy');
    expect($options[CURLOPT_TIMEOUT_MS]<=25000,'Bounded catalogue deadline');
    $command=$form['Command'];$GLOBALS['catalogueCalls'][]=$form;
    if(isset($GLOBALS['catalogueDuring'])){$during=$GLOBALS['catalogueDuring'];unset($GLOBALS['catalogueDuring']);$during();}
    if($command==='GetTLDList'&&($GLOBALS['catalogueListFailed']??false)){return false;}
    if($command==='PE_GetRetailPricing'){
        if($GLOBALS['catalogueBulkFailed']??false){return false;}
        $body=catalogueBulkText(($GLOBALS['catalogueBulk'])((int)$form['Years']));
        return ($options[CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
    }
    $ending=$form['TLD']??'com';
    $payload=match($command){
        'GetBalance'=>'<AvailableBalance>100.00</AvailableBalance>',
        'GetTLDList'=>($GLOBALS['catalogueMissing']??false)?'<TLDList><TLD>net</TLD></TLDList>':'<TLDList>'.implode('',array_map(static fn(string $name)=>'<TLD>'.$name.'</TLD>',$GLOBALS['catalogueList']??['com','net'])).'</TLDList>',
        'GetTLDDetails'=>'<TLDs><TLD><TLD>'.$ending.'</TLD><Registration><Minimum>1</Minimum><Maximum>'.($GLOBALS['catalogueMaximum'][$ending]??1).'</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs>',
        'GetExtAttributes'=>'<ResponseCount>0</ResponseCount>',
        'PE_GetProductPrice'=>(($GLOBALS['catalogueBadPrice']??false)||in_array($ending,$GLOBALS['catalogueBadPriceEndings']??[],true))?'<ProductPrice><Price>invalid</Price></ProductPrice>':'<ProductPrice><ProductEnabled>true</ProductEnabled><Price>'.($GLOBALS['cataloguePrice']??'10.0000').'</Price></ProductPrice>',
        'Check'=>'<Domains><Domain><Name>'.($form['SLD'].'.'.$ending).'</Name><RRPCode>210</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP></Domain></Domains>',
        default=>throw new LogicException('Unapproved catalogue command: '.$command),
    };
    $body='<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$payload.'</interface-response>';
    return ($options[CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
};
