<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Availability states come from evidence, never from an empty value alone; provider codes map through tables.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Paymenter\Extensions\Servers\Domains\Presentation\{Availability, Plain, Read, State};
use Paymenter\Extensions\Servers\Domains\Presentation\Codes\{InvoiceStatus, RecordRead};

$never = new Read(null); $good = new Read(100); $failed = new Read(100, 200); $recovered = new Read(300, 200);
same(State::NotReadYet, Availability::value($never, false), 'Never read: nothing for anyone to do');
same(State::Checking, Availability::value(new Read(null, null, true), false), 'Waiting on the registrar now');
same(null, Availability::value(new Read(100, 200, true), true), 'A refresh after a failure keeps the last value');
same(State::Checking, Availability::value(new Read(100, 200, true), false), 'Checking replaces only a value we have never got');
same(State::CouldntRead, Availability::value(new Read(null, 50), false), 'The only read failed');
same(State::CouldntRead, Availability::value($failed, true), 'A failed read is red even with an older value');
same(null, Availability::value($good, true), 'A good read shows its value');
same(null, Availability::value($recovered, true), 'A good read after a failure shows its value');
same(Plain::None, Availability::value($good, false), 'A good read that came back empty is None, not a missing value');
same(State::NotSupported, Availability::value($failed, false, supported: false), 'Can\'t be done wins over how the read went');
same(State::NotForSale, Availability::value($never, false, sold: false), 'Not sold wins over the read');
same(State::NotSupported, Availability::value($good, true, supported: false, sold: false), 'Not supported wins over not for sale');
same(null, Availability::setting(true, true), 'A chosen setting shows its value');
same(State::NotSet, Availability::setting(false, true), 'An empty required setting is Not set, which staff fix');
same(Plain::None, Availability::setting(false, false), 'An empty optional setting is None');

// Registrar record reads.
same([State::Ok, null], [RecordRead::state('complete', $good)->state, RecordRead::state('complete', $good)->code], 'A complete read is OK');
same(State::NotSupported, RecordRead::state('unsupported', $good)->state, 'A family the registrar can\'t read');
foreach (['unknown', 'partial'] as $code) {
    same(State::NotReadYet, RecordRead::state($code, $never)->state, 'No complete read yet: '.$code);
    same(State::CouldntRead, RecordRead::state($code, $failed)->state, 'The last read failed: '.$code);
    same(State::Checking, RecordRead::state($code, new Read(100, null, true))->state, 'Reading now: '.$code);
}
$odd = RecordRead::state('future_code', $good);
same([State::NotRecognized, 'code', 'future_code'], [$odd->state, $odd->code?->kind, $odd->code?->text], 'An unmapped code reads Not recognized and keeps the code');
same([State::NotRecognized, null], [RecordRead::state('<b>Some text</b>', $good)->state, RecordRead::state('<b>Some text</b>', $good)->code], 'Provider text that isn\'t a code never echoes');

// A provider code spelled like one of our words is still only a code: Not recognized, with the code for staff.
foreach ([RecordRead::state('failed', new Read(null, 200)), RecordRead::state('Active', $good), InvoiceStatus::state('unpaid', null, '2026-11-12')] as $coded) {
    same([State::NotRecognized, 'code'], [$coded->state, $coded->code?->kind], 'A code that matches a word falls back: '.$coded->code?->text);
}
same(['failed', 'Active', 'unpaid'], [RecordRead::state('failed', new Read(null, 200))->code?->text, RecordRead::state('Active', $good)->code?->text, InvoiceStatus::state('unpaid', null, '2026-11-12')->code?->text], 'The code keeps its own spelling');

// Paymenter invoices.
same(State::Paid, InvoiceStatus::state('paid', '2026-11-01', '2026-11-12')->state, 'Paid');
same(State::Cancelled, InvoiceStatus::state('cancelled', null, '2026-11-12')->state, 'Cancelled');
same(State::AwaitingPayment, InvoiceStatus::state('pending', '2026-11-19 00:00:00', '2026-11-12')->state, 'Not due yet');
same(State::AwaitingPayment, InvoiceStatus::state('pending', '2026-11-12', '2026-11-12')->state, 'Due today is still awaiting payment');
same(State::Unpaid, InvoiceStatus::state('pending', '2026-11-10', '2026-11-12')->state, 'Past due is Unpaid');
same(State::AwaitingPayment, InvoiceStatus::state('pending', null, '2026-11-12')->state, 'No due date is awaiting payment');
same([State::NotRecognized, 'refunded'], [InvoiceStatus::state('refunded', null, '2026-11-12')->state, InvoiceStatus::state('refunded', null, '2026-11-12')->code?->text], 'A status Paymenter adds later');
$terms = \Paymenter\Extensions\Servers\Domains\Presentation\RenewalPayment::line(true, 'USD 14.99', true, 'Visa 4242', ['issue'=>'2027-02-01','due'=>'2027-02-08']);
foreach (['USD 14.99','Visa 4242'] as $fact) { expect(str_contains($terms,$fact),'Charge terms expose the amount and owned method'); }
$nativeSuccessful = true; echo "Panel availability passed.\n";
