<?php
// Enom 444f31c renewal-admission/observation-integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/lifecycle-facts.php';
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities, CostUnit, DateEvidence, Disposition, DomainSnapshot, DriverResult, Environment, Evidence, ExpiryPrecision, Money, Query, QuoteEvidence, RenewalCode, RenewalRequest, RenewalStatus, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, RenewalAcceptance, RenewalAdmission, RenewalAuthority, RegistrarRegistry};
[$original, $binding] = $prepare(placeholderComFixtureName('renew-held-placeholder.com'));
same('succeeded', $reconcile->run($original->id)->state, 'Prior completion uses real reconciliation');
$domain = $original->domain()->firstOrFail();
$request = static fn (int $years = 1) => new RenewalRequest($domain->id, 1, 1, '2027-10-18', '456', $years);
$state = (object) ['name' => $domain->domain, 'expiry' => '10/18/2027 5:00:00 AM', 'day' => '10/18/2027',
    'price' => '8.95', 'balance' => '100.00', 'owner' => 'account-enom', 'id' => '456', 'auto' => '0',
    'captured' => false, 'premium' => '0', 'hold' => 'False', 'maximum' => '8', 'expired' => 'Expired', 'during' => null, 'duringCommand' => null, 'calls' => [], 'fail' => null];
$transport = new class($state) implements Transport {
    public function __construct(private $s) {}
    public function send(Environment $env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        same(0, DB::transactionLevel(), 'Every fresh read is outside locks');
        expect($timeoutMs > 0 && $timeoutMs <= 25000, 'Per-request deadline bounded');
        $s = $this->s; $s->calls[] = $command;
        if ($s->during && ($s->duringCommand === null || $s->duringCommand === $command)) { $hook = $s->during; $s->during = null; $hook(); }
        if ($s->fail === $command) { throw new RuntimeException('synthetic-private-token'); }
        $captured = require __DIR__.'/Support/enom-captured-renewal.php';
        if ($s->captured && isset($captured[$command->name])) {
            return str_replace(['example.com', '10/22/2027', '8.95'], [$s->name, $s->day, $s->price], $captured[$command->name]);
        }
        $data = match ($command) {
            ReadCommand::Info => '<GetDomainInfo><DomainName DomainNameID="'.$s->id.'">'.$s->name.'</DomainName><Status><Belongs-To>'.$s->owner.'</Belongs-To><Expiration>'.$s->expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::RenewalInfo => '<Expiration>'.$s->day.'</Expiration><Price>'.$s->price.'</Price><RegistrarHold>'.$s->hold.'</RegistrarHold><MaxExtension>'.$s->maximum.'</MaxExtension>',
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
(require __DIR__.'/Support/renewal-acceptance.php')($binding, $check,
    static fn () => $service->refresh($request()), static fn () => $state->calls,
    static function (Closure $hook) use ($state) { $state->during = $hook; });
$decision = $check(); same(RenewalCode::Admitted, $decision->code, 'Ordinary known-cost renewal admitted');
same(RenewalStatus::Ordinary, $decision->status, 'Ordinary state retained'); same('8.9500', $decision->cost->amount, 'Exact cost retained');
$admissionReads = [ReadCommand::Info, ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Balance];
same($admissionReads, $state->calls, 'Ordinary renewal uses exactly six fresh reads');
foreach (['owner' => ['foreign', RenewalCode::Owner, 4], 'id' => ['999', RenewalCode::Owner, 5],
    'expiry' => ['10/19/2027 5:00:00 AM', RenewalCode::Anchor, 5],
    'same_day_expiry' => ['10/18/2027 5:00:01 AM', RenewalCode::Anchor, 5]] as $case => [$value, $code, $count]) {
    $field = $case === 'same_day_expiry' ? 'expiry' : $case; $saved = $state->$field;
    $state->duringCommand = ReadCommand::Check; $state->during = static function () use ($state, $field, $value) { $state->$field = $value; };
    same($code, $check()->code, 'Drift between admission identities refuses '.$case);
    same(array_slice($admissionReads, 0, $count), $state->calls, 'Identity drift stops at the required fresh proof '.$case);
    $state->$field = $saved;
}
$state->duringCommand = null;
$state->captured = true;
$capturedDecision = $check();
same(RenewalCode::Admitted, $capturedDecision->code, 'Captured flat GetExtendInfo and Check v2 pass full admission');
same(RenewalStatus::Ordinary, $capturedDecision->status, 'Captured pair retains ordinary status through authority rechecks');
same('8.9500', $capturedDecision->cost?->amount, 'Captured empty currency gives exact one-year USD admission cost');
$state->captured = false;
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
$clock->instant = new DateTimeImmutable('2027-10-18T12:00:00Z');
same(RenewalCode::Grace, $check()->code, 'Grace acceptance without lifecycle evidence refuses');
storeLifecycleFixture($binding,lifecycleFixture($clock->now()));
$decision = $check();
same(RenewalCode::Admitted, $decision->code, 'Exact expiry boundary admits proven one-year grace'); same(RenewalStatus::Grace, $decision->status, 'Grace from provider inventory');
same([ReadCommand::Info, ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Expired, ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Balance], $state->calls, 'Grace retains inventory and both fresh identities in seven reads');
$state->hold = 'True'; same(RenewalCode::Admitted, $check()->code, 'Inventory-proven grace may retain registrar hold'); $state->hold = 'False';
$graceOff = new RenewalAdmission($authority, $renewBindings, new RenewalAcceptance($binding, true, true, 25), $clock);
Cache::flush(); same(RenewalCode::Grace, $graceOff->check($request())->code, 'Grace defaults closed');
expect(!$check($request(3))->allowed(), 'Multi-year grace refused');
foreach (['RGP', 'Extended RGP', 'Redemption', 'RedemptionPeriod', 'Restorable', 'Unknown', 'PendingDelete'] as $status) {
    $state->expired = $status; $decision = $check(); expect(!$decision->allowed(), 'Restore/unknown expired state refuses');
    if (in_array($status, ['RGP', 'Extended RGP', 'Redemption', 'RedemptionPeriod', 'Restorable'], true)) { same(RenewalCode::Restore, $decision->code, 'Explicit restoration refusal'); }
}
$state->expired = 'Expired';
// Fresh policy evidence cannot extend a provider's Expired label beyond the verified window.
$clock->instant = new DateTimeImmutable('2027-10-19T12:00:00Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same(RenewalCode::Grace,$check()->code,'Expired inventory at provider expiry plus 24 hours refuses fresh one-day grace');
$clock->instant = new DateTimeImmutable('2027-10-20T12:00:00Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same(RenewalCode::Grace,$check()->code,'Expired inventory on day two refuses even freshly verified facts');
// The engine must also reject unsafe Grace evidence from any accepted reader.
$syntheticGrace = static function (?DateEvidence $expiry) use ($domain,$binding,$verify,$clock,$request) {
    $registry = new RegistrarRegistry;
    $registry->register('enom',['token'],$verify,static function ($b,$c) use ($domain,$clock,$expiry) {
        $query = new Query($domain->domain,\Paymenter\Extensions\Servers\Domains\Data\Action::Renew,1);
        return new \Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($b,new Capabilities($b,[]),
            new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
                new Evidence(domain:new DomainSnapshot($b,$domain->domain,true,$expiry,false,providerReference:'456'),
                    quote:new QuoteEvidence($query,new Money('8.95','USD'),CostUnit::TermTotal,'synthetic_grace',$clock->now(),$clock->now()->modify('+300 seconds')),
                    balance:new Money('100.00','USD'),premium:false,renewal:RenewalStatus::Grace)));
    });
    $bindings = new AccountBindings($registry);
    $admission = new RenewalAdmission(new RenewalAuthority($bindings,$clock),$bindings,new RenewalAcceptance($binding,true,true,25,true),$clock);
    Cache::flush(); return $admission->check($request());
};
$expiry = new DateEvidence('2027-10-18T00:00:00+00:00',ExpiryPrecision::Instant,'synthetic_expiry','UTC');
foreach (['2027-10-18T00:00:01Z'=>RenewalCode::Admitted,'2027-10-18T23:59:00Z'=>RenewalCode::Admitted,
    '2027-10-19T00:00:00Z'=>RenewalCode::Grace,'2027-10-20T00:00:00Z'=>RenewalCode::Grace,
    '2027-10-17T23:59:59Z'=>RenewalCode::Grace] as $at=>$expected) {
    $clock->instant = new DateTimeImmutable($at);
    storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
    same($expected,$syntheticGrace($expiry)->code,'One-day grace interval boundary '.$at);
}
$clock->instant = new DateTimeImmutable('2027-10-18T23:59:59Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same('2027-10-19T00:00:00+00:00',$syntheticGrace($expiry)->validUntil?->format(DATE_ATOM),'Grace validity is capped at its window deadline');
same(RenewalCode::Grace,$syntheticGrace(null)->code,'Grace with unknown expiry refuses');
same(RenewalCode::Grace,$syntheticGrace(new DateEvidence('2027-10-18',ExpiryPrecision::Date,'synthetic_expiry'))->code,'Grace without a parseable expiry instant refuses');
$later = new DateEvidence('2027-10-18T12:00:00+00:00',ExpiryPrecision::Instant,'synthetic_expiry','UTC');
$clock->instant = new DateTimeImmutable('2027-10-18T06:00:00Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same(RenewalCode::Grace,$syntheticGrace($later)->code,'Grace cannot begin before the provider expiry even when local date has passed');
$clock->instant = new DateTimeImmutable('2027-10-19T00:00:00Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same(RenewalCode::Grace,$syntheticGrace($later)->code,'Earlier local expiry deadline wins over a later provider instant');
$earlier = new DateEvidence('2027-10-18T01:00:00+14:00',ExpiryPrecision::Instant,'synthetic_expiry','+14:00');
$clock->instant = new DateTimeImmutable('2027-10-18T10:59:59Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same('2027-10-18T11:00:00+00:00',$syntheticGrace($earlier)->validUntil?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),'Earlier provider deadline wins and days are measured in UTC');
$clock->instant = new DateTimeImmutable('2027-10-18T11:00:00Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
same(RenewalCode::Grace,$syntheticGrace($earlier)->code,'Earlier provider expiry deadline refuses at equality');
// Exercise the real provider reads and final locked authority check across the local deadline.
$clock->instant = new DateTimeImmutable('2027-10-18T23:59:59Z');
storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'grace_days'=>1]);
$state->duringCommand = ReadCommand::Balance;
$state->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-19T00:00:00Z');
same(RenewalCode::Changed,$check()->code,'Grace computed before its deadline refuses when finalized at the deadline');
$clock->instant = new DateTimeImmutable('2027-10-18T23:59:59Z');
$state->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-19T00:00:01Z');
same(RenewalCode::Changed,$check()->code,'Grace computed before its deadline refuses when finalized after the deadline');
$state->duringCommand = null;
$clock->instant = new DateTimeImmutable('2027-10-18T12:00:00Z');
foreach([['conflicting'=>true],['auto_renew_only'=>true],['grace_supported'=>false],['grace_days'=>0],['grace_currency'=>'EUR'],
    ['verified_at'=>$clock->now()->modify('-30 days')->format(DATE_ATOM)],['grace_verified_at'=>$clock->now()->modify('-30 days')->format(DATE_ATOM)]] as $change){
    storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),...$change]);
    same(RenewalCode::Grace,$check()->code,'Unusable lifecycle evidence refuses real admission');
}
storeLifecycleFixture($binding,lifecycleFixture($clock->now()));
$state->duringCommand=ReadCommand::Balance;
$state->during=static fn()=>storeLifecycleFixture($binding,[...lifecycleFixture($clock->now()),'conflicting'=>true]);
same(RenewalCode::Changed,$check()->code,'Evidence changed during reads refuses under final locked stamp');
storeLifecycleFixture($binding,lifecycleFixture($clock->now()));$state->duringCommand=null;
storeLifecycleFixture($binding,lifecycleFixture($clock->now()->modify('-30 days +1 second')));
same($clock->now()->modify('+1 second')->getTimestamp(),$check()->validUntil?->getTimestamp(),'Admission cannot outlive lifecycle evidence');
$state->duringCommand=ReadCommand::Balance;
$state->during=static fn()=>$clock->instant=$clock->now()->modify('+2 seconds');
same(RenewalCode::Changed,$check()->code,'Lifecycle evidence expiring during reads refuses final admission');
$clock->instant=$clock->now()->modify('-2 seconds');$state->duringCommand=null;
storeLifecycleFixture($binding,lifecycleFixture($clock->now()));
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
expect(!$check()->allowed(), 'Processing pause during reads refuses'); $bindings->setProcessing($binding->serverId, true, syntheticActivation());
$state->during = static function () use ($bindings, $binding): void {
    $timestamps = DB::table('settings')->pluck('updated_at', 'id');
    $accountTime = DB::table('domains_accounts')->where('server_id', $binding->serverId)->value('updated_at');
    $bindings->configure($binding->serverId, 'enom', Environment::Test, ['token' => 'account-enom']);
    $bindings->setProcessing($binding->serverId, true, syntheticActivation());
    // Rotation detection must use ciphertext, even when persisted timestamps do not change.
    foreach ($timestamps as $id => $time) { DB::table('settings')->where('id', $id)->update(['updated_at' => $time]); }
    DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['updated_at' => $accountTime]);
};
same(RenewalCode::Changed, $check()->code, 'Same-account verified rotation requires a fresh admission');
same(RenewalCode::Admitted, $check()->code, 'Fresh attempt revalidates rotated binding');
$state->during = static fn () => $clock->instant = $clock->now()->modify('+251 seconds');
expect(!$check()->allowed(), 'Expired bounded read window refuses');
$clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
$domain->refresh()->update(['renewal_policy' => [...$domain->renewal_policy, 'automatic_spend_paused' => true]]);
same(RenewalCode::Paused, $check()->code, 'Automatic-spend pause respected'); same([], $state->calls, 'Paused admission performs no reads');
echo "Renewal admission ordinary/grace/restore, exact costs and locked authority passed\n"; expect(Illuminate\Support\Facades\DB::table('domains_registrar_balances')->where('source','renewal')->exists(),'Existing balance path records without extra reads'); $nativeSuccessful = true;
