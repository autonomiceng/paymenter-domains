<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/OfferFixture.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Facts, FactsObserved, Intent, OfferReader, OfferState, Projection, Query, Writer};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

expect(method_exists(OfferReader::class, 'readBatch'), 'Mixed-account admission requires one shared batch loader');
// Native SQLite exercises actual binding, projection and local loading. It cannot prove InnoDB
// current reads or concurrent blocking; the installed two-connection check remains deferred.
// One active commerce installation supplies both accounts; no second installation or cart helpers.
extract(commerceFixture(active:true));
$clock = new FakeClock($now); $allowed = true; $checked = [];
$authorize = static function ($q, $context) use (&$allowed): void {
    if (!$allowed && $context !== Context::Public) { throw new DomainException('Permission revoked.'); }
};
$ready = static function ($b, $server, $account) use (&$checked): bool {
    $checked[] = $b->serverId;
    return $account->processing_enabled;
};
$reader = new OfferReader($clock, $authorize, $ready);
$writer = new Writer(new Projection($native), $clock, $authorize, static fn () => true);
$b = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$n = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id', $tlds[1]->server_id)->sole());
$converter = new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture; $converter->batch($b); $converter->batch($n);
$rev = static fn ($binding) => $writer->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision;
foreach ([$b, $n] as $binding) {
    $writer->apply(new FactsObserved($binding, 'com', new Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
        [], true, false, false, 'synthetic_facts', $now, $now->modify('+1 day'))), $rev($binding));
    $writer->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true)), $rev($binding));
}
$writer->apply(Edit::seller($b, 'com', ['register', 'transfer']), $rev($b));
$queries = [new Query($n, ['com', 'org'], 'register'), new Query($b, ['com'], 'register', 3)];
$out = $reader->readBatch($queries, Context::Public);
same([false, false, true], [$out[0][0]->available, $out[0][1]->available, $out[1][0]->available], 'Mixed accounts, missing offers and exact terms preserve input order');
same('23.40', $out[1][0]->retail, 'Three-year total is not an annual or another-account fallback');
same(null, $out[1][0]->staffCost, 'Public results redact costs');
same(false, $reader->readBatch([new Query($b, ['com'], 'register', 2)], Context::Public)[0][0]->available, 'Absent exact-term price cannot borrow a different term');
same('unavailable', $out[0][0]->reason, 'Public results redact specific seller reasons');
same('seller_changed', $reader->readBatch([$queries[0]], Context::Staff)[0][0]->reason, 'Authorized staff sees local refusal');
$allowed = false;
commerceReject(fn () => $reader->readBatch([$queries[1]], Context::Staff));
commerceReject(fn () => $reader->readBatch([new Query($b, ['com'], 'renew')], Context::Holder));
$allowed = true;
// A change commits after display and BEFORE admission. This is stale-receipt behavior, not
// simulated SQLite concurrency. Every component of the expected tuple must be reloaded.
foreach (['offer', 'defaults', 'selection', 'publication'] as $axis) {
    $expected = $rev($b);
    if ($axis === 'offer') { DB::table('domains_tlds')->where('id', $tlds[0]->id)->increment('catalogue_revision'); }
    if ($axis === 'defaults') {
        $d = OfferState::decode(Account::where('server_id', $b->serverId)->value('catalogue_defaults'));
        DB::table('domains_accounts')->where('server_id', $b->serverId)->update(['catalogue_defaults' => OfferState::json([...$d, '_revision' => ($d['_revision'] ?? 0) + 1])]);
    }
    if ($axis === 'selection') { DB::table('domains_sales_policies')->where('tld', 'com')->increment('revision'); }
    if ($axis === 'publication') { DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_published_revision' => null]); }
    $d = DB::transaction(fn () => $reader->readBatch([new Query($b, ['com'], 'register', 3, expected: ['com' => $expected])], Context::Locked)[0][0]);
    same(false, $d->available, 'Committed '.$axis.' change refuses old admission evidence');
    $writer->apply(Edit::republish($b, 'com'), $rev($b));
}
commerceReject(fn () => $reader->readBatch($queries, Context::Locked));
// SQLite omits FOR UPDATE in rendered SQL. Observe the actual builder lock flag instead
// of treating credential-validation SELECTs of an already locked account as new locks.
$connection = DB::connection(); $original = $connection->getQueryGrammar();
$grammar = new class($connection) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public array $selects = [];
    public function compileSelect(\Illuminate\Database\Query\Builder $query)
    {
        $this->selects[] = ['table' => $query->from, 'lock' => $query->lock,
            'bindings' => $query->getBindings(), 'orders' => $query->orders];
        return parent::compileSelect($query);
    }
};
try {
    $connection->setQueryGrammar($grammar);
    DB::transaction(function () use ($reader, $native, $tlds, $b, $n, $rev, $grammar, &$checked): void {
        (new Projection($native))->lock($tlds);
        $expected = $rev($b); $checked = []; $grammar->selects = [];
        $out = $reader->readBatch([new Query($n, ['com'], 'register'), new Query($b, ['com'], 'register', 3, expected: ['com' => $expected])], Context::Locked);
        same(true, $out[1][0]->available, 'Locked batch uses the same evaluator and current tuple');
        same([$b->serverId, $n->serverId], $checked, 'Local readiness follows sorted account prefix, not cart order');
    });
} finally { $connection->setQueryGrammar($original); }
$tables = [(new Server)->getTable(), 'domains_accounts', 'domains_sales_policies', 'domains_tlds', 'domains_prices'];
$locked = array_values(array_filter($grammar->selects, static fn ($q) => $q['lock'] !== null));
same($tables, array_column($locked, 'table'), 'Every shared lock group is acquired once, before any later account lock');
foreach ($locked as $q) { same(true, $q['lock'], 'Every lock group requests an exclusive lock'); }
foreach (array_slice($locked, 0, 2) as $i => $q) {
    same($i === 0 ? ['server', $b->serverId, $n->serverId] : [$b->serverId, $n->serverId], $q['bindings'], 'Whole account prefix is acquired in numeric order');
    same([['column' => $i === 0 ? 'id' : 'server_id', 'direction' => 'asc']], $q['orders'], 'Account locks use stable ascending order');
}
$accountReads = array_values(array_filter($grammar->selects, static fn ($q) => $q['table'] === 'domains_accounts' && $q['lock'] === null));
expect($accountReads !== [], 'Current NameSilo TEST facts revalidate the already locked account without a new lock');
foreach ($accountReads as $q) {
    same([$n->serverId], $q['bindings'], 'Plain validation reads stay within the acquired account prefix');
}
same(0, $reads->calls, 'No provider factory or callback is entered by batch loading');
$nativeSuccessful = true;
echo "Offer batch loading: ok\n";
