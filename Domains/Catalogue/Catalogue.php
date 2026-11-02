<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

/** Dormant contract. The persistence/lock implementation and caller cutover come separately. */
interface Catalogue
{
    /** Recheck binding/revisions under locks; commit offer, native projection and audit atomically.
     * Invalidate caches/withdraw affected carts after commit. Never perform provider reads here. */
    public function apply(Command $command, Revision $expectedRevision): Revision;

    /** Authorize staff/holder access before loading. Batch load, or reload under admission locks,
     * then use Evaluator for every result. Locked reads require each expected revision.
     * @return list<Decision> */
    public function readOffers(Query $query, Context $context): array;
}
