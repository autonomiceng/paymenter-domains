<?php
// Synthetic Enom dns cases; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$nativeInvoiceAttachmentFixture=true;
// Keep live native/Livewire graphs out of automatic repeated cycle scans.
$automaticDnsGc=gc_enabled();gc_disable();
$enomModes = [];
$runEnomRegistrationTail = false;
$runEnomRegistrationDns = true;
require __DIR__.'/Support/enom-registration-suite.php';
if($automaticDnsGc){gc_enable();}
