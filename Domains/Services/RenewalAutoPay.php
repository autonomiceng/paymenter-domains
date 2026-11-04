<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Helpers\{ExtensionHelper, NotificationHelper};
use App\Models\{BillingAgreement, Gateway, Invoice, InvoiceItem, InvoiceTransaction, Service, ServiceCancellation};
use Illuminate\Support\Facades\{DB, Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Operation, Tld};

/** One saved-method attempt per newly issued debt. No retry entry point or funding authority. */
final readonly class RenewalAutoPay
{
    public function __construct(private Clock $clock) {}

    /** Whether a renewal invoice can be charged to the service's saved method today. The read-only form of the agreement checks in eligibility(). */
    public static function ready(?Service $service): bool
    {
        $agreement = $service?->billingAgreement;
        return $agreement && (int)$agreement->user_id === (int)$service->user_id && (bool)$agreement->gateway?->enabled;
    }

    /** Caller holds HoldingLocks in the invoice-issuing transaction, after native credit settlement. */
    public function issued(Operation $op, Invoice $invoice, array $l, bool $scheduled): void
    {
        if (array_key_exists('auto_pay', $op->funding ?? [])) { return; }
        [$reason, $agreement, $gateway] = $this->eligibility($op, $invoice, $l, $scheduled);
        // Committed BEFORE any gateway call. A crash leaves an uncertain attempt, never permission to retry.
        $marker = ['at'=>$this->clock->now()->format(DATE_ATOM), 'invoice_id'=>(int)$invoice->id,
            'agreement_id'=>$agreement?->id, 'gateway_id'=>$gateway?->id,
            'status'=>$reason === null ? 'uncertain' : 'skipped', 'reason'=>$reason];
        $op->update(['funding'=>[...($op->funding ?? []), 'auto_pay'=>$marker]]);
        if ($reason === null) { DB::afterCommit(fn () => $this->charge($op->id, $marker)); }
    }

    /** Current retained renewal authority. Call outside other transactions to freeze a gateway attempt; recheck with renewalAutoPayStillAllowed at confirm.
     * The gateway owns attempt/decline proof; this method neither collects nor retires pending money.
     * No result may be the first in-flight confirmation; the gateway's attempt record owns that proof.
     * @return array{service_id:int,agreement_id:int,operation_id:int,operation_key:string,generation:int,cutoff:int}|null
     */
    public function renewalAutoPayContext(int $invoiceId): ?array
    {
        OperationClaims::outside();
        try{
            $ops=Operation::where('invoice_id',$invoiceId)->get();
            if($ops->count()!==1){return null;}
            $seed=$ops->first()->domain()->firstOrFail();
            return DB::transaction(function()use($seed,$invoiceId):?array{
                $l=HoldingLocks::take($seed);$op=$l['operations']->firstWhere('invoice_id',$invoiceId);
                return $op ? $this->context($op,$l,true) : null;
            });
        }catch(\Throwable){return null;}
    }

    /** Plain-read final check for gateways already holding payment locks; errors never grant a debit. */
    public function renewalAutoPayStillAllowed(int $invoiceId, array $expected): bool
    {
        try {
            $op=Operation::where('invoice_id',$invoiceId)->sole();
            $domain=$op->domain()->firstOrFail();$service=Service::findOrFail($domain->service_id);
            $server=$service->product->server;$account=Account::where('server_id',$domain->server_id)->firstOrFail();
            $adoption=$domain->adoption_id===null?null:Adoption::find($domain->adoption_id);
            $cancel=NativeCancellation::withIntents(ServiceCancellation::where('service_id',$service->id)->get(),[(int)$service->id],true);
            $operations=Operation::where('domain_id',$domain->id)->orderBy('id')->get();
            $invoices=Invoice::whereIn('id',$operations->pluck('invoice_id'))->get();
            $items=InvoiceItem::whereIn('invoice_id',$invoices->pluck('id'))->get();
            $transactions=InvoiceTransaction::whereIn('invoice_id',$invoices->pluck('id'))->get();
            $tld=Tld::where(['server_id'=>$domain->server_id,'tld'=>$domain->tld])->firstOrFail();
            $native=['user'=>$service->user,'service'=>$service,'product'=>$service->product,'plan'=>$service->plan,
                'orders'=>collect([$service->order]),'properties'=>$service->properties()->get(),'markers'=>$service->product->settings()->get(),
                'configs'=>$service->configs()->get(),'upgrades'=>DB::table('product_upgrades')->where('product_id',$service->product_id)->orWhere('upgrade_id',$service->product_id)->get(),
                'links'=>DB::table('config_option_products')->where('product_id',$service->product_id)->get()];
            if($domain->adoptionPending()||!$domain->billing_managed||$domain->reservation_name!==$domain->domain
                ||$account->account_fingerprint!==$domain->account_fingerprint||$account->registrar_key!==$domain->registrar_key){return false;}
            $identity_source=HoldingIdentitySource::resolve($domain,HoldingNativeIdentity::capture($domain,$native,$tld),$adoption,$account,$cancel);
            $current=$this->context($op,compact('domain','service','server','account','adoption','cancel','operations','invoices','items','transactions','identity_source'),false);
            return $current!==null && $current===$expected;
        } catch(\Throwable) { Log::warning('Domains renewal Auto Pay payment-time check failed; debit refused.'); return false; }
    }

    private function context(Operation $op,array $l,bool $lock): ?array
    {
        if(isset($op->policy['renewal']['late'])||$op->action!=='renew'||!in_array($op->state,['awaiting_payment','queued'],true)||!RenewalCycles::registrarVirgin($op)
            ||!RenewalBillingSettings::read((int)$op->server_id,$lock)['scheduled']
            ||EnomTestRenewal::holdsInvoices(AccountBindings::binding($l['account']))
            ||$l['service']->subscription_id||($l['domain']->renewal_policy['automatic_spend_paused']??false)){return null;}
        app(AccountBindings::class)->requireProcessingLocked($l['server'],$l['account'],!$lock);
        RenewalFunding::requireAuthority($op,$l);
        $invoice=DomainBilling::existingInvoice($op,$l);
        [$reason,$agreement,$gateway]=$this->eligibility($op,$invoice,$l,true,$lock);
        $marker=$op->funding['auto_pay']??[];
        if(in_array($op->funding['auto_pay_result']['status']??null,['unknown','submitted'],true)){return null;}
        $cutoff=new \DateTimeImmutable($op->policy['calendar']['due'].'T00:00:00',$this->clock->now()->getTimezone());
        if($reason!==null||$cutoff<=$this->clock->now()||(!array_key_exists('reason',$marker)||$marker['reason']!==null)
            ||($marker['agreement_id']??null)!==$agreement->id||($marker['gateway_id']??null)!==$gateway->id){return null;}
        return ['service_id'=>(int)$op->service_id,'agreement_id'=>(int)$agreement->id,'operation_id'=>(int)$op->id,
            'operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'cutoff'=>$cutoff->getTimestamp()];
    }

    /** Only definite non-collection permits unpaid retirement or replacement. */
    public static function unattempted(Operation $op): bool
    {
        $f = $op->funding ?? [];
        if (!array_key_exists('auto_pay', $f)) { return !array_key_exists('auto_pay_result', $f); }
        $result = $f['auto_pay_result'] ?? $f['auto_pay'];
        return is_array($result) && in_array($result['status'] ?? null, ['skipped', 'declined'], true);
    }

    private function eligibility(Operation $op, Invoice $invoice, array $l, bool $scheduled, bool $lock = true): array
    {
        $s = $l['service']; $d = $l['domain'];
        $reason = match (true) {
            LateRenewalQuote::deadline($op) !== null && (!EnomLateRenewal::accepted(AccountBindings::binding($l['account'])) || LateRenewalQuote::deadline($op) <= $this->clock->now()->modify('+25 seconds')) => 'late_renewal_closed',
            !$scheduled => 'manual_renewal',
            $op->currency !== 'USD' || $invoice->currency_code !== 'USD' || $s->currency_code !== 'USD' => 'currency',
            $s->status !== 'active' => 'service_inactive',
            RenewalStop::blocked($l) => 'renewal_off',
            RenewalStop::ownerOff($l) => 'owner_auto_renew_off',
            $op->service_id !== $d->service_id || $op->authority_generation !== $d->authority_generation
                || (int)$invoice->user_id !== (int)$d->user_id || (int)$s->user_id !== (int)$d->user_id => 'authority_changed',
            $invoice->status !== Invoice::STATUS_PENDING || PricingRule::minor($invoice->remaining) <= 0 => 'invoice_settled',
            !$s->billing_agreement_id => 'no_agreement',
            default => null,
        };
        if ($reason !== null) { return [$reason, null, null]; }
        $agreement = BillingAgreement::whereKey($s->billing_agreement_id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        if (!$agreement) { return ['no_agreement', null, null]; }
        if ((int)$agreement->user_id !== (int)$s->user_id) { return ['foreign_agreement', $agreement, null]; }
        $gateway = Gateway::whereKey($agreement->gateway_id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        return [!$gateway ? 'gateway_missing' : (!(bool)$gateway->enabled ? 'gateway_disabled' : null), $agreement, $gateway];
    }

    private function charge(int $id, array $marker): void
    {
        // Refresh authority after commit, without holding locks during gateway I/O.
        $op = Operation::findOrFail($id); $seed = $op->domain()->firstOrFail();
        try {
            $ready = DB::transaction(function () use ($id, $seed, $marker): ?array {
                $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
                if (!$op || ($op->funding['auto_pay'] ?? null) !== $marker || array_key_exists('auto_pay_result', $op->funding ?? [])) { return null; }
                $invoice = $l['invoices']->firstWhere('id', $op->invoice_id);
                if (!$invoice) { throw new \DomainException('Renewal invoice unavailable.'); }
                [$reason, $agreement, $gateway] = $this->eligibility($op, $invoice, $l, true);
                if ($reason === null && ($agreement->id !== $marker['agreement_id'] || $gateway->id !== $marker['gateway_id'])) { $reason = 'agreement_changed'; }
                if ($reason !== null) { $this->resultLocked($op, ['status'=>'skipped', 'reason'=>$reason]); return null; }
                return [$invoice, $agreement, $gateway];
            });
        } catch (\Throwable) {
            $this->result($id, ['status'=>'skipped', 'reason'=>'authority_changed']); return;
        }
        if ($ready === null) { return; }
        [$invoice, $agreement, $gateway] = $ready;
        try {
            $submitted = ExtensionHelper::charge($gateway, $invoice, $agreement);
        } catch (\Throwable) {
            $this->result($id, ['status'=>'unknown']);
            Log::warning('Domains renewal Auto Pay outcome unknown; staff review required.');
            return;
        }
        if (!$submitted) {
            $notification = 'requested';
            try { NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice); }
            catch (\Throwable) { $notification = 'failed'; }
            $this->result($id, ['status'=>'declined', 'notification'=>$notification]); return;
        }
        // Persistence failure here leaves the original uncertain marker. It must never repeat the charge.
        $this->result($id, ['status'=>'submitted']);
    }

    private function result(int $id, array $result): void
    {
        DB::transaction(function () use ($id, $result): void {
            $this->resultLocked(Operation::whereKey($id)->lockForUpdate()->firstOrFail(), $result);
        });
    }
    private function resultLocked(Operation $op, array $result): void
    {
        if (array_key_exists('auto_pay_result', $op->funding ?? [])) { return; }
        $op->update(['funding'=>[...($op->funding ?? []), 'auto_pay_result'=>[...$result, 'at'=>$this->clock->now()->format(DATE_ATOM)]]]);
    }
}
