<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A registration or transfer the registrar rejected: the customer page promises no expiry and says what happens to the money.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';require __DIR__.'/Support/money-receipt.php';
use App\Enums\InvoiceTransactionStatus;
use App\Models\{Gateway, InvoiceTransaction};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainPage, Missing, OwnedDomain};
use Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns;

storefrontViews();
// Tags become spaces: a heading, a label and a value piece sit next to each other with no whitespace between them.
$norm=static fn(string $html)=>trim(preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('/<[^>]+>/',' $0 ',$html)),ENT_QUOTES|ENT_HTML5)));
$owned=static fn(string $reason,string $renewal,?string $refundTo=null)=>new OwnedDomain(...['rejected.example','Failed','bad',null,$renewal,null,['show'=>'/domains/1'],[],[],[],[],false,'statusReason'=>$reason,...($refundTo===null?[]:['refundTo'=>$refundTo])]);
// Start with the existing no-payment DTO shape, before any newly added return helper.
$money=[null=>'', 'original'=>'Any refund goes back to your original payment method.','credit'=>'Any refund goes to your account credit.',
    'split'=>'Any refund goes back to your original payment method where it can, and to your account credit otherwise.',
    'credited'=>'We added what you paid to account credit.','returned'=>'We are returning what you paid to your original payment method.'];
foreach(['Registration did not finish'=>['Not registered',''],'Transfer did not finish'=>['Not transferred','The domain stays at your current registrar. ']] as $reason=>[$value,$lead]){
    foreach($money as $to=>$sentence){
        $to=$to===''?null:$to;$note=trim($lead.$sentence);
        same(['missing'=>false,'tone'=>null,'value'=>$value,'note'=>$note],DomainPage::expires($owned($reason,'',$to),null,'/tickets/create'),$reason.' / '.($to??'nothing paid').': Expires says what happened and where the money goes');
        $card=$norm(view('domains::partials.owner-renew',['domain'=>$owned($reason,'',$to),'support'=>'/tickets/create'])->render());
        $want=trim($value.' '.$note).(Missing::supportUrl()!==null?' Contact support':'');
        expect(str_starts_with($card,'Renewal Expires '.$want)&&!str_contains($card,Missing::FILLS)&&!str_contains($card,Missing::LABEL),$reason.' / '.($to??'nothing paid').': the Renewal card reads '.$want.', got '.$card);
    }
    same(null,DomainPage::nextInvoice($owned($reason,'On','credit'),null,'/tickets/create'),$reason.': no next invoice is promised');
}
// A domain still waiting for its first expiry keeps the existing Missing line.
same(true,DomainPage::expires(new OwnedDomain('waiting.example','Active','ok',null,'',null,['show'=>'/domains/1'],[],[],[],[],false),null,'/tickets/create')['missing'],'Other missing expiries are unchanged');
// Where a refund goes follows the paid rows: a gateway that can return a payment gets it back, anything else becomes account credit.
$receipt=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
$row=static fn(?Gateway $gateway,string $amount='10.99',InvoiceTransactionStatus $status=InvoiceTransactionStatus::Succeeded)=>(new InvoiceTransaction)->forceFill(['amount'=>$amount,'status'=>$status])->setRelation('gateway',$gateway);
same([null,null,'credit','original','split'],[ReceiptReturns::destination([]),ReceiptReturns::destination([$row($receipt,'10.99',InvoiceTransactionStatus::Failed),$row(null,'0.00')]),ReceiptReturns::destination([$row(null)]),ReceiptReturns::destination([$row($receipt)]),ReceiptReturns::destination([$row($receipt),$row(null,'5.00')])],'Refund destination follows the paid rows');


$nativeSuccessful=true;echo "Rejected registration page checked.\n";
