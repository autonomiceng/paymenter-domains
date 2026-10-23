<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Advisory selection only. Never apply these filters to reconciliation, retirement or refunds. */
final class RuntimeCandidates
{
    public static function invoiceAuthority(Builder $q): Builder
    {
        return $q->where('funding_basis','paid_invoice')->whereExists(fn($i)=>$i->selectRaw('1')->from('invoice_items as runtime_item')
            ->join('invoices as runtime_invoice','runtime_invoice.id','=','runtime_item.invoice_id')
            ->whereColumn('runtime_item.id','domains_operations.invoice_item_id')->whereColumn('runtime_invoice.id','domains_operations.invoice_id'));
    }
    public static function transfer(Builder $q): Builder
    {
        return self::invoiceAuthority($q)->where('action','transfer')->whereIn('state',['awaiting_payment','awaiting_code','queued','held']);
    }
    public static function billing(Builder $q): Builder
    {
        return $q->where(fn($q)=>$q->whereNotNull('adoption_id')->orWhere('provenance','converted_purchase')
            ->orWhereExists(function($o):void {
                $o->selectRaw('1')->from('domains_operations as runtime_op')->join('invoice_items as runtime_item','runtime_item.id','=','runtime_op.invoice_item_id')
                    ->join('invoices as runtime_invoice','runtime_invoice.id','=','runtime_item.invoice_id')
                    ->whereColumn('runtime_op.domain_id','domains_domains.id')->whereColumn('runtime_op.invoice_id','runtime_invoice.id')
                    ->whereColumn('runtime_op.authority_generation','domains_domains.authority_generation')->where('runtime_op.funding_basis','paid_invoice');
            }));
    }
    public static function transferReason(Operation $op): ?RuntimeReason
    {
        if($op->funding_basis==='offline_demo'){return RuntimeReason::OfflineProvenance;}
        if(!self::invoiceAuthority(Operation::whereKey($op->id))->exists()){return RuntimeReason::NoInvoiceAuthority;}
        return in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true)?null:RuntimeReason::LifecycleNotReady;
    }
    public static function billingReason(Domain $d): ?RuntimeReason
    {
        if(!self::billing(Domain::whereKey($d->id))->exists()){
            return $d->operations()->where('funding_basis','offline_demo')->exists()?RuntimeReason::OfflineProvenance:RuntimeReason::NoInvoiceAuthority;
        }
        if(!$d->provider_expires_at||!isset($d->renewal_policy['billing_anchor'])){return RuntimeReason::MissingAnchor;}
        return null;
    }
}
