<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

/** Explicit, dormant composition; never container-resolved by a production caller.
 * authorize owns staff permission/holding ownership/command scope. featureReady must use existing
 * LOCAL runtime/feature acceptance only, never price summaries, provider factories or I/O.
 * No default grant: both trusted deployment callbacks are required, rechecked on each invocation. */
final class CallerPorts implements Catalogue
{
    public static function routed(): ?self { return CallerRouting::resolve(self::class); }
    private readonly Writer $writer;
    private readonly OfferReader $reader;

    public function __construct(Projection $projection, private readonly Clock $clock, private readonly AccountBindings $bindings,
        private readonly \Closure $authorize, private readonly \Closure $featureReady, ?int $actorId = null, private readonly ?\Closure $current = null)
    {
        $ready = static function (AccountBinding $binding, Server $server, Account $account, ?Query $query = null) use ($bindings, $featureReady): bool {
            try {
                $bindings->requireOfferAccount($binding, $server, $account);
                return $featureReady($binding, $query?->action) === true;
            } catch (\DomainException) { return false; }
        };
        $this->writer = new Writer($projection, $clock, $authorize, $ready, $actorId, $current);
        $this->reader = new OfferReader($clock, $authorize, $ready, $current);
    }

    public function writer(): Writer { return $this->writer; }

    public function apply(Command $command, Revision $expectedRevision): Revision
    { return $this->writer->apply($command, $expectedRevision); }

    public function readOffers(Query $query, Context $context): array
    { return $this->reader->readOffers($query, $context); }

    public function readBatch(array $queries, Context $context, bool $lock = false): array
    { return $this->reader->readBatch($queries, $context, $lock); }

    /** Server-only adapter evidence, not an input DTO or receipt constructor. */
    public function loadBatch(array $queries, Context $context, bool $lock = false): array
    { return $this->reader->loadBatch($queries, $context, $lock); }

    public function displayBatch(array $queries): array { return $this->display($queries, Context::Public); }
    public function staffBatch(array $queries): array { return $this->display($queries, Context::Staff); }
    private function display(array $queries, Context $context): array
    {
        // Base credential/processing proof is shared only inside this one unlocked display load.
        // Feature acceptance remains action-specific; strict receipt/locked readers never use this cache.
        $checked = [];
        $ready = function (AccountBinding $b, Server $server, Account $account, Query $q) use (&$checked): bool {
            if (!array_key_exists($b->serverId, $checked)) {
                try { $this->bindings->requireOfferAccount($b, $server, $account); $checked[$b->serverId] = true; }
                catch (\DomainException) { $checked[$b->serverId] = false; }
            }
            return $checked[$b->serverId] && ($this->featureReady)($b, $q->action) === true;
        };
        return (new OfferReader($this->clock, $this->authorize, $ready, $this->current))->displayBatch($queries, $context);
    }
}
