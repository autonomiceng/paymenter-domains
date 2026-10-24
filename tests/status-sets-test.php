<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One status vocabulary: domain, registrar request and registrar account sets.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus, DomainStatus, RequestStatus};

$pill=static fn(string $label,string $tone,?string $reason=null)=>['label'=>$label,'tone'=>$tone,'reason'=>$reason];
foreach ([DomainStatus::SET,RequestStatus::SET,AccountStatus::SET] as $set) {
    foreach ($set as [$label,$tone]) {
        expect(str_word_count($label)<=2&&!preg_match('/\d/',$label),'Pill text is one or two words, no digits: '.$label);
        expect(in_array($tone,['success','info','warning','danger','gray'],true),'Tone is a status colour: '.$label);
    }
}

// 2.1 Domain.
same(['Active','Pending registration','Pending transfer','Action required','Under review','Expired','Redemption','Failed','Cancelled'],
    array_column(DomainStatus::SET,0),'Domain labels');
same(['success','info','info','warning','warning','danger','danger','danger','gray'],array_column(DomainStatus::SET,1),'Domain tones');
foreach ([
    'active'=>$pill('Active','success'),
    'registration_pending'=>$pill('Pending registration','info'),
    'transfer_pending'=>$pill('Pending transfer','info'),
    'awaiting_payment'=>$pill('Action required','warning','Invoice not paid'),
    'awaiting_code'=>$pill('Action required','warning','Auth code needed'),
    'renewal_off_expiring'=>$pill('Action required','warning','Auto-renew off'),
    'watch.ns_changed'=>$pill('Action required','warning','Nameservers changed'),
    'needs_review'=>$pill('Under review','warning'),
    'adoption_pending'=>$pill('Under review','warning','Import not confirmed'),
    'held'=>$pill('Under review','warning'),
    'uncertain'=>$pill('Under review','warning'),
    'active_no_expiry'=>$pill('Under review','warning','Expiry missing'),
    'expired'=>$pill('Expired','danger'),
    'grace'=>$pill('Expired','danger','In grace period'),
    'watch.expired'=>$pill('Expired','danger','Renew at your registrar'),
    'redemption'=>$pill('Redemption','danger'),
    'watch.redemption'=>$pill('Redemption','danger'),
    'registration_rejected'=>$pill('Failed','danger','Registration rejected'),
    'transfer_rejected'=>$pill('Failed','danger','Transfer rejected'),
    'cancelled'=>$pill('Cancelled','gray'),
    'something_new'=>$pill('Under review','warning','Status missing'),
] as $state=>$expected) { same($expected,DomainStatus::for($state),'Domain state '.$state); }
same($pill('Under review','warning','Status missing'),DomainStatus::for(null),'No domain state is Under review with the cause');
same($pill('Under review','warning','Transfer on hold'),DomainStatus::for('held','Transfer on hold'),'The caller names the hold cause');
same($pill('Action required','warning','Not using Example nameservers'),DomainStatus::for('watch.ns_changed','Not using Example nameservers'),'The caller can name the company');

// 2.2 Registrar request.
same(['Action required','In progress','Under review','Completed','Failed','Cancelled'],array_column(RequestStatus::SET,0),'Request labels');
same(['warning','info','warning','success','danger','gray'],array_column(RequestStatus::SET,1),'Request tones');
foreach ([
    'awaiting_payment'=>$pill('Action required','warning','Invoice not paid'),
    'awaiting_code'=>$pill('Action required','warning','Auth code needed'),
    'queued'=>$pill('In progress','info'), 'running'=>$pill('In progress','info'), 'pending'=>$pill('In progress','info'),
    'held'=>$pill('Under review','warning','Not sent'),
    'uncertain'=>$pill('Under review','warning','Registrar has not confirmed the result'),
    'succeeded'=>$pill('Completed','success'),
    'rejected'=>$pill('Failed','danger'),
    'disposed'=>$pill('Cancelled','gray'),
    'something_new'=>$pill('Under review','warning','Status missing'),
] as $state=>$expected) { same($expected,RequestStatus::for($state),'Request state '.$state); }
same($pill('Under review','warning',"Not sent: couldn't load the registrar account's settings"),
    RequestStatus::for('held',"couldn't load the registrar account's settings"),'Held reason keeps the real cause');
same($pill('Failed','danger','Registrar rejected the transfer'),RequestStatus::for('rejected','Registrar rejected the transfer'),'Rejected shows the registrar reason');
same($pill('Under review','warning','Status missing'),RequestStatus::for(null),'No request state is Under review with the cause');

// 2.3 Registrar account.
same(['Active','Orders paused','Not connected','Connection failed'],array_column(AccountStatus::SET,0),'Account labels');
same(['success','warning','gray','danger'],array_column(AccountStatus::SET,1),'Account tones');
foreach ([
    'matched'=>$pill('Active','success'),
    'not_configured'=>$pill('Not connected','gray'),
    'server_disabled'=>$pill('Not connected','gray','Off in Paymenter'),
    'server_unavailable'=>$pill('Connection failed','danger','Server missing in Paymenter'),
    'site_invalid'=>$pill('Connection failed','danger','Site address must use HTTPS'),
    'site_unbound'=>$pill('Connection failed','danger','Site address not approved for live mode'),
    'site_mismatch'=>$pill('Connection failed','danger','Site address changed'),
    'configuration_unavailable'=>$pill('Connection failed','danger','Credentials changed'),
    'something_new'=>$pill('Connection failed','danger','Status missing'),
] as $state=>$expected) { same($expected,AccountStatus::for($state),'Account state '.$state); }
same($pill('Orders paused','warning','Paused by staff'),AccountStatus::for('matched',processing:false),'Processing off pauses orders');
same($pill('Orders paused','warning','Request #2 unconfirmed'),AccountStatus::for('matched',processing:false,reason:'Request #2 unconfirmed'),'The caller names the pause cause');
same($pill('Orders paused','warning','Demo registrar off while Enom Test mode is on'),
    AccountStatus::for('matched',processing:false,reason:AccountStatus::DEMO_PAUSED),'Demo pause cause');
same($pill('Not connected','gray'),AccountStatus::for('not_configured',processing:false),'Processing does not matter before a connection');
echo "Status set checks passed.\n";
