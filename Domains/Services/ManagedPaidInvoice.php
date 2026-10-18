<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, Service};
use Paymenter\Extensions\Servers\Domains\Models\Domain;

/** Native observer resolves this adapter; managed activation belongs solely to proven completion. */
final class ManagedPaidInvoice extends \App\Services\Invoice\ProcessPaidInvoiceService
{
    public function handle(Invoice $invoice): void
    {
        $native = clone $invoice;
        $native->setRelation('items', $invoice->items()->get()->reject(static fn ($item): bool =>
            $item->reference_type === (new Service)->getMorphClass() && Domain::where('service_id', $item->reference_id)->exists()));
        parent::handle($native);
    }
}
