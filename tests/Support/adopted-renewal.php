<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic transport only.
declare(strict_types=1);
require_once dirname(__DIR__).'/native-app.php';
require_once __DIR__.'/adoption-holding.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainBilling, HolderPricing, NativeDomainGuard, RegistrarRegistry, RenewalAcceptance, RenewalAdmission, RenewalAuthority, RenewalCompletion, RenewalExecution, RenewalWriter, WriteFeatures};
function adoptedRenewalFixture(Environment $environment = Environment::Live, bool $retainedHistory = false): array
{
    config(['app.url' => 'https://domains-tests.invalid']);
    $f = adoptionHoldingFixture($environment, $retainedHistory ? static function ($f, $d): void {
        \Paymenter\Extensions\Servers\Domains\Models\Operation::create(['domain_id' => $d->id, 'service_id' => $f['service']->id,
            'server_id' => $d->server_id, 'registrar_key' => $d->registrar_key, 'account_fingerprint' => $d->account_fingerprint,
            'authority_generation' => $d->authority_generation, 'action' => 'register', 'operation_key' => 'retained-definitely-unsent',
            'term' => 1, 'currency' => 'USD', 'amount' => '10.00', 'state' => 'rejected', 'policy' => [], 'payload' => [],
            'attempt' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent']]);
    } : null); extract($f);
    attachHolding($f); $bindings->setProcessing($binding->serverId, true); $domain->refresh(); $service->update(['status' => 'active']);
    \App\Models\Setting::updateOrCreate(['key'=>'credits_auto_use','settingable_type'=>null], ['value'=>false,'type'=>'boolean']);
$state = (object) ['name' => '', 'expiry' => '10/20/2027 5:00:00 AM', 'day' => '10/20/2027',
    'price' => '8.95', 'balance' => '100.00', 'owner' => 'account-enom', 'id' => '123', 'auto' => '0',
    'premium' => '0', 'hold' => 'False', 'maximum' => '8', 'expired' => 'Expired', 'during' => null, 'duringCommand' => null, 'calls' => [], 'fail' => null, 'years' => 1, 'writes' => 0, 'lost' => false, 'after' => '2028-10-20', 'charge' => '8.95', 'order' => '9002', 'orderYear' => '2027', 'multiple' => false, 'afterWrite' => null, 'lastWrite' => null];
$transport = new class($state) implements Transport {
    public function __construct(private $s) {}
    public function send(Environment $env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        same(0, DB::transactionLevel(), 'Every fresh read is outside locks');
        expect($timeoutMs > 0 && $timeoutMs <= 25000, 'Per-request deadline bounded');
        $s = $this->s; $s->calls[] = $command;
        if ($s->during && ($s->duringCommand === null || $s->duringCommand === $command)) { $hook = $s->during; $s->during = null; $hook(); }
        if ($s->fail === $command) { throw new RuntimeException('synthetic-private-token'); }
        $data = match ($command) {
            ReadCommand::Info => '<GetDomainInfo><DomainName DomainNameID="'.$s->id.'">'.$s->name.'</DomainName><Status><Belongs-To>'.$s->owner.'</Belongs-To><Expiration>'.$s->expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::RenewalInfo => '<Expiration>'.$s->day.'</Expiration><Price>'.$s->price.'</Price><RegistrarHold>'.$s->hold.'</RegistrarHold><MaxExtension>'.$s->maximum.'</MaxExtension>',
            ReadCommand::Check => '<Domains><Domain><Name>'.$s->name.'</Name><RRPCode>211</RRPCode><IsPremium>'.$s->premium.'</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Prices><Currency/><Renewal>'.$s->price.'</Renewal></Prices></Domain></Domains>',
            ReadCommand::Renewal => '<RenewName>'.$s->auto.'</RenewName>',
            ReadCommand::Lock => '<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy => '<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Balance => '<AvailableBalance>'.$s->balance.'</AvailableBalance>',
            ReadCommand::Expired => '<DomainCount>1</DomainCount><DomainDetail><DomainName>'.$s->name.'</DomainName><DomainNameID>'.$s->id.'</DomainNameID><Status>'.$s->expired.'</Status><Expiration-Date>'.$s->day.'</Expiration-Date></DomainDetail>',
            ReadCommand::Expiry => '<ExpirationDate>'.$s->after.'</ExpirationDate>',
            ReadCommand::Order => '<Order><OrderID>'.$s->order.'</OrderID><Result>true</Result><OrderBillAmount>$'.$s->charge.'</OrderBillAmount><BillDate>10/20/'.$s->orderYear.' 5:00:01 AM</BillDate><OrderDetail><Description>'.$s->name.'</Description><ProductType>Renew</ProductType><Quantity>'.$s->years.'</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>'.$s->charge.'</AmountPaid><ExpiryDateTime>'.$s->after.'</ExpiryDateTime></OrderDetail></Order>',
            default => throw new LogicException('No spending or unbounded command permitted'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};

    $registry = new RegistrarRegistry;
    $registry->register('enom', ['uid', 'token'], new class implements AccountVerifier {
        public function verify(Environment $e, array $c): string { return $c['uid']; }
    }, fn ($b, $c) => new Driver(new Client($b, $c['uid'], $c['token'], $transport), ['com']));
    $renewBindings = new AccountBindings($registry); $authority = new RenewalAuthority($renewBindings, $clock);
    $guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot();
    $billing = new DomainBilling($renewBindings, $guard, $clock);
    $state->name = 'example.com'; $state->price = '8.00';
    $clock->time = new DateTimeImmutable('2027-09-29T12:00:00Z');
    \App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'], ['value'=>true,'type'=>'boolean']);
$compose = static function ($binding, bool $enabled = true, bool $grace = true) use ($renewBindings, $authority, $clock, $state) {
    $accepted = new RenewalAcceptance($binding, true, true, 25, $grace); $features = new WriteFeatures(renewal: $enabled);
    $service = new RenewalAdmission($authority, $renewBindings, $accepted, $clock);
    $engine = new RenewalExecution($renewBindings, $authority, $service, $accepted, $features, $clock);
    $writer = new RenewalWriter($binding, $features, $accepted, fn () => $engine, static function ($origin, $command, $params, $timeout) use ($state) {
        same(0, DB::transactionLevel(), 'Renewal wire outside locks'); expect($timeout > 0 && $timeout <= 25000, 'Bounded write timeout');
        same('https://reseller.enom.com/interface.asp', $origin, 'Live origin is intercepted by the synthetic sender');
        same('no', $params['UseCreditCard'], 'Account balance only'); $state->writes++; $state->lastWrite = [$command,$params];
        if ($state->afterWrite) { ($state->afterWrite)(); }
        if ($state->lost) { throw new RuntimeException('Synthetic lost response'); }
        if ($command === 'UpdateExpiredDomains') { return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><ReactivateDomainName><Status>true</Status><OrderID>'.$state->order.'</OrderID></ReactivateDomainName></interface-response>'; }
        return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>'.$state->order.'</OrderID></interface-response>';
    });
    return [$engine, $writer, new RenewalCompletion($renewBindings, $clock)];
};

    return [...$f, ...compact('state', 'authority', 'billing', 'compose', 'renewBindings')];
}
