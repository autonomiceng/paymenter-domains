<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainPage,OwnedDomain,RequestStatus};
$d=new OwnedDomain('example.com','Active','ok','2027-11-05','On','$10.99',[],[],[],[],[],false,
    renewalDue:'2027-10-29',renewNow:['invoice'=>'/invoices/130'],earlyRenewal:true);
same(['value'=>'Invoice ready. Due Oct 29, 2027.','action'=>['label'=>'Pay invoice','url'=>'/invoices/130']],DomainPage::nextInvoice($d,null,null),'An issued early invoice has a neutral due date and payment action');
same('Invoice ready. Due Oct 29, 2027.',RequestStatus::earlyRenewal('2027-10-29'),'Shared request wording has the same due-date story');
echo "Renewal ready copy passed.\n";
