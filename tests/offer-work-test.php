<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/OfferFixture.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, FeedObserved, Intent, OfferWork, Projection, Query, Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, OfferFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client as NsClient, Driver as NsDriver, OfferFeed as NsFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as NsEnvironment, RegistrarRequest, RequestContext as NsContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, Installation, OfferWorkSchema, PricingRule};

// Real SQLite queue/writer/projection is needed for durable claims, restart and scoped publication.
// Only the wire and clock are fake; no provider runtime or active scheduler is registered.
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/OfferWork.php'), 'One dormant offer worker coalesces feed and lazy facts in the existing queue');
(new Installation)->install();
extract(commerceFixture(active:true));
$b = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$n = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $tlds[1]->server_id)->sole());
(new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture)->batch($b); (new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture)->batch($n);
DB::table('domains_tlds')->where('server_id', $b->serverId)->update(['catalogue_intent' => json_encode(['sell' => false])]);
$clock = new class($now->modify('+1 minute')) implements Clock {
    public function __construct(public DateTimeImmutable $time) {}
    public function now(): DateTimeImmutable { return $this->time; }
};
$wire = new class implements Transport {
    public array $calls = [];
    public int $extra = 0;
    public ?ReadCommand $fail = null;
    public ?Closure $during = null;
    public function send(Environment $environment, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    {
        expect(DB::transactionLevel() === 0, 'Every provider read happens outside database locks');
        $this->calls[] = $command;
        if ($this->during) { ($this->during)($command); }
        if ($this->fail === $command) { throw new RuntimeException('Synthetic wire failure with private body'); }
        $header = '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>';
        if ($command === ReadCommand::Tlds) {
            $extra = '';
            for ($i = 0; $i < $this->extra; $i++) { $extra .= '<TLD>suffix'.chr(97 + intdiv($i, 26)).chr(97 + $i % 26).'</TLD>'; }
            return $header.'<TLDList><TLD>com</TLD><TLD>org</TLD><TLD>co.uk</TLD>'.$extra.'</TLDList></interface-response>';  }
        if ($command === ReadCommand::TldInfo) {
            return $header.'<TLDs><TLD><TLD>'.$parameters['TLD'].'</TLD><Registration><Minimum>1</Minimum><Maximum>2</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs></interface-response>';
        }
        if ($command === ReadCommand::Attributes) { return $header.'<ResponseCount>0</ResponseCount></interface-response>'; }
        return "Command={$command->value}\nErrCount=0\nDone=true\nCount=1\nTLD1=com\nResellerPriceReg1=7.0000\nRegisterEnabled1=true\nResellerPriceRenew1=8.0000\nRenewEnabled1=true\nResellerPriceTran1=9.0000\nTransferEnabled1=true\n";
    }
};
$afterFeed = null;
$writer = new Writer(new Projection($native), $clock, static function ($command) use (&$afterFeed): void {
    if ($afterFeed && $command instanceof FeedObserved && DB::transactionLevel() === 0) {
        $callback = $afterFeed; $afterFeed = null; $callback();
    }
}, static fn () => true);
$nsWire = new class implements DeadlineTransport {
    public int $calls = 0;
    public function send(NsEnvironment $environment, NsContext $context, RegistrarRequest $request, string $apiKey): TransportResponse
    { throw new LogicException('Deadline required.'); }
    public function sendWithin(NsEnvironment $environment, NsContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        expect(DB::transactionLevel() === 0, 'NameSilo provider reads also happen outside locks'); $this->calls++;
        return new TransportResponse(200, json_encode(['request' => ['operation' => 'getPrices', 'ip' => '192.0.2.1'],
            'reply' => ['code' => 300, 'detail' => 'synthetic', 'com' => ['registration' => '7.00', 'renew' => '8.00', 'transfer' => '9.00']]], JSON_THROW_ON_ERROR));
    }
};
$factory = static fn ($binding) => $binding->registrarKey === 'namesilo'
    ? new NsFeed(new NsDriver(new NsClient($binding, 'placeholder-key', $nsWire), clock: $clock))
    : new OfferFeed(new Driver(new Client($binding, Account::where('server_id', $binding->serverId)->sole()->stable_identity, 'placeholder-key', $wire), ['com', 'org', 'co.uk']));
$allowance = 25;
$worker = new OfferWork($writer, $clock, new AccountReadBudget($clock, 25), $factory,
    static function () use (&$allowance): int { return $allowance; });
$worker->wake($b); $worker->tick(1);
same(0, count($wire->calls), 'Local-only work never schedules an account feed');
same(null, $worker->status($b)['due_at'], 'Local-only work has no feed deadline');
same(null, $worker->status($b)['succeeded_at'], 'Local-only work has no completed feed');
same('ready', $worker->status($b)['state'], 'Finished local-only work without a feed deadline is ready');
$worker->sync($b); $worker->sync($b);
same('queued', $worker->status($b)['state'], 'Repeated manual sync coalesces before work starts');
$worker->tick(1);
same(5, count($wire->calls), 'One account feed uses five reads, including discovered unpriced suffixes');
$worker->tick(1);
same(5, count($wire->calls), 'Warm account feed is reused without another read');
$worker->sync($b, RequestOrigin::Background); $worker->tick(1);
same(5, count($wire->calls), 'Repeated background hints reuse a warm account feed');
expect(Tld::where('server_id', $b->serverId)->where('tld', 'co.uk')->exists(), 'An unpriced discovered suffix is retained for cold search');
same(false, $writer->readOffers(new Query($b, ['co.uk'], 'register'), Context::Public)[0]->available,
    'Discovery alone cannot publish or select a suffix');
// Sync after durable provider success must survive the rest of the active writer turn.
$clock->time = $clock->time->modify('+61 seconds'); $worker->sync($b);
$afterFeed = static function () use ($worker, $b, $clock): void {
    same($clock->now()->getTimestamp(), $worker->status($b)['succeeded_at'], 'The provider success is stored before the manual Sync');
    same('running', $worker->status($b)['state'], 'The completed feed still owns its worker claim');
    $worker->sync($b);
};
$before = count($wire->calls); $worker->tick(1);
same(5, count($wire->calls) - $before, 'Sync after provider success does not start an overlapping feed');
$worker->tick(1);
same(10, count($wire->calls) - $before, 'Sync after provider success runs one feed on the next turn');
$worker->tick(1);
same(10, count($wire->calls) - $before, 'Sync after provider success is consumed once');

$factsBefore = count($wire->calls);
$worker->requestFacts($b, 'co.uk'); $worker->requestFacts($b, 'co.uk');
$worker->tick(1);
same($factsBefore + 2, count($wire->calls), 'Concurrent cold requests coalesce to two Enom fact reads');
$worker->requestFacts($b, 'co.uk'); $worker->tick(1);
same($factsBefore + 2, count($wire->calls), 'Warm independent facts are reused');
same('ready', $worker->status($b, 'co.uk')['state'], 'Lazy fact success is visible without granting sale authority');
// A buffered account feed must not delay a cold search until the whole account has drained.
$clock->time = $clock->time->modify('+1 minute'); $wire->extra = 30;
$worker->requestFacts($b, 'org'); $worker->sync($b);
$before = count($wire->calls); $worker->tick(1);
same(5, count($wire->calls) - $before, 'A large account still has one fixed-size feed read group');
same(8, $worker->status($b)['remaining'], 'Only 25 observations apply in one account turn');
$wire->during = static function ($command) use ($worker, $b): void {
    if ($command === ReadCommand::TldInfo) { $worker->sync($b); }
};
$before = count($wire->calls); $worker->tick(1); $wire->during = null;
same(2, count($wire->calls) - $before, 'A pending feed batch cannot starve requested facts');
same(0, $worker->status($b)['remaining'], 'Saved feed observations drain without rereading prices');
$before = count($wire->calls); $worker->tick(1);
same(5, count($wire->calls) - $before, 'Manual Sync during buffer drain starts one feed after the buffer empties');
$worker->tick(1);
same(5, count($wire->calls) - $before, 'Manual Sync during buffer drain is consumed once');
// Failed cold reads do not erase known facts, and repeated search hints cannot reset backoff.
$worker->requestFacts($b, 'suffixaa'); $wire->fail = ReadCommand::TldInfo;
$before = count($wire->calls); $worker->tick(1);
$failure = $worker->status($b, 'suffixaa');
same('retrying', $failure['state'], 'A failed cold read exposes its automatic retry status');
same($clock->now()->getTimestamp(), $failure['failed_at'], 'A failure has its own age');
expect(!isset($failure['succeeded_at']), 'Failed metadata does not invent a successful observation');
$worker->requestFacts($b, 'suffixaa'); $worker->tick(1);
same($before + 1, count($wire->calls), 'Repeated cold requests respect failure backoff and stop after the first failed wire');
$wire->fail = null; $clock->time = $clock->time->modify('+121 seconds'); $worker->tick(1);
$success = $worker->status($b, 'suffixaa');
same('ready', $success['state'], 'Automatic retry recovers without a staff step');
same($failure['failed_at'], $success['failed_at'], 'A success does not rewrite failure age');
same($clock->now()->getTimestamp(), $success['succeeded_at'], 'A success is dated separately');
same(false, Schema::hasColumn('domains_tlds', 'offer_facts_work'), 'Queue owns fact attempts; the offer stores only independently aged observations');

// Local rule fan-out shares the worker without consuming any provider allowance.
$read = static fn ($binding = null, $tld = 'com') => $writer->readOffers(new Query($binding ?? $b, [$tld], 'register'), Context::Staff)[0];
$writer->apply(Edit::defaults($b, 'com', new Intent(defaultSell: true)), $read()->revision);
same('staff_stopped', $read()->reason, 'Explicit staff Off keeps automatic metadata discovery stopped during default changes');
$before = count($wire->calls); $worker->wake($b); $worker->tick(1); $worker->tick(1);
expect($read()->reason !== 'publication_pending', 'Bounded local rotation clears publication fences');
same($before, count($wire->calls), 'Rule fan-out needs no registrar reads');

// Failed feeds retain independently aged facts and the last successful run.
$factBytes = Tld::where('server_id', $b->serverId)->where('tld', 'co.uk')->value('catalogue_facts');
$lastGood = $worker->status($b)['succeeded_at']; $clock->time = $clock->time->modify('+61 seconds');
$wire->fail = ReadCommand::RetailPricing; $worker->sync($b); $worker->tick(1);
$feedFailure = $worker->status($b);
same('retrying', $feedFailure['state'], 'A feed failure exposes its automatic retry');
same($lastGood, $feedFailure['succeeded_at'], 'Feed failure retains the previous success timestamp');
$before = count($wire->calls); $worker->sync($b); $worker->tick(1);
same($before, count($wire->calls), 'Manual Sync coalesces with a scheduled failure retry');
$wire->fail = null; $clock->time = $clock->time->modify('+121 seconds'); $worker->tick(1); $worker->tick(1);
same($feedFailure['failed_at'], $worker->status($b)['failed_at'], 'Feed recovery preserves failure age');
same($factBytes, Tld::where('server_id', $b->serverId)->where('tld', 'co.uk')->value('catalogue_facts'), 'Feed retries never refresh metadata age');

// Our allowance refuses unsent work while another account can use its own smaller group.
$clock->time = $clock->time->modify('+61 seconds'); $allowance = 0; $before = count($wire->calls);
$tried = $worker->status($b)['tried_at']; $worker->sync($b); $worker->tick(1);
same($before, count($wire->calls), 'No physical read starts without whole-group daily headroom');
same($tried, $worker->status($b)['tried_at'], 'Unsent capacity deferral does not invent an attempt');
$allowance = 1; $worker->sync($n); $worker->tick(1);
same(1, $nsWire->calls, 'Fair account rotation services the one-read NameSilo feed while Enom waits');
$worker->requestFacts($n, 'com'); $worker->tick(2);
same(1, $nsWire->calls, 'Unknown NameSilo facts never create a per-TLD price read');
$unsupported = $worker->status($n, 'com');
same('unsupported', $unsupported['state'], 'A zero-reader adapter reports unsupported facts instead of retrying');
same(null, $unsupported['due_at'], 'Unsupported facts have no automatic retry deadline');
expect(!isset($unsupported['tried_at']) && !isset($unsupported['succeeded_at']) && !isset($unsupported['failed_at']), 'Unsupported facts invent no attempt, success or failure');
same(null, Tld::where('server_id', $n->serverId)->where('tld', 'com')->value('catalogue_facts'), 'Unsupported facts remain unknown on the offer');
$worker->requestFacts($n, 'com');
$allowance = 25; $clock->time = $clock->time->modify('+121 seconds'); $worker->tick(2); $worker->tick(2);
same($before + 5, count($wire->calls), 'Deferred unsent work resumes with one feed, without a new staff action');
same($unsupported, $worker->status($n, 'com'), 'Repeated hints and later turns do not retry unsupported facts');

// Success age expires independently. Only lazily requested suffixes get cold reads.
$clock->time = $clock->time->modify('+31 days');
$costBytes = DB::table('domains_prices')->where('server_id', $b->serverId)->where('tld', 'com')->orderBy('id')->get()->toJson();
$before = count($wire->calls); $worker->tick(2);
same(2, count($wire->calls) - $before, 'Expired requested facts use two reads before the next due account feed');
same($unsupported, $worker->status($n, 'com'), 'Unsupported facts stay terminal beyond the maximum retry backoff');
same($costBytes, DB::table('domains_prices')->where('server_id', $b->serverId)->where('tld', 'com')->orderBy('id')->get()->toJson(), 'Fact renewal cannot freshen costs');
$worker->tick(2); $worker->tick(2); $worker->tick(2);
$before = count($wire->calls); $worker->tick(2);
same($before, count($wire->calls), 'Unrequested discovered suffixes do not trigger a metadata sweep');

// Manual Sync during facts-only work must survive a warm account feed's active claim.
expect($worker->status($b)['due_at'] > $clock->now()->getTimestamp(), 'The account feed is warm before the facts-only turn');
$worker->requestFacts($b, 'suffixac');
$wire->during = static function ($command) use ($worker, $b): void {
    if ($command === ReadCommand::TldInfo) { $worker->sync($b); }
};
$before = count($wire->calls); $worker->tick(2); $wire->during = null;
same(2, count($wire->calls) - $before, 'Manual Sync during facts reads waits for the next account turn');
$before = count($wire->calls); $worker->tick(2);
same(5, count($wire->calls) - $before, 'Manual Sync made during facts-only work runs one feed group afterward');
$worker->tick(2);
same(5, count($wire->calls) - $before, 'The retained manual Sync is consumed by exactly one feed group');

// Simulate process loss after a durable feed buffer, then reconstruct the worker.
$clock->time = $clock->time->modify('+61 seconds'); $wire->extra = 60;
$worker->sync($b); $worker->tick(2);
same(38, $worker->status($b)['remaining'], 'A large feed saves the work beyond its bounded turn');
$q = DB::table('domains_observation_queues')->where('server_id', $b->serverId);
$q->update(['offer_claim' => str_repeat('a', 32), 'offer_lease' => $clock->now()->getTimestamp() + 300]);
$before = count($wire->calls); $worker->tick(2);
same(38, $worker->status($b)['remaining'], 'An unexpired crashed claim is not stolen');
$clock->time = $clock->time->modify('+301 seconds');
$worker = new OfferWork($writer, $clock, new AccountReadBudget($clock, 25), $factory,
    static function () use (&$allowance): int { return $allowance; });
$worker->tick(2); same(13, $worker->status($b)['remaining'], 'Restart advances only one bounded buffer batch');
$worker->tick(2); same(0, $worker->status($b)['remaining'], 'The restarted worker finishes its durable buffer');
same($before, count($wire->calls), 'Crash recovery does not repeat a completed provider feed');

// A cold hint arriving while a feed reads outside locks must survive the worker's later save.
$clock->time = $clock->time->modify('+61 seconds'); $worker->sync($b);
$wire->during = static function () use ($worker, $b): void { $worker->requestFacts($b, 'suffixab'); $worker->sync($b); };
$before = count($wire->calls); $worker->tick(2); $wire->during = null;
same(5, count($wire->calls) - $before, 'In-flight hints and manual sync do not start overlapping feeds');
$worker->tick(2); $worker->tick(2);
same('ready', $worker->status($b, 'suffixab')['state'], 'A cold hint made during provider reads survives buffered-work saves');

// A later queue generation or an account rebind during reads exposes no new observations.
$wire->extra = 61;
expect(!Tld::where('server_id', $b->serverId)->where('tld', 'suffixci')->exists(), 'The superseded feed fixture includes a new suffix');
$clock->time = $clock->time->modify('+61 seconds'); $worker->sync($b);
$oldRevision = $read()->revision; $lastGood = $worker->status($b)['succeeded_at'];
$wire->during = static function ($command) use ($q): void { if ($command === ReadCommand::Tlds) { $q->increment('offer_feed_run'); } };
$worker->tick(2); $wire->during = null; $wire->extra = 60;
same(false, Tld::where('server_id', $b->serverId)->where('tld', 'suffixci')->exists(), 'Superseded feed cannot insert a newly discovered suffix');
expect($oldRevision->matches($read()->revision), 'Superseded feed cannot apply or publish observations');
same($lastGood, $worker->status($b)['succeeded_at'], 'Superseded feed cannot acknowledge success');
$q->update(['offer_claim' => null, 'offer_lease' => 0]);
$worker->sync($b);
$wire->during = static function ($command) use ($b): void {
    if ($command === ReadCommand::Tlds) { DB::table('domains_accounts')->where('server_id', $b->serverId)->update(['account_fingerprint' => str_repeat('0', 64)]); }
};
$worker->tick(2); $wire->during = null;
DB::table('domains_accounts')->where('server_id', $b->serverId)->update(['account_fingerprint' => $b->fingerprint]);
expect($oldRevision->matches($read()->revision), 'Rebinding during reads cannot save observations to the former account');
$q->update(['offer_claim' => null, 'offer_lease' => 0]);

// The queue fence is also checked under writer locks, including new unpriced discovery.
$guarded = $writer->withGuard(static function (): void {
    expect(DB::transactionLevel() > 0, 'Queue guard is rechecked in the writer transaction');
    throw new DomainException('Synthetic superseded writer.');
});
commerceReject(static fn () => $guarded->apply(new FeedObserved($b, 'freshsuffix', []), $read($b, 'freshsuffix')->revision));
same(false, Tld::where('server_id', $b->serverId)->where('tld', 'freshsuffix')->exists(), 'Late generation refusal rolls back discovery too');

// A credential rotation invalidates a durable buffer even though the verified account identity is unchanged.
$q->update(['offer_work' => '{}', 'offer_claim' => null, 'offer_lease' => 0]);
$clock->time = $clock->time->modify('+301 seconds'); $credential = 'synthetic-revision-one';
$credentialPort = static function () use (&$credential): string { return $credential; };
$worker = new OfferWork($writer, $clock, new AccountReadBudget($clock, 25), $factory, static fn () => 25, $credentialPort);
$worker->sync($b); $worker->tick(2);
same(38, $worker->status($b)['remaining'], 'The first credential revision owns a durable feed buffer');
$credential = 'synthetic-revision-two'; $clock->time = $clock->time->modify('+61 seconds');
$before = count($wire->calls); $worker->tick(2);
same(5, count($wire->calls) - $before, 'Rotation discards the old buffer and fetches current account evidence before publishing more offers');
same(38, $worker->status($b)['remaining'], 'The replacement feed has its own complete bounded buffer');
$worker->tick(2); $worker->tick(2);
$clock->time = $clock->time->modify('+61 seconds'); $worker->sync($b);
$oldRevision = $read()->revision; $lastGood = $worker->status($b)['succeeded_at'];
$wire->during = static function ($command) use (&$credential): void {
    if ($command === ReadCommand::Tlds) { $credential = 'synthetic-revision-three'; }
};
$worker->tick(2); $wire->during = null;
expect($oldRevision->matches($read()->revision), 'Rotation during the last wire read cannot publish or acknowledge the old credential evidence');
same($lastGood, $worker->status($b)['succeeded_at'], 'A credential-superseded feed cannot acknowledge success');
$clock->time = $clock->time->modify('+301 seconds'); $before = count($wire->calls);
$worker->tick(2);
same(5, count($wire->calls) - $before, 'A later worker recovers an expired superseded claim with a fresh credential-bound feed');


// Production caller: a selected sold row with expired/unknown facts schedules its own metadata refresh.
$q->update(['offer_work' => json_encode(['credential_revision' => $credential]), 'offer_claim' => null, 'offer_lease' => 0]);
DB::table('domains_tlds')->where('server_id', $b->serverId)->where('tld', 'com')->update(['catalogue_facts' => null, 'catalogue_intent' => json_encode(['sell' => true])]);
$clock->time = $clock->time->modify('+301 seconds'); $before = count($wire->calls);
$worker->wake($b); $worker->tick(2); $worker->tick(2);
same('ready', $worker->status($b, 'com')['state'], 'Sold set automatically requests unknown metadata');
same(2, count($wire->calls) - $before, 'Sold-set warming uses two metadata reads and no full-list sweep');
$clock->time = $clock->time->modify('+31 days'); $before = count($wire->calls);
$worker->tick(2); $worker->tick(2); $worker->tick(2); $worker->tick(2);
same(2, count($wire->calls) - $before, 'Sold-set facts expire independently and refresh automatically');

// A local sold-set hint can promote TEST facts while the worker still holds an older copy.
DB::table('domains_tlds')->where('server_id',$n->serverId)->where('tld','com')->update(['catalogue_intent'=>json_encode(['sell'=>true]),'catalogue_facts'=>null]);
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>$n->serverId,'registrar_key'=>$n->registrarKey,'account_fingerprint'=>$n->fingerprint,'register_enabled'=>true]);
$future=$clock->now()->getTimestamp()+86400;
DB::table('domains_observation_queues')->where('server_id',$n->serverId)->update(['offer_work'=>json_encode(['facts'=>['com'=>['sold_only'=>true,'due_at'=>$future]]]),'offer_claim'=>null,'offer_lease'=>0]);
$worker->accountTurn($n->serverId);
same($clock->now()->getTimestamp(),$worker->status($n,'com')['due_at'],'Worker save preserves an earlier facts hint created during republication');
$worker->accountTurn($n->serverId);
same('unsupported',$worker->status($n,'com')['state'],'The promoted hint reaches the existing unsupported-reader path');
// Upgrade an actual retained B2 schema, preserving all catalogue/native identities.
$ids = Tld::orderBy('id')->pluck('id')->all();
Schema::table('domains_observation_queues', static fn ($t) => $t->dropColumn(['offer_work', 'offer_claim', 'offer_lease', 'offer_turn']));
DB::table('migrations')->where('migration', OfferWorkSchema::MIGRATION)->delete();
(new Installation)->install(); (new Installation)->install();
same($ids, Tld::orderBy('id')->pluck('id')->all(), 'Retained upgrade and repeated install preserve offer IDs');
$nativeSuccessful = true;
echo "Dormant offer queue and lazy facts checks passed.\n";
