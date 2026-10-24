<?php
// Synthetic input-bounded policy reads; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/watch-notices.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Cache, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{StorefrontController, WatchTransferController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainSummaries, PolicyEndings, Rdap, Storefront, TransferPrecheck, WatchTransfer};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

$f = noticeFixture(); extract($f); extract(commerceFixture(true, static fn () => null));
$clock = new FakeClock($now); $at = $now->getTimestamp();
config(['domains.watch_transfer.enabled' => true]);
DB::table('domains_watch_names')->where('id', $id)->update(['registration' => 'external']);
noticeRegistry($f, $at, 45);
$rdap = new Rdap(static fn ($url) => ['status' => 200, 'body' => json_encode(str_contains($url, 'dns.json')
    ? ['services' => [[['com'], ['https://rdap.example.com/']]]]
    : ['objectClassName' => 'domain', 'ldhName' => 'example.com', 'status' => ['active'], 'events' => [
        ['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z'],
        ['eventAction' => 'expiration', 'eventDate' => gmdate('Y-m-d\TH:i:s\Z', $at + 45 * 86400)]]])]);
$eligibility = new CheckoutEligibility($bindings, $clock, new TransferPrecheck($rdap));
$store = new Storefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$tlds[0]->server->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
require dirname(__DIR__).'/Domains/routes/web.php'; require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews(); $session = app('request')->session(); Auth::login($owner);
(new CartAdmission($native, $sellers, $eligibility, $clock))->boot();
$controller = new StorefrontController($store); app()->instance(StorefrontController::class, $controller);
$summaries = new DomainSummaries; $transfers = new WatchTransfer($store, $summaries, $native);
$watchController = new WatchTransferController($transfers, $eligibility, $clock);
$request = static function (array $data = []) use ($owner, $session): Request {
    $r = Request::create('/domains/check', 'POST', $data); $r->setLaravelSession($session); $r->setUserResolver(fn () => $owner); return $r;
};
$postWatch = static function (bool $confirm = false) use ($request, $watchController, $id) {
    $r = $request(); $key = FormKeys::issue($r, 'watch-transfer:'.$id);
    return $watchController->check($request(['_request_key' => $key, 'revision' => '1',
        ...($confirm ? ['step' => 'confirm', 'authority' => '1'] : [])]), $id);
};
// A page with distinct endings plus an owned native service exercises the shared batch projection.
$watchIds = [$id];
foreach ([reservedFixtureName('second.example.test'), reservedFixtureName('third.example.net')] as $name) {
    $row = (array) DB::table('domains_watch_names')->where('id', $id)->first(); unset($row['id']);
    $watchIds[] = DB::table('domains_watch_names')->insertGetId([...$row, 'canonical_name' => $name]);
}
$managed = DB::table('domains_domains')->insertGetId(['service_id' => $service->id, 'user_id' => $owner->id,
    'server_id' => $tlds[0]->server_id, 'registrar_key' => 'enom', 'account_fingerprint' => $tlds[0]->account_fingerprint,
    'domain' => 'managed-placeholder.com', 'reservation_name' => 'managed-placeholder.com', 'sld' => 'managed-placeholder', 'tld' => 'com', 'status' => 'active']);
foreach (['test' => true, 'example.test' => false, 'net' => false] as $ending => $enabled) {
    DB::table('domains_sales_policies')->insert(['tld' => $ending, 'transfer_enabled' => $enabled]);
}
// The actual lookup column already has a unique index, not merely a transfer-enabled index.
expect(Schema::hasIndex('domains_sales_policies', ['tld'], 'unique'), 'Policy suffix lookup uses the existing unique TLD index');
$stats = null;
DB::listen(static function ($query) use (&$stats): void {
    if ($stats === null) { return; }
    $stats['queries']++;
    foreach (['domains_sales_policies' => 'policy', 'domains_tlds' => 'tld'] as $table => $key) {
        if (!preg_match('/^select\b.*\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?\b/is', $query->sql)) { continue; }
        // Replay only local SELECTs immediately in the same snapshot via raw PDO (no listener recursion).
        // SQLite PDO rowCount() does not report SELECT cardinality; count the returned rows themselves.
        $statement = $query->connection->getPdo()->prepare($query->sql);
        $statement->execute($query->connection->prepareBindings($query->bindings));
        $stats[$key.'Rows'] += count($statement->fetchAll(PDO::FETCH_NUM));
        $stats[$key.'Queries']++;
    }
});
$measure = static function (Closure $read) use (&$stats): array {
    $stats = ['queries' => 0, 'policyRows' => 0, 'policyQueries' => 0, 'tldRows' => 0, 'tldQueries' => 0];
    try { $result = $read(); return [$result, $stats]; } finally { $stats = null; }
};
$fill = static function (int $size): void {
    $rows = [];
    for ($i = DB::table('domains_sales_policies')->count(); $i < $size; $i++) {
        // Alphabetic supported suffixes, with every unrelated transfer enabled to expose a global subset read.
        $rows[] = ['tld' => 'extra'.chr(97 + intdiv($i, 676)).chr(97 + intdiv($i % 676, 26)).chr(97 + $i % 26), 'transfer_enabled' => true];
    }
    foreach (array_chunk($rows, 100) as $chunk) { DB::table('domains_sales_policies')->insert($chunk); }
};
$paths = [
    'check' => static function () use ($controller, $request) {
        $r = $request(); $key = FormKeys::issue($r, 'search');
        $response = $controller->check($request(['domain' => 'check-placeholder.com', '_request_key' => $key]));
        same('available', $response->getData(true)['status'], 'Actual check POST succeeds');
    },
    'order' => static function () use ($controller, $request) {
        $r = $request(); $key = FormKeys::issue($r, 'order:order-placeholder.com');
        $response = $controller->order($request(['domain' => 'order-placeholder.com', 'action' => 'register', 'term' => '1', '_request_key' => $key]));
        same(route('cart'), $response->getTargetUrl(), 'Actual order POST reaches native cart');
    },
    'watch-get' => static function () use ($watchController, $request, $id) {
        same(true, $watchController->show($request(), $id)->getData()['canCheck'], 'GET resolves a current offer');
    },
    'watch-post' => static function () use ($postWatch) { same('eligible', $postWatch()->getData()['state'], 'POST rechecks offer'); },
    'watch-handoff' => static function () use ($postWatch) { same(route('cart'), $postWatch(true)->getTargetUrl(), 'Confirmed transfer reaches add()'); },
    'portal-batch' => static function () use ($summaries, $owner, $managed, $watchIds, $at) {
        $page = $summaries->batch($owner, [$managed], $watchIds, $at + 2);
        same(4, count($page), 'Multi-domain page retains managed and watch rows');
        expect($page['watch:'.$watchIds[0]]->urls['compareTransfer'] !== null, 'Enabled transfer ending retains link');
    },
    'hosting-summary' => static function () use ($summaries, $owner, $service, $at) {
        same(1, count($summaries->services($owner, [$service->id], $at + 2)), 'Native hosting summary remains owner scoped');
    },
];
$observed = [];
foreach ([10, 1500] as $size) {
    $fill($size);
    foreach ($paths as $path => $read) {
        [, $counts] = $measure($read); $observed[$size][$path] = $counts;
        expect($counts['policyRows'] <= 30, $path.' fetches bounded policy rows');
        if ($size === 1500) { same($observed[10][$path], $counts, $path.' row and query counts are catalogue-independent'); }
        echo $size.' '.$path.' '.json_encode($counts)."\n";
    }
    Cache::flush(); [$cold, $counts] = $measure(fn () => $store->endings());
    same(['com'], array_column($cold, 'tld'), 'Cold display preserves available offers');
    expect($counts['policyRows'] <= 2 * $size + 30 && $counts['queries'] <= 3 * $size + 100, 'Cold display stays linear per ending, independent of term retries');
    echo $size.' cold-display '.json_encode($counts)."\n";
    [$warm, $counts] = $measure(fn () => $store->endings()); same($cold, $warm, 'Warm display parity');
    same(0, $counts['queries'], 'Warm display is query-free');
}
foreach (['Name.Example.Test.' => ['domain' => 'name.example.test', 'sld' => 'name', 'tld' => 'example.test'], 'name.test' => ['domain' => 'name.test', 'sld' => 'name', 'tld' => 'test']] as $input => $expected) {
    same($expected, PolicyEndings::parse($input), 'Longest suffix and normalization parity');
    same($expected['tld'], $store->check($input)['tld'], 'Check uses the same longest ending');
}
foreach (['name.unknown', 'sub.name.example.test', 'sub.name.test', 'sub.name.unknown', 'bad..com', 'xn--name.com'] as $input) {
    $message = static function (Closure $parse): string {
        try { $parse(); expect(false, 'Expected parse refusal'); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    };
    same($message(fn () => CanonicalName::parse($input, ['com', 'example.test', 'test', 'net'])),
        $message(fn () => PolicyEndings::parse($input)), 'Unknown/subdomain/syntax refusal parity');
}
same(['test'], PolicyEndings::transferEnabled(['second.example.test']), 'Summary keeps any enabled suffix even when the longer policy is disabled');
same([], PolicyEndings::transferEnabled([reservedFixtureName('third.example.net')]), 'Disabled transfer ending omitted');
// Warm display never becomes money authority, including raw changes without cache invalidation.
$store->endings(); DB::table('domains_sales_policies')->where('tld', 'com')->update(['transfer_enabled' => false]);
same(null, $transfers->current($owner, $id, $at + 2)['offer'], 'Disabled current transfer policy refuses a warm offer');
same(null, $summaries->one($owner, 'watch', $id, $at + 2)->urls['compareTransfer'], 'Current summary withdraws disabled transfer link');
DB::table('domains_sales_policies')->where('tld', 'com')->update(['transfer_enabled' => true]);
$product = $native->product($tlds[0], 'register');
$product->plans()->where('billing_period', 1)->sole()->prices()->where('currency_code', 'USD')->update(['price' => '99.00']);
$r = $request(); $key = FormKeys::issue($r, 'order:changed-placeholder.com');
$response = $controller->order($request(['domain' => 'changed-placeholder.com', 'action' => 'register', 'term' => '1', '_request_key' => $key]));
same(route('domains.domains.search', ['name' => 'changed-placeholder.com']), $response->getTargetUrl(), 'Order refuses a changed authoritative price inside display TTL');
$nativeSuccessful = true; echo "Bounded policy rows, indexed suffix parity, batch links and uncached money decisions passed.\n";
