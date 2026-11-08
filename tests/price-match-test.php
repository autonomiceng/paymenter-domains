<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real SQLite catalogue/native models and installed routing exercise the command's actual price authority.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\{Cache, DB, Log};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, Context, Edit, FactsObserved, FeedObserved, OfferState, Query};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionOffers, EnomTestRenewal};

extract(commerceFixture(true, ending: 'net', registrars: ['enom']));
$b = AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true,
    'domains.enom_writes.registration' => true, 'domains.enom_writes.renewal' => true]);
App\Models\Server::findOrFail($b->serverId)->settings()->updateOrCreate(['key' => EnomTestRenewal::KEY],
    ['type' => 'string', 'encrypted' => true, 'value' => json_encode(['fingerprint' => $b->fingerprint, ...EnomTestRenewal::protocol($b)])]);
$offers = CallerRouting::resolve(AcquisitionOffers::class);
$command = new Paymenter\Extensions\Servers\Domains\Console\PriceMatch;
$console = new Symfony\Component\Console\Application; $console->add($command); $command->setLaravel(app());
$tester = new Symfony\Component\Console\Tester\CommandTester($command);
same(0, $tester->execute(['--json' => true]), 'Healthy catalogue passes');
$healthy = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
same([1, 3], array_column(array_filter($healthy, fn ($r) => $r['action'] === 'register'), 'term'), 'Check enumerates exact eligible catalogue terms');
$product = $native->product($tlds[0], 'register');
$annual = $product->plans()->where('billing_period', 1)->sole();
$long = $product->plans()->where('billing_period', 3)->sole();
$annualPrice = $annual->prices()->where('currency_code', 'USD')->sole();
$longPrice = $long->prices()->where('currency_code', 'USD')->sole();
DB::table('prices')->where('id', $annualPrice->id)->update(['price' => '0.01']);
DB::table('prices')->where('id', $longPrice->id)->update(['setup_fee' => '1.00']);
Log::spy();
$before = DB::table('prices')->orderBy('id')->get()->toJson(); $calls = $reads->calls;
same(1, $tester->execute([]), 'Amount and setup mismatches fail together');
foreach (['net register 1y', 'net register 3y', 'catalogue USD', 'plan '.$annual->id, 'plan '.$long->id] as $text) {
    expect(str_contains($tester->getDisplay(), $text), 'Command reports every mismatch with catalogue and plan: '.$text);
}
same($before, DB::table('prices')->orderBy('id')->get()->toJson(), 'Command does not repair prices');
same($calls, $reads->calls, 'Command makes no provider reads');
Log::shouldNotHaveReceived('warning');
same([], $offers->display(['net']), 'Storefront hides both mismatched terms');
$offers->display(['net']);
Log::shouldHaveReceived('warning')->twice();
$transferPrice=$native->product($tlds[0],'transfer')->plans()->where('billing_period',1)->sole()->prices()->where('currency_code','USD')->sole();
DB::table('prices')->where('id',$transferPrice->id)->update(['price'=>'0.01']);
$offers->display(['net']);$offers->display(['net']);
Log::shouldHaveReceived('warning')->with('Domain acquisition price mismatch; term hidden.',Mockery::on(fn($r)=>$r['action']==='transfer'&&$r['term']===1))->once();
DB::table('prices')->where('id',$transferPrice->id)->update(['price'=>$transferPrice->price]);
$clock->instant = $now->modify('+13 hours');
$offers->display(['net']);
Log::shouldHaveReceived('warning')->times(5);
$clock->instant = $now;
DB::table('prices')->where('id', $annualPrice->id)->update(['price' => $annualPrice->price]);
DB::table('prices')->where('id', $longPrice->id)->update(['setup_fee' => '0.00']);
// Diagnostic failures must still hide drift while returning the healthy sibling term.
DB::table('prices')->where('id', $longPrice->id)->update(['price' => '0.01']);
$cache = Cache::getFacadeRoot(); $log = Log::getFacadeRoot();
foreach (['cache', 'logger'] as $failure) {
    $diagnosticCache = Mockery::mock();
    $add = $diagnosticCache->shouldReceive('add')->once();
    if ($failure === 'cache') { $add->andThrow(new RuntimeException('Synthetic warning cache failure.')); }
    else { $add->andReturn(true); }
    Cache::swap($diagnosticCache);
    $diagnosticLog = Mockery::mock(Psr\Log\LoggerInterface::class);
    if ($failure === 'logger') {
        $diagnosticLog->shouldReceive('warning')->once()->andThrow(new RuntimeException('Synthetic warning logger failure.'));
    }
    Log::swap($diagnosticLog);
    try {
        $display = $offers->display(['net']);
        same(['register:1'], array_keys($display['net']), $failure.' failure preserves the healthy term and hides drift');
        same(1, $tester->execute([]), $failure.' failure does not change the command mismatch exit');
    } finally { Cache::swap($cache); Log::swap($log); }
    $diagnosticCache->shouldHaveReceived('add')->once();
    if ($failure === 'logger') { $diagnosticLog->shouldHaveReceived('warning')->once(); }
}
DB::table('prices')->where('id', $longPrice->id)->update(['price' => $longPrice->price]);
// Missing/duplicate plans and missing/duplicate USD rows must not disappear from the checked set.
foreach (['missing-plan', 'duplicate-plan', 'missing-price', 'duplicate-price', 'invalid-plan'] as $bad) {
    if ($bad === 'missing-plan') { DB::table('plans')->where('id', $long->id)->delete(); }
    if ($bad === 'duplicate-plan') { $copy = $long->getRawOriginal(); unset($copy['id']); DB::table('plans')->insert($copy); }
    if ($bad === 'missing-price') { DB::table('prices')->where('id', $longPrice->id)->delete(); }
    if ($bad === 'duplicate-price') { $copy = $longPrice->getRawOriginal(); unset($copy['id']); DB::table('prices')->insert($copy); }
    if ($bad === 'invalid-plan') { DB::table('plans')->where('id', $long->id)->update(['billing_unit' => 'month']); }
    same(1, $tester->execute(['--json' => true]), $bad.' fails the command');
    $rows = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    $failed = array_values(array_filter($rows, fn ($r) => $r['mismatch'] !== null));
    same([3], array_column($failed, 'term'), $bad.' remains in the eligible set');
    if ($bad === 'missing-plan') { DB::table('plans')->insert($long->getRawOriginal()); }
    if ($bad === 'duplicate-plan') { DB::table('plans')->where('priceable_id', $product->id)->where('billing_period', 3)->where('id', '!=', $long->id)->delete(); }
    if ($bad === 'missing-price') { DB::table('prices')->insert($longPrice->getRawOriginal()); }
    if ($bad === 'duplicate-price') { DB::table('prices')->where('plan_id', $long->id)->where('id', '!=', $longPrice->id)->delete(); }
    if ($bad === 'invalid-plan') { DB::table('plans')->where('id', $long->id)->update(['billing_unit' => 'year']); }
}
same(0, $tester->execute([]), 'Restored prices pass');
// An unrelated corrupt selected ending must not be scanned by a one-ending publication run.
$org = $tlds[0]->replicate(); $org->tld = 'org'; $org->catalogue_published_revision = null; $org->saveQuietly();
foreach (DB::table('domains_prices')->where('server_id', $b->serverId)->where('tld', 'net')->get() as $row) {
    $copy = (array) $row; unset($copy['id']); $copy['tld'] = 'org'; DB::table('domains_prices')->insert($copy);
}
$q = new Query($b, ['org'], 'register');
$ports->apply(Edit::seller($b, 'org', ['register']), $ports->readOffers($q, Context::Staff)[0]->revision);
$orgPrice = $native->product($org, 'register')->plans()->where('billing_period', 3)->sole()->prices()->where('currency_code', 'USD')->sole();
DB::table('prices')->where('id', $orgPrice->id)->update(['price' => '0.01']);
$costs = DB::table('domains_prices')->where('server_id', $b->serverId)->where('tld', 'net')->pluck('catalogue_observation')->map(OfferState::decode(...))->all();
DB::table('domains_observation_queues')->updateOrInsert(['server_id' => $b->serverId],
    ['account_fingerprint' => $b->fingerprint, 'offer_work' => OfferState::json(['feed_due' => $now->getTimestamp() + 86400,
        'pending' => [['tld' => 'net', 'costs' => $costs]]])]);
// Force a native update, then simulate drift left after Writer/Projection finishes the buffered row.
DB::table('prices')->where('id', $longPrice->id)->update(['price' => '0.01']);
DB::statement('CREATE TRIGGER drift_native_price AFTER UPDATE ON prices WHEN NEW.id = '.$longPrice->id.
    " BEGIN UPDATE prices SET setup_fee = '1.00' WHERE id = NEW.id; END");
$feedCalls = 0;
$worker = new Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork($ports->writer(), $clock,
    new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock, 25),
    static function () use (&$feedCalls) { ++$feedCalls; throw new LogicException('No additional provider reads allowed.'); }, static fn () => 0);
same('worked', $worker->accountTurn($b->serverId), 'Existing account job finishes its buffered publication');
same(2, count(array_filter($offers->priceMatches(), fn ($r) => $r['mismatch'] !== null)), 'Full check still detects touched and untouched drift');
Log::shouldHaveReceived('warning')->with('Domain acquisition price mismatch after catalogue publication.',
    Mockery::on(fn ($row) => $row['tld'] === 'net' && $row['term'] === 3 && $row['mismatch'] !== null))->once();
Log::shouldNotHaveReceived('warning')->with('Domain acquisition price mismatch after catalogue publication.',Mockery::on(fn($row)=>$row['tld']==='org'));
same($calls, $reads->calls, 'End-of-publication check makes no provider calls');
same(0, $feedCalls, 'Buffered publication and its check never resolve another provider feed');
same('0.01', number_format((float) $orgPrice->fresh()->price, 2, '.', ''), 'Untouched ending remains unchanged');
// A real touched publication must retain its result and saved queue state when its warning fails.
$clock->instant = $now->modify('+1 minute');
$publicationCosts = array_map(static fn ($cost) => [...$cost,
    'observed_at' => $clock->now()->format(DATE_ATOM),
    'total' => $cost['action'] === 'register' && $cost['term'] === 3 ? '19.0000' : $cost['total']], $costs);
$beforePublication = $tlds[0]->fresh()->catalogue_revision;
$queue = DB::table('domains_observation_queues')->where('server_id', $b->serverId);
$work = OfferState::decode((clone $queue)->value('offer_work'));
$work['pending'] = [['tld' => 'net', 'costs' => $publicationCosts]];
$queue->update(['offer_work' => OfferState::json($work)]);
DB::table('prices')->where('id', $longPrice->id)->update(['price' => '0.02']);
$diagnosticLog = Mockery::mock(Psr\Log\LoggerInterface::class);
$diagnosticLog->shouldReceive('warning')->with('Domain acquisition price mismatch after catalogue publication.',
    Mockery::on(fn ($row) => $row['tld'] === 'net' && $row['term'] === 3))->once()
    ->andThrow(new RuntimeException('Synthetic publication logger failure.'));
Log::swap($diagnosticLog);
try {
    same('worked', $worker->accountTurn($b->serverId), 'Publication logger failure preserves the completed turn result');
} finally { Log::swap($log); }
$diagnosticLog->shouldHaveReceived('warning')->once();
$saved = $queue->first(); $savedWork = OfferState::decode($saved->offer_work);
same([], $savedWork['pending'], 'Publication logger failure preserves the drained buffer');
same($work['feed_due'], $savedWork['feed_due'], 'Publication logger failure preserves the feed deadline');
same(null, $saved->offer_claim, 'Publication logger failure preserves the released claim');
same(0, (int) $saved->offer_lease, 'Publication logger failure preserves the released lease');
expect(!isset($savedWork['local_failure']), 'Publication logger failure does not record a feed failure');
expect($tlds[0]->fresh()->catalogue_revision > $beforePublication, 'A new feed observation was really committed');
$publishedRetail = DB::table('domains_prices')->where(['server_id' => $b->serverId, 'tld' => 'net',
    'action' => 'register', 'term' => 3])->value('retail');
same(number_format((float) $publishedRetail, 2, '.', ''),
    number_format((float) $longPrice->fresh()->price, 2, '.', ''), 'The touched native price was really published');
same(0, $feedCalls, 'Publication logger failure never starts another provider feed');
DB::statement('DROP TRIGGER drift_native_price');
DB::table('prices')->where('id', $longPrice->id)->update(['setup_fee' => '0.00']);
DB::table('prices')->where('id', $orgPrice->id)->update(['price' => $orgPrice->price]);
config(['domains.enom_writes.registration' => false]);
DB::table('prices')->where('id', $longPrice->id)->update(['price' => '0.01']);
same(0, $tester->execute(['--json' => true]), 'Closed acquisition terms are excluded');
same([], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR), 'Zero eligible terms is explicit JSON evidence');
echo "Read-only acquisition price command and daily storefront warnings pass\n";
$nativeSuccessful = true;
