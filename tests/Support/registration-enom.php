<?php
// Adapted from Enom 444f31c dispatch, NS correction and unknown-outcome scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/registration-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/owner-pages.php';
storefrontViews();
$createOperation = $new;
$new = static function (string $name, bool $paid = true, bool $setup = true, string $action = 'register', int $term = 1) use ($createOperation) {
    return $createOperation(reservedFixtureName($name), $paid, $setup, $action, $term);
};
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
$ownerPage = static function ($result) use ($user, $clock): string {
    $summary = (new DomainSummaries)->one($user, 'managed', (int) $result->domain->id, $clock->now()->getTimestamp());
    return view('domains::partials.summary', ['summary' => $summary, 'summaryOnManaged' => true])->render();
};
$binding = $bindingFor(0);
$completionFailures = 0;
\Paymenter\Extensions\Servers\Domains\Models\Operation::updating(static function ($op) use ($registration, &$completionFailures): void {
    if ($registration->mode === 'finishfailure' && isset($op->attempt['execution']['disposition']) && $completionFailures++ === 0) {
        throw new RuntimeException('Synthetic transient completion failure');
    }
});
$exchange = static function ($origin, $command, $parameters, $timeout) use ($registration): string {
    same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'Registration wire outside locks');
    same('https://resellertest.enom.com/interface.asp', $origin, 'Fixed Enom origin'); expect($timeout > 0 && $timeout <= 25000, 'Bounded timeout');
    $registration->calls[] = $command;
    if ($command === 'Purchase') {
        $registration->purchases[] = $parameters;
        if($registration->mode==='defaultdns'){same('default',$parameters['UseDNS']??null,'Unconfigured Purchase uses registrar defaults');expect(!isset($parameters['NS1']),'No custom nameservers without setup');}
        else{expect(!isset($parameters['UseDNS']),'Configured Purchase sends explicit nameservers');same(['ns1.provider.example','ns2.provider.example'],[$parameters['NS1']??null,$parameters['NS2']??null],'Frozen initial Purchase nameservers');}
        same(0, $parameters['RenewName'], 'Provider renewal off'); same('no', $parameters['UseCreditCard'], 'Account funds only');
        expect(isset($parameters['AuxBillingFirstName']), 'Frozen four-role casing');
        if ($registration->mode === 'authority') {
            Illuminate\Support\Facades\DB::table('domains_domains')->where('domain', $parameters['SLD'].'.'.$parameters['TLD'])->update(['authority_generation' => 2]);
        }
        if ($registration->mode === 'unknown') { return '<invalid/>'; }
    }
    if ($command === 'ModifyNS' && in_array($registration->mode, ['lost', 'authority', 'finishfailure'], true)) { throw new RuntimeException('Synthetic lost ancillary response'); }
    if ($command === 'GetDNS' && in_array($registration->mode, ['lost', 'authority'], true)) { throw new RuntimeException('Synthetic nameserver readback unavailable'); }
    $data = $command === 'Purchase' ? '<OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>' : '';
    if ($command === 'Purchase') {
        if (in_array($registration->mode, ['pending', 'processing', 'queued'], true)) { $data .= '<OrderStatus>'.$registration->mode.'</OrderStatus>'; }
        if ($registration->mode === 'missingid') { $data = '<ExpirationDate>2027-10-18</ExpirationDate>'; }
        if ($registration->mode === 'invalidid') { $data = '<OrderID>0</OrderID><ExpirationDate>2027-10-18</ExpirationDate>'; }
        if ($registration->mode === 'missingexpiry') { $data = '<OrderID>1234</OrderID>'; }
        if ($registration->mode === 'invalidexpiry') { $data = '<OrderID>1234</OrderID><ExpirationDate>2027-02-30</ExpirationDate>'; }
    }
    if ($command === 'GetDNS') { $data = '<dns>ns1.provider.example</dns><dns>'.($registration->mode === 'mismatch' ? 'ns3.provider.example' : 'ns2.provider.example').'</dns>'; }
    $code = $registration->mode === '1300' ? '1300' : '200';
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>'.$code.'</RRPCode>'.$data.'</interface-response>';
};
