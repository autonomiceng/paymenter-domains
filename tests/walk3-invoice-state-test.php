<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/staff-request-invoice-test.php';
$nativeSuccessful=false;
StaffInvoiceReversalGateway::$observations['pi_staff_invoice']=$row(['kind'=>'refund','status'=>'refunded','amount_minor'=>1099]);
same('Refunded',\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($op->fresh())['invoice_state'],'The staff request reports a fully refunded invoice');
$nativeSuccessful=true;
