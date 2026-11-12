<?php
// Synthetic Enom 444f31c registration reconciliation scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, DateEvidence, Disposition, DriverResult, Environment, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationClaims, OperationReconciliation, RegistrarRegistry};
$observation = (object) ['name' => '', 'nameservers'=>[], 'years' => 1, 'expiryYears' => null, 'reference' => '9001', 'fault' => '', 'billDate' => '10/18/2026 6:00:01 AM', 'calls' => [], 'during' => null];
$wire = new class($observation) implements Transport {
    public function __construct(private $state) {}
    public function send(Environment $env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'Provider reads outside financial locks');
        if ($this->state->fault === 'transport') { throw new RuntimeException('Synthetic transport unavailable'); }
        if ($this->state->fault === 'send_guard') { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit('send_guard'); }
        expect($timeoutMs > 0 && $timeoutMs <= 25000, 'Bounded read timeout'); $s = $this->state; $s->calls[] = $command;
        if ($s->during) { $hook = $s->during; $s->during = null; $hook(); }
        if ($s->fault === 'absent' && $command === ReadCommand::Info) {
            return '<interface-response><Command>'.$command->value.'</Command><ErrCount>1</ErrCount><Done>true</Done><errors><Err1>Domain name not found</Err1></errors>'
                .'<ResponseCount>1</ResponseCount><responses><response><ResponseNumber>301029</ResponseNumber><ResponseString>Validation error; not found; domain name(s)</ResponseString></response></responses></interface-response>';
        }
        $expiry = (2026 + ($s->expiryYears ?? $s->years)).'-10-18'; if ($s->fault === 'term') { $expiry = '2026-10-18'; }
        $item = '<Description>'.($s->fault === 'history-zero' ? 'unrelated.example.com' : $s->name).'</Description><ProductType>Register</ProductType><Quantity>'.$s->years.'</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime>';
        $data = match ($command) {
            ReadCommand::Info => '<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>'.($s->fault==='owner'?'another-account':'account-enom').'</Belongs-To><Expiration>'.$expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::Nameservers => implode('',array_map(fn($ns)=>'<dns>'.$ns.'</dns>',$s->nameservers)),
            ReadCommand::Expiry => '<ExpirationDate>'.$expiry.'</ExpirationDate>',
            ReadCommand::Renewal => '<RenewName>0</RenewName>', ReadCommand::Lock => '<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy => '<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Order => '<Order><OrderID>'.($s->fault === 'history-multiple' ? $parameters['OrderID'] : $s->reference).'</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><BillDate>'.$s->billDate.'</BillDate><OrderDetail>'.$item.'</OrderDetail>'.($s->fault === 'multiple' ? '<OrderDetail>'.$item.'</OrderDetail>' : '').'</Order>',
            ReadCommand::Orders => '<OrderCount>'.($s->fault === 'history-multiple' ? '2' : '1').'</OrderCount><OrderList><Order><OrderID>'.$s->reference.'</OrderID></Order>'
                    .($s->fault === 'history-multiple' ? '<Order><OrderID>9002</OrderID></Order>' : '').'</OrderList>',
            default => '',
        };
        if ($command === ReadCommand::Order) {
            $data = match ($s->fault) {
                'wrong-id' => str_replace('<OrderID>'.$s->reference.'</OrderID>', '<OrderID>9999</OrderID>', $data),
                'wrong-name' => str_replace('<Description>'.$s->name.'</Description>', '<Description>other.example.com</Description>', $data),
                'open' => str_replace('<OrderStatus>Closed</OrderStatus>', '<OrderStatus>Open</OrderStatus>', $data),
                'failed' => str_replace('<Status>Successful</Status>', '<Status>Failed</Status>', $data),
                'wrong-action' => str_replace('<ProductType>Register</ProductType>', '<ProductType>Renew</ProductType>', $data),
                'wrong-years' => str_replace('<Quantity>'.$s->years.'</Quantity>', '<Quantity>2</Quantity>', $data),
                'wrong-charge' => str_replace('8.95', '8.96', $data),
                'wrong-currency' => str_replace('<Result>', '<Currency>EUR</Currency><Result>', $data),
                'wrong-expiry' => str_replace('<ExpiryDateTime>'.$expiry.'</ExpiryDateTime>', '<ExpiryDateTime>2027-10-19</ExpiryDateTime>', $data),
                default => $data,
            };
        }
        if ($s->fault === 'captured-charge') { $data = str_replace('8.95', '6.00', $data); }
        if ($s->fault === 'zero' && $command === ReadCommand::Order) { $data = ''; }
        if (($s->fault === 'info_missing' && $command === ReadCommand::Info) || ($s->fault === 'expiry_missing' && $command === ReadCommand::Expiry)) { $data = ''; }
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$registry = new RegistrarRegistry;
$verify = new class implements AccountVerifier { public function verify(Environment $e, array $c): string { return $c['token']; } };
$registry->register('enom', ['token'], $verify, fn ($binding, $c) => new Driver(new Client($binding, 'account-enom', $c['token'], $wire), [$fixtureEnding ?? 'com']));
$readBindings = new AccountBindings($registry); $reconcile = new OperationReconciliation($claims, $readBindings, $clock);
$caps??=null;$ports??=null;
$prepare = static function (string $name, string $mode = 'accepted', ?\Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence $nameservers = null) use ($new, $claims, $preflight, $clock, $observation,$caps,$ports) {
    $clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
    if($caps!==null){activeCommerceFacts($clock->now(),$clock->now()->modify('+1 day'),$ports);}
    $op = $new($name); [$claimed, $domain, $binding] = $claims->claim($op->id);
    if ($mode === 'preseal') { $clock->instant = $clock->now()->modify('+60 seconds'); }
    $ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
    $sealed = $claims->seal($claimed, $preflight->inspect($claimed, $domain, $ctx), $clock->now()); expect($sealed !== null, 'Original attempt sealed');
    if ($mode === 'late') { $clock->instant = $clock->now()->modify('+361 seconds'); }
    if (!in_array($mode, ['absent', 'preseal'], true)) {
        $result = $mode === 'null' ? null : new DriverResult($binding, $mode === 'captured' ? Disposition::Unknown : Disposition::Accepted, SendState::Sent, $mode === 'captured' ? ResultReason::Unresolved : ResultReason::Acknowledged,
            new Evidence(registration: new RegistrationEvidence(OperationClaims::query($sealed, $domain), '9001', $mode === 'captured' ? null : new DateEvidence('2027-10-17', ExpiryPrecision::Date, 'synthetic_purchase'), null, $nameservers?->state === 'observed' ? [] : ['nameservers'], $nameservers)));
        $claims->finish($sealed, $result);
    }
    $observation->name = $name; $observation->years = $op->term; $observation->expiryYears = null; $observation->reference = '9001';
    $observation->fault = ''; $observation->billDate = '10/18/2026 6:00:01 AM'; $observation->calls = []; $observation->during = null;
    return [$op->fresh(), $binding];
};
