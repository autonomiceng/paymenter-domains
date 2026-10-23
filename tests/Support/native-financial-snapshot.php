<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Invoice, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Bounded holding rows and fixture-wide finance; reminder alerts/deliveries are deliberately excluded. */
function nativeFinancialSnapshot(Operation $operation): array
{
    return [
        'service' => Service::findOrFail($operation->service_id)->getRawOriginal(),
        'domain' => Domain::findOrFail($operation->domain_id)->getRawOriginal(),
        'invoice' => $operation->invoice_id === null ? null : Invoice::findOrFail($operation->invoice_id)->getRawOriginal(),
        'transactions' => DB::table('invoice_transactions')->orderBy('id')->get()->toJson(),
        'invoice_count' => Invoice::count(),
        'operation_count' => Operation::count(),
        'credits' => DB::table('credits')->orderBy('id')->get()->toJson(),
    ];
}
