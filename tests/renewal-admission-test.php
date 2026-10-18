<?php
// Enom 444f31c renewal-admission/observation-integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RenewalCode, RenewalRequest, RenewalStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, RenewalAcceptance, RenewalAdmission, RenewalAuthority, RegistrarRegistry};
[$original, $binding] = $prepare('renew-held.com');
same('succeeded', $reconcile->run($original->id)->state, 'Prior completion uses real reconciliation');
$domain = $original->domain()->firstOrFail();
$request = static fn (int $years = 1) => new RenewalRequest($domain->id, 1, 1, '2027-10-18', '456', $years);
$state = (object) ['name' => $domain->domain, 'expiry' => '10/18/2027 5:00:00 AM', 'day' => '10/18/2027',
    'price' => '8.95', 'balance' => '100.00', 'owner' => 'account-enom', 'id' => '456', 'auto' => '0',
    'premium' => '0', 'hold' => 'False', 'maximum' => '8', 'expired' => 'Expired', 'during' => null, 'duringCommand' => null, 'calls' => [], 'fail' => null];
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
            default => throw new LogicException('No spending or unbounded command permitted'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, 'account-enom', $c['token'], $transport), ['com']));
$renewBindings = new AccountBindings($registry); $authority = new RenewalAuthority($renewBindings, $clock);
$service = new RenewalAdmission($authority, $renewBindings, new RenewalAcceptance($binding, true, true, 25, true), $clock);
$check = static function (?RenewalRequest $r = null) use ($service, $request, $state) {
    Cache::flush(); $state->calls = []; return $service->check($r ?? $request());
};
$decision = $check(); same(RenewalCode::Admitted, $decision->code, 'Ordinary known-cost renewal admitted');
same(RenewalStatus::Ordinary, $decision->status, 'Ordinary state retained'); same('8.9500', $decision->cost->amount, 'Exact cost retained');
same(8, count($state->calls), 'Bounded actual read sequence');
same('26.8500', $check($request(3))->cost?->amount, 'Exact supported three-year renewal total');
$state->price = '99.00'; $decision = $check(); same(RenewalCode::Admitted, $decision->code, 'Below-cost renewals remain admitted');
same('below_cost', $decision->staffAlert, 'Below-cost renewal alerts staff'); $state->price = '8.95';
expect(!str_contains(json_encode($decision), '99') && !str_contains(print_r($decision, true), '99'), 'Generic summaries redact money');
commerceReject(fn () => serialize($decision));
$before = serialize(DB::table('invoices')->get()); $opsBefore = serialize(DB::table('domains_operations')->get());
$check(); same($before, serialize(DB::table('invoices')->get()), 'Admission never changes invoices');
same($opsBefore, serialize(DB::table('domains_operations')->get()), 'Admission never claims/seals/creates operations');
same(null, $domain->service->fresh()->expires_at, 'Admission never advances native expiry');
$noAcceptance = new RenewalAdmission($authority, $renewBindings, new RenewalAcceptance($binding), $clock);
same(RenewalCode::Acceptance, $noAcceptance->check($request())->code, 'Account policy defaults closed');
Cache::flush(); same(RenewalCode::Admitted, $service->check($request())->code, 'First account slot admitted');
$calls = count($state->calls); same(RenewalCode::Rate, $service->check($request())->code, 'Nonblocking shared account slot');
same($calls, count($state->calls), 'Rate refusal does no reads');
foreach (['auto' => ['1', '?'], 'balance' => ['8.94', '?'], 'price' => ['?', '8.95001'], 'premium' => ['1', '?'],
    'maximum' => ['0', '?'], 'hold' => ['True', '?'], 'owner' => ['other-account'], 'id' => ['457']] as $field => $values) {
    $saved = $state->$field;
    foreach ($values as $value) { $state->$field = $value; expect(!$check()->allowed(), 'Unsafe fresh '.$field.' refused'); }
    $state->$field = $saved;
}
$state->auto = '1'; same(RenewalCode::AutoRenew, $check()->code, 'Enabled provider renewal gives staff-visible reason'); $state->auto = '0';
$state->balance = '8.95'; same(RenewalCode::Admitted, $check()->code, 'Exact sufficient balance boundary');
$state->balance = '999999999999999999.99'; same(RenewalCode::Admitted, $check()->code, 'Full precision balance comparison without floats'); $state->balance = '100.00';
$state->fail = ReadCommand::Balance; same(RenewalCode::Balance, $check()->code, 'Unknown balance refuses'); $state->fail = null;
$state->price = '?'; same(RenewalCode::Cost, $check()->code, 'Unknown price never becomes zero'); $state->price = '8.95';
foreach ([new RenewalRequest($domain->id, 2, 1, '2027-10-18', '456'), new RenewalRequest($domain->id, 1, 2, '2027-10-18', '456'),
    new RenewalRequest($domain->id, 1, 1, '2027-10-19', '456'), new RenewalRequest($domain->id, 1, 1, '2027-10-18', '457')] as $stale) {
    same(RenewalCode::Anchor, $check($stale)->code, 'Saved generation/revision/expiry/provider-ID must match'); same([], $state->calls, 'Stale anchors do no reads');
}
DB::transaction(function () use ($service, $request): void { commerceReject(fn () => $service->check($request())); });
// The non-selling holding account keeps renewal authority independently of all new-sale flags.
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
$tlds[0]->update(['enabled' => false, 'register_enabled' => false, 'transfer_enabled' => false, 'renew_enabled' => false]);
same(RenewalCode::Admitted, $check()->code, 'Seller/flags withdrawal cannot strand holder');
$clock->instant = new DateTimeImmutable('2027-10-18T11:59:59Z'); same(RenewalStatus::Ordinary, $check()->status, 'Precise expiry instant selects ordinary');
same('2027-10-18T12:00:00+00:00', $check()->validUntil?->format(DATE_ATOM), 'Ordinary validity never crosses expiry');
$state->duringCommand = ReadCommand::Renewal;
$state->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-18T12:00:01Z');
same(RenewalCode::Changed, $check($request(3))->code, 'Multi-year ordinary read crossing expiry cannot bypass grace');
$state->duringCommand = null;
$clock->instant = new DateTimeImmutable('2027-10-18T12:00:00Z'); $decision = $check();
same(RenewalCode::Admitted, $decision->code, 'Exact expiry boundary admits proven one-year grace'); same(RenewalStatus::Grace, $decision->status, 'Grace from provider inventory');
same(9, count($state->calls), 'Grace read budget bounded');
$state->hold = 'True'; same(RenewalCode::Admitted, $check()->code, 'Inventory-proven grace may retain registrar hold'); $state->hold = 'False';
$graceOff = new RenewalAdmission($authority, $renewBindings, new RenewalAcceptance($binding, true, true, 25), $clock);
Cache::flush(); same(RenewalCode::Grace, $graceOff->check($request())->code, 'Grace defaults closed');
expect(!$check($request(3))->allowed(), 'Multi-year grace refused');
foreach (['Redemption', 'RedemptionPeriod', 'Restorable', 'Unknown', 'PendingDelete'] as $status) {
    $state->expired = $status; $decision = $check(); expect(!$decision->allowed(), 'Restore/unknown expired state refuses');
    if (in_array($status, ['Redemption', 'RedemptionPeriod', 'Restorable'], true)) { same(RenewalCode::Restore, $decision->code, 'Explicit restoration refusal'); }
}
$state->expired = 'Expired';
$state->price = '99.00'; same(RenewalCode::Admitted, $check()->code, 'Grace has no original-retail floor'); $state->price = '8.95';
$clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
// Every final authority check uses fresh locked rows after provider I/O.
$changes = [
    ['domains_domains', $domain->id, ['confirmed_revision' => 2]],
    ['domains_domains', $domain->id, ['authority_generation' => 2]],
    ['domains_domains', $domain->id, ['provider_domain_id' => '999']],
    ['domains_domains', $domain->id, ['provider_expires_at' => '2027-10-19']],
    ['domains_domains', $domain->id, ['reservation_name' => null]],
    ['services', $domain->service_id, ['status' => 'cancelled']],
    ['services', $domain->service_id, ['user_id' => $user->id + 100]],
    ['products', $product->id, ['server_id' => $tlds[1]->server_id]],
    ['domains_operations', $original->id, ['state' => 'uncertain']],
];
foreach ($changes as [$table, $id, $changeset]) {
    $saved = (array) DB::table($table)->where('id', $id)->first();
    $state->during = static fn () => DB::table($table)->where('id', $id)->update($changeset);
    expect(!$check()->allowed(), 'Post-read changed '.$table.' refuses'); DB::table($table)->where('id', $id)->update($saved);
}
$state->during = static fn () => $bindings->setProcessing($binding->serverId, false);
expect(!$check()->allowed(), 'Processing pause during reads refuses'); $bindings->setProcessing($binding->serverId, true);
$state->during = static function () use ($bindings, $binding): void {
    $bindings->configure($binding->serverId, 'enom', Environment::Test, ['token' => 'account-enom']);
    $bindings->setProcessing($binding->serverId, true);
};
same(RenewalCode::Changed, $check()->code, 'Same-account verified rotation requires a fresh admission');
same(RenewalCode::Admitted, $check()->code, 'Fresh attempt revalidates rotated binding');
$state->during = static fn () => $clock->instant = $clock->now()->modify('+251 seconds');
expect(!$check()->allowed(), 'Expired bounded read window refuses');
$clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
$domain->refresh()->update(['renewal_policy' => [...$domain->renewal_policy, 'automatic_spend_paused' => true]]);
same(RenewalCode::Paused, $check()->code, 'Automatic-spend pause respected'); same([], $state->calls, 'Paused admission performs no reads');
echo "Renewal admission ordinary/grace/restore, exact costs and locked authority passed\n"; $nativeSuccessful = true;
