<?php
// Synthetic Enom d1392a8 managed renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-fixture.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainBilling, HolderPricing, NativeDomainGuard, RenewalAcceptance, RenewalAdmission, RenewalAuthority, RenewalCompletion, RenewalCycles, RenewalExecution, RenewalFunding, RenewalWriter, RegistrarRegistry, WriteFeatures, HoldingLocks};
// Shared scenarios fund invoices explicitly; credit-policy cases override this immediately before billing.
\App\Models\Setting::updateOrCreate(['key'=>'credits_auto_use','settingable_type'=>null], ['value'=>false,'type'=>'boolean']);
$state = (object) ['name' => '', 'expiry' => '10/18/2027 5:00:00 AM', 'day' => '10/18/2027',
    'price' => '8.95', 'balance' => '100.00', 'owner' => 'account-enom', 'id' => '456', 'auto' => '0',
    'premium' => '0', 'hold' => 'False', 'maximum' => '8', 'expired' => 'Expired', 'during' => null, 'duringCommand' => null, 'calls' => [], 'fail' => null, 'years' => 1, 'writes' => 0, 'lost' => false, 'after' => '2028-10-18', 'charge' => '8.95', 'order' => '9002', 'orderYear' => '2027', 'billTime' => '5:00:01 AM', 'multiple' => false, 'afterWrite' => null, 'lastWrite' => null];
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
            ReadCommand::RenewalInfo => '<GetExtendInfo><Expiration>'.$s->day.'</Expiration><Price>'.$s->price.'</Price><RegistrarHold>'.$s->hold.'</RegistrarHold><MaxExtension>'.$s->maximum.'</MaxExtension></GetExtendInfo>',
            ReadCommand::Check => '<Domains><Domain><Name>'.$s->name.'</Name><RRPCode>211</RRPCode><IsPremium>'.$s->premium.'</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Prices><Currency/><Renewal>'.$s->price.'</Renewal></Prices></Domain></Domains>',
            ReadCommand::Renewal => '<RenewName>'.$s->auto.'</RenewName>',
            ReadCommand::Lock => '<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy => '<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Balance => '<AvailableBalance>'.$s->balance.'</AvailableBalance>',
            ReadCommand::Expired => '<DomainCount>1</DomainCount><DomainDetail><DomainName>'.$s->name.'</DomainName><DomainNameID>'.$s->id.'</DomainNameID><Status>'.$s->expired.'</Status><Expiration-Date>'.$s->day.'</Expiration-Date></DomainDetail>',
            ReadCommand::Expiry => '<ExpirationDate>'.$s->after.'</ExpirationDate>',
            ReadCommand::Order => '<Order><OrderID>'.$s->order.'</OrderID><Result>true</Result><OrderBillAmount>$'.$s->charge.'</OrderBillAmount><BillDate>10/18/'.$s->orderYear.' '.$s->billTime.'</BillDate><OrderDetail><Description>'.$s->name.'</Description><ProductType>Renew</ProductType><Quantity>'.$s->years.'</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>'.$s->charge.'</AmountPaid><ExpiryDateTime>'.$s->after.'</ExpiryDateTime></OrderDetail></Order>',
            default => throw new LogicException('No spending or unbounded command permitted'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, 'account-enom', $c['token'], $transport), ['com']));
$renewBindings = new AccountBindings($registry); $authority = new RenewalAuthority($renewBindings, $clock);
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); Queue::fake();
$billing = new DomainBilling($bindings, $guard, $clock); $cycles = new RenewalCycles($bindings, $clock);
$makeRenewal = static function (string $name, bool $paid = true, int $years = 1, bool $credit = false, ?Closure $beforeBill = null) use ($prepare, $reconcile, $clock, $billing, $state, $product, $user) {
    [$original, $binding] = $prepare($name); same('succeeded', $reconcile->run($original->id)->state, 'Real original charge and expiry anchor');
    $d = $original->domain()->firstOrFail(); $state->name = $name; $state->expiry = '10/18/2027 5:00:00 AM'; $state->day = '10/18/2027';
    $state->years = $years; $state->after = (2027 + $years).'-10-18'; $state->orderYear = '2027'; $state->billTime = '5:00:01 AM'; $state->charge = '8.95'; $state->price = '8.00'; $state->auto = '0';
    $state->owner = 'account-enom'; $state->id = '456'; $state->during = null; $state->calls = []; $state->lost = false; $state->afterWrite = null;
    $clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
    $product->server->settings()->updateOrCreate(['key' => 'domains_manual_renewals_enabled'], ['value' => true, 'type' => 'boolean']);
    if ($credit) { $user->credits()->create(['currency_code'=>'USD','amount'=>'100.00']); }
    if ($beforeBill) { $beforeBill($d); }
    $invoice = $billing->invoice($d->id, $user, $years); $op = Operation::where('invoice_id', $invoice->id)->sole();
    if ($paid) { \App\Helpers\ExtensionHelper::addPayment($invoice, null, $op->amount); }
    // Reads for execution happen near expiry, after the fixed issue/due calendar and stale prices.
    $clock->instant = new DateTimeImmutable('2027-10-18T11:59:00Z'); Cache::flush();
    return [$op->fresh(), $d, $binding, $invoice->fresh()];
};
$compose = static function ($binding, bool $enabled = true, bool $grace = true) use ($renewBindings, $authority, $clock, $state) {
    $accepted = new RenewalAcceptance($binding, true, true, 25, $grace); $features = new WriteFeatures(renewal: $enabled);
    $service = new RenewalAdmission($authority, $renewBindings, $accepted, $clock);
    $engine = new RenewalExecution($renewBindings, $authority, $service, $accepted, $features, $clock);
    $writer = new RenewalWriter($binding, $features, $accepted, fn () => $engine, static function ($origin, $command, $params, $timeout) use ($state) {
        same(0, DB::transactionLevel(), 'Renewal wire outside locks'); expect($timeout > 0 && $timeout <= 25000, 'Bounded write timeout');
        same('https://resellertest.enom.com/interface.asp', $origin, 'Fixed test origin');
        same('no', $params['UseCreditCard'], 'Account balance only'); $state->writes++; $state->lastWrite = [$command,$params];
        if ($state->afterWrite) { ($state->afterWrite)(); }
        if ($state->lost) { throw new RuntimeException('Synthetic lost response'); }
        if ($command === 'UpdateExpiredDomains') { return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><ReactivateDomainName><Status>true</Status><OrderID>'.$state->order.'</OrderID></ReactivateDomainName></interface-response>'; }
        return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>'.$state->order.'</OrderID></interface-response>';
    });
    return [$engine, $writer, new RenewalCompletion($renewBindings, $clock)];
};
$runRenewal = static function ($op, $binding) use ($compose) { [$engine, $writer] = $compose($binding); return $engine->run($op->id, $writer); };
