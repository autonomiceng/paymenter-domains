<?php
// Synthetic Enom 444f31c registration reconciliation scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, DateEvidence, Disposition, DriverResult, Environment, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationClaims, OperationReconciliation, RegistrarRegistry};
$observation = (object) ['name' => '', 'years' => 1, 'fault' => '', 'calls' => [], 'during' => null];
$wire = new class($observation) implements Transport {
    public function __construct(private $state) {}
    public function send(Environment $env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'Provider reads outside financial locks');
        expect($timeoutMs > 0 && $timeoutMs <= 25000, 'Bounded read timeout'); $s = $this->state; $s->calls[] = $command;
        if ($s->during) { $hook = $s->during; $s->during = null; $hook(); }
        $expiry = (2026 + $s->years).'-10-18'; if ($s->fault === 'term') { $expiry = '2026-10-18'; }
        $item = '<Description>'.$s->name.'</Description><ProductType>Register</ProductType><Quantity>'.$s->years.'</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime>';
        $data = match ($command) {
            ReadCommand::Info => '<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>account-enom</Belongs-To><Expiration>'.$expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::Expiry => '<ExpirationDate>'.$expiry.'</ExpirationDate>',
            ReadCommand::Renewal => '<RenewName>0</RenewName>', ReadCommand::Lock => '<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy => '<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Order => '<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><BillDate>10/18/2026 5:00:01 AM</BillDate><OrderDetail>'.$item.'</OrderDetail>'.($s->fault === 'multiple' ? '<OrderDetail>'.$item.'</OrderDetail>' : '').'</Order>',
            ReadCommand::Orders => '<OrderCount>1</OrderCount><OrderList><Order><OrderID>9001</OrderID></Order></OrderList>',
            default => '',
        };
        if ($s->fault === 'zero' && $command === ReadCommand::Order) { $data = ''; }
        if (($s->fault === 'info_missing' && $command === ReadCommand::Info) || ($s->fault === 'expiry_missing' && $command === ReadCommand::Expiry)) { $data = ''; }
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$registry = new RegistrarRegistry;
$verify = new class implements AccountVerifier { public function verify(Environment $e, array $c): string { return $c['token']; } };
$registry->register('enom', ['token'], $verify, fn ($binding, $c) => new Driver(new Client($binding, 'account-enom', $c['token'], $wire), ['com']));
$readBindings = new AccountBindings($registry); $reconcile = new OperationReconciliation($claims, $readBindings, $clock);
$prepare = static function (string $name, string $mode = 'accepted') use ($new, $claims, $preflight, $clock, $observation) {
    $clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
    $op = $new($name); [$claimed, $domain, $binding] = $claims->claim($op->id);
    if ($mode === 'preseal') { $clock->instant = $clock->now()->modify('+60 seconds'); }
    $ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
    $sealed = $claims->seal($claimed, $preflight->inspect($claimed, $domain, $ctx)); expect($sealed !== null, 'Original attempt sealed');
    if ($mode === 'late') { $clock->instant = $clock->now()->modify('+361 seconds'); }
    if (!in_array($mode, ['absent', 'preseal'], true)) {
        $result = $mode === 'null' ? null : new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
            new Evidence(registration: new RegistrationEvidence(OperationClaims::query($sealed, $domain), '9001', new DateEvidence('2027-10-17', ExpiryPrecision::Date, 'synthetic_purchase'), null, ['nameservers'])));
        $claims->finish($sealed, $result);
    }
    $observation->name = $name; $observation->years = $op->term; $observation->fault = ''; $observation->calls = []; $observation->during = null;
    return [$op->fresh(), $binding];
};
