<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Credit, Invoice, InvoiceItem, InvoiceTransaction, Server, ServiceCancellation};
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain, Operation};
/** What DomainStates::project reads for a set of owned domains, in a fixed number of queries for up to 250 domains.
 * The Domains list loads a batch; the domain page loads its one domain. Reads only: no locks, writes or provider calls. */
final class DomainStateInputs
{
    private Collection $acquisitions, $cycles, $invoices, $items, $transactions, $cancel, $adoptions, $servers, $accounts;
    /** Domain ids without an expiry that have a renewal request in their generation. */
    private array $renewed = [];
    /** Domain ids whose retired renewal cycle fails billing's replacement proof. */
    private array $unproven = [];
    /** @var list<int> OwnedDomains::paying over the transaction batch. */
    private array $paying = [];
    /** @param array<int,Domain> $domains id => domain; @param array<int,\App\Models\Service> $services id => service */
    private function __construct(private array $domains, private array $services) {}
    public static function one(Domain $d): self
    { return self::load([(int) $d->id => $d], $d->service ? [(int) $d->service_id => $d->service] : []); }
    public static function load(array $domains, array $services): self
    {
        $in = new self($domains, $services); $ids = array_keys($domains);
        $current = fn ($q) => $q->join('domains_domains as d', 'd.id', '=', 'domains_operations.domain_id')->whereIn('d.id', $ids)
            ->whereColumn('d.authority_generation', 'domains_operations.authority_generation');
        $in->acquisitions = $ids ? Operation::whereIn('id', Operation::selectRaw('max(domains_operations.id)')->tap($current)
            ->whereColumn('d.account_fingerprint', 'domains_operations.account_fingerprint')->whereIn('action', ['register', 'transfer'])->groupBy('domain_id'))
            ->limit(250)->get()->keyBy('domain_id') : collect();
        // The current cycle: the newest request under this expiry's renewal key or one of its replacements.
        $keys = array_map(fn ($d) => RenewalCycles::key($d), array_filter($domains, fn ($d) => $d->provider_expires_at !== null));
        // Groups of 25 domains keep the condition shallow: SQLite refuses one flat chain of 500 terms.
        $in->cycles = $keys ? Operation::whereIn('id', Operation::selectRaw('max(id)')->where('action', 'renew')->whereIn('domain_id', array_keys($keys))
            ->where(function ($q) use ($keys): void { foreach (array_chunk($keys, 25) as $group) { $q->orWhere(function ($q) use ($group): void {
                foreach ($group as $key) { $q->orWhereLike('operation_key', $key, caseSensitive: true)->orWhereLike('operation_key', $key.':replacement:%', caseSensitive: true); } }); } })
            ->groupBy('domain_id'))->limit(250)->get()->keyBy('domain_id') : collect();
        $missing = array_keys(array_diff_key($domains, $keys));
        $in->renewed = $missing ? Operation::select('domain_id')->tap($current)->whereIn('d.id', $missing)->where('action', 'renew')->distinct()->limit(250)->pluck('domain_id')->map(fn ($id) => (int) $id)->all() : [];
        $cycleInvoices = $in->cycles->pluck('invoice_id')->filter()->all();
        $in->invoices = Invoice::whereIn('id', [...$cycleInvoices, ...$in->acquisitions->pluck('invoice_id')->filter()->all()])->get()->keyBy('id');
        $in->items = InvoiceItem::whereIn('invoice_id', $cycleInvoices)->get();
        // An unpaid order's payments too: a cancelled order says nothing was charged only when no payment came in or is on its way.
        $ordered = $in->acquisitions->where('state', 'awaiting_payment')->pluck('invoice_id')->filter()->all();
        $in->transactions = InvoiceTransaction::whereIn('invoice_id', array_values(array_unique([...$cycleInvoices, ...$ordered])))->get();
        $in->paying = OwnedDomains::paying($in->transactions);
        $serviceIds = array_map(fn ($d) => (int) $d->service_id, array_values($domains));
        $in->cancel = NativeCancellation::withIntents(ServiceCancellation::whereIn('service_id', $serviceIds)->get(), $serviceIds, true);
        $in->adoptions = Adoption::whereIn('id', array_filter(array_map(fn ($d) => $d->adoption_id, $domains)))->get()->keyBy('id');
        // A renewal still being confirmed reads its account, domain and server for the registrar check.
        $waiting = $in->cycles->whereIn('state', ['running', 'pending', 'uncertain'])->each(fn ($op) => $op->setRelation('domain', $domains[$op->domain_id]));
        $in->servers = Server::with('settings')->whereIn('id', [...array_map(fn ($d) => $d->server_id, $domains), ...$waiting->pluck('server_id')->all()])->get()->keyBy('id');
        $in->accounts = $waiting->isEmpty() ? collect() : Account::whereIn('server_id', $waiting->pluck('server_id')->unique()->all())->get()->groupBy('server_id');
        $in->prove();
        return $in;
    }
    /** A retired or refunded cycle permits a replacement only when billing's proof holds over every request in the generation. */
    private function prove(): void
    {
        $retired = $this->cycles->filter(fn ($op) => isset($this->domains[$op->domain_id])
            && ($op->safe_code === 'renewal_cancelled_unpaid' || RenewalReplacements::reviewed($op, $this->domains[$op->domain_id])));
        if ($retired->isEmpty()) { return; }
        $credits = Credit::whereIn('user_id', array_map(fn ($d) => $d->user_id, $this->domains))->where('currency_code', 'USD')->get()->keyBy('user_id');
        $ids = $retired->keys()->all();
        try {
            // Keep date validation in the shared proof; the writer computes the replacement number under locks.
            Operation::where('action', 'renew')->whereIn('domain_id', $ids)->chunkById(100, function ($ops) use ($credits): void {
                $invoices = $ops->pluck('invoice_id')->filter()->all();
                $chunk = ['invoices' => Invoice::whereIn('id', $invoices)->get()->keyBy('id'), 'items' => InvoiceItem::whereIn('invoice_id', $invoices)->get(),
                    'transactions' => InvoiceTransaction::whereIn('invoice_id', $invoices)->get()];
                foreach ($ops->groupBy('domain_id') as $id => $group) {
                    $d = $this->domains[$id]; $group = $group->filter(fn ($op) => (int) $op->authority_generation === (int) $d->authority_generation)->values();
                    if ($group->isEmpty() || isset($this->unproven[$id])) { continue; }
                    try { RenewalCycles::next(self::slice($d, $this->service($d), $group, $chunk) + ['credit' => $credits->get($d->user_id)]); }
                    catch (\Throwable) { $this->unproven[$id] = true; }
                }
            });
        } catch (\Throwable) { $this->unproven += array_fill_keys($ids, true); }
    }
    public function acquisition(Domain $d): ?Operation
    { return OwnedDomains::acquisition(collect(array_filter([$this->acquisitions->get($d->id)])), $d); }
    /** The owner's own invoice, or null. */
    public function invoice(?int $id, Domain $d): ?Invoice
    { $invoice = $this->invoices->get($id); return $invoice && (int) $invoice->user_id === (int) $d->user_id ? $invoice : null; }
    /** The newest payment attempt, from the same transaction batch as the renewal ledger. */
    public function latestTransactionStatus(Invoice $invoice): mixed
    { return $this->transactions->where('invoice_id', $invoice->id)->sortByDesc('id')->first()?->status; }
    /** Invoice ids with a payment that succeeded or is processing, for OwnedDomains::order. */
    public function paying(): array { return $this->paying; }
    public function service(Domain $d): ?\App\Models\Service { return $this->services[$d->service_id] ?? null; }
    public function cycle(Domain $d): ?Operation { return $this->cycles->get($d->id); }
    public function renewed(Domain $d): bool { return in_array((int) $d->id, $this->renewed, true); }
    public function unproven(Domain $d): bool { return isset($this->unproven[$d->id]); }
    /** A cancellation or native intent stops the service. */
    public function stopped(Domain $d): bool { return $this->cancel->contains('service_id', $d->service_id); }
    /** The RenewalStop read ledger. */
    public function stops(Domain $d): array
    { return ['domain' => $d, 'policy' => RenewalStop::policy($d), 'adoption' => $d->adoption_id === null ? null : $this->adoptions->get($d->adoption_id), 'cancel' => $this->cancel->where('service_id', $d->service_id)->values()]; }
    public function settings(Domain $d): ?Collection { return $this->servers->get($d->server_id)?->settings; }
    /** The customer's registrar check line for this renewal request, from the batch. */
    public function check(Operation $cycle): array
    { return RegistrarCheck::forCustomer($cycle, ['accounts' => $this->accounts->get($cycle->server_id, collect()), 'server' => $this->servers->get($cycle->server_id)]); }
    /** The billing ledger for these renewal requests, from the batch. */
    public function ledger(Domain $d, Collection $ops): array
    { return self::slice($d, $this->service($d), $ops, ['invoices' => $this->invoices, 'items' => $this->items, 'transactions' => $this->transactions]); }
    private static function slice(Domain $d, ?\App\Models\Service $service, Collection $ops, array $rows): array
    {
        $ids = $ops->pluck('invoice_id')->filter()->all();
        return ['domain' => $d, 'service' => $service, 'operations' => $ops, 'invoices' => $rows['invoices']->only($ids)->values(),
            'items' => $rows['items']->whereIn('invoice_id', $ids)->values(), 'transactions' => $rows['transactions']->whereIn('invoice_id', $ids)->values()];
    }
}
