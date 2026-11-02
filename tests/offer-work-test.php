<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, OfferWork, Projection, Query, UpgradeConversion, Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, OfferFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, CatalogueAcceptances, Installation};

// Real SQLite queue/writer/projection is needed for durable claims, restart and scoped publication.
// Only the wire and clock are fake; no provider runtime or active scheduler is registered.
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/OfferWork.php'), 'One dormant offer worker coalesces feed and lazy facts in the existing queue');
(new Installation)->install();
extract(commerceFixture());
$b = CatalogueAcceptances::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$n = CatalogueAcceptances::binding(Account::where('server_id', $tlds[1]->server_id)->sole());
(new UpgradeConversion)->batch($b); (new UpgradeConversion)->batch($n);
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
        if ($this->during) { ($this->during)(); }
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
$writer = new Writer(new Projection($native), $clock, static function (): void {}, static fn () => true);
$factory = static fn ($binding) => new OfferFeed(new Driver(new Client($binding, Account::where('server_id', $binding->serverId)->sole()->stable_identity, 'placeholder-key', $wire), ['com', 'org', 'co.uk']));
$allowance = 25;
$worker = new OfferWork($writer, $clock, new AccountReadBudget($clock, 25), $factory,
    static function () use (&$allowance): int { return $allowance; });
$worker->sync($b); $worker->sync($b);
same('queued', $worker->status($b)['state'], 'Repeated manual sync coalesces before work starts');
$worker->tick(1);
same(5, count($wire->calls), 'One account feed uses five reads, including discovered unpriced suffixes');
$worker->tick(1);
same(5, count($wire->calls), 'Warm account feed is reused without another read');
expect(Tld::where('server_id', $b->serverId)->where('tld', 'co.uk')->exists(), 'An unpriced discovered suffix is retained for cold search');
same(false, $writer->readOffers(new Query($b, ['co.uk'], 'register'), Context::Public)[0]->available,
    'Discovery alone cannot publish or select a suffix');
$worker->requestFacts($b, 'co.uk'); $worker->requestFacts($b, 'co.uk');
$worker->tick(1);
same(7, count($wire->calls), 'Concurrent cold requests coalesce to two Enom fact reads');
$worker->requestFacts($b, 'co.uk'); $worker->tick(1);
same(7, count($wire->calls), 'Warm independent facts are reused');
same('ready', $worker->status($b, 'co.uk')['state'], 'Lazy fact success is visible without granting sale authority');
// A buffered account feed must not delay a cold search until the whole account has drained.
$clock->time = $clock->time->modify('+1 minute'); $wire->extra = 30;
$worker->requestFacts($b, 'org'); $worker->sync($b);
$before = count($wire->calls); $worker->tick(1);
same(5, count($wire->calls) - $before, 'A large account still has one fixed-size feed read group');
same(8, $worker->status($b)['remaining'], 'Only 25 observations apply in one account turn');
$before = count($wire->calls); $worker->tick(1);
same(2, count($wire->calls) - $before, 'A pending feed batch cannot starve requested facts');
same(0, $worker->status($b)['remaining'], 'Saved feed observations drain without rereading prices');
$nativeSuccessful = true;
echo "Dormant offer queue and lazy facts checks passed.\n";
