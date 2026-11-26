<?php
// Adapted from Enom d2c55e2 Models/Operation.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AttemptFacts, AttemptToken, OperationAttempts, OperationSchema};

class Operation extends Model
{
    use HasAccountBinding;

    /** Transaction-local capability evidence; never an attribute, relationship or persisted authority. */
    public ?Tld $catalogueTld = null;
    /** Exact worker authority, set by the claim or seal transaction and never persisted. */
    public ?AttemptToken $acquisitionWorker = null;
    /** @var array{facts: AttemptFacts, action: string, start: ?\DateTimeImmutable}|array{}|null */
    private ?array $acquisitionRow = null;
    private AcquisitionAttempt|\Throwable|null $acquisitionAttempt = null;
    private bool $acquisitionLoaded = false;
    private bool $noAcquisitionStep = false;

    protected $table = 'domains_operations';
    protected $guarded = [];
    // Keep the first attempt's schema default on the model returned by create().
    protected $attributes = ['retry_number' => 0];
    protected $hidden = ['account_fingerprint', 'payload', 'policy', 'cost', 'attempt', 'renewal_previous_proof', 'transition_revision', 'funding', 'funding_reason', 'authorized_by', 'request_fingerprint', 'management_pending'];
    protected $casts = ['transition_revision' => 'integer', 'retry_number'=>'integer', 'attempt' => 'encrypted:array', 'funding' => 'encrypted:array', 'authority_generation' => 'integer', 'payload' => 'encrypted:array', 'policy' => 'encrypted:array', 'cost' => 'encrypted:array', 'amount' => 'decimal:2', 'base_expires_at' => 'immutable_date', 'expected_expires_at' => 'immutable_date', 'confirmed_expires_at' => 'immutable_date', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    /**
     * The retained attempt record. A renewal's attempt lives in its attempt and evidence rows, so its retired column reads
     * as empty and is never decrypted: an unreadable copy cannot block a refund, a save guard or any other reader.
     * Writes still encrypt through the cast.
     */
    protected function attempt(): Attribute
    {
        return $this->retainedEvidence('attempt');
    }

    protected function funding(): Attribute { return $this->retainedEvidence('funding'); }
    protected function policy(): Attribute { return $this->retainedEvidence('policy'); }

    /** @return Attribute */
    private function retainedEvidence(string $field)
    {
        return Attribute::make(
            get: function (?string $value, array $row) use ($field): ?array {
                return $this->projectEvidence($field, $value, $row);
            },
            set: function (?array $value, array $row) use ($field): ?string {
                if ($attempt = $this->acquisition($row)) {
                    $value = $attempt->keepOperationFacts($field, $this->acquisitionEvidence($field, $row), $value);
                }
                return $value === null ? null : $this->castAttributeAsEncryptedString($field, $this->asJson($value));
            },
        );
    }

    private function projectEvidence(string $field, ?string $value, array $row): ?array
    {
        if ($field === 'attempt' && ($row['action'] ?? null) === 'renew') { return []; }
        $stored = $this->decodeEvidence($value);
        $projection = $this->acquisitionEvidence($field, $row);
        return $projection ? array_replace($stored ?? [], $projection) : $stored;
    }

    private function decodeEvidence(?string $value): ?array
    {
        return $value === null ? null : $this->fromJson($this->fromEncryptedString($value));
    }

    /** Receipt returns are owned by the operation, independently of claim and seal funding. */
    public function receiptFunding(): array
    {
        return $this->decodeEvidence($this->attributes['funding'] ?? null) ?? [];
    }

    public function getOriginal($key = null, $default = null)
    {
        if (in_array($key, ['attempt', 'funding', 'policy'], true)) {
            // Both versions use the same retained attempt. Eloquent otherwise constructs a fresh model on every call.
            return $this->projectEvidence($key, Arr::get($this->original, $key, $default), $this->original);
        }
        return parent::getOriginal($key, $default);
    }

    /** Read step presence with the operation when the caller has a current view of the ledger. */
    public function scopeWithAcquisitionStep(Builder $query): Builder
    {
        return $query->addSelect(['has_acquisition_step' => DB::table(OperationSchema::STEPS)
            ->select('id')->whereColumn('operation_id', 'domains_operations.id')
            ->whereIn('step_type', ['register', 'transfer'])->where('sequence', 1)
            ->whereColumn('step_type', 'domains_operations.action')->limit(1)]);
    }

    private function acquisitionEvidence(string $field, array $row): array
    {
        $attempt = $this->acquisition($row);
        if (!$attempt) { return []; }
        return match ($field) {
            'attempt' => $attempt->attempt(),
            'funding' => $attempt->funding(),
            'policy' => ($answer = $attempt->answer()) ? ['enom_answer' => $answer] : [],
        };
    }

    private function acquisitionRow(array $row): array
    {
        if ($this->noAcquisitionStep
            || !isset($row['id'], $row['action'], $row['transition_revision'])
            || AcquisitionAttempt::key($this->getRawOriginal('action') ?? $row['action']) === null) { return []; }
        $id = (int) $row['id'];
        return $this->acquisitionRow ??= OperationAttempts::latestAcquisitions([$id])[$id] ?? [];
    }

    private function acquisition(array $row): ?AcquisitionAttempt
    {
        if (!($facts = $this->acquisitionRow($row))) { return null; }
        if (!$this->acquisitionLoaded) {
            $id = (int) $row['id'];
            $this->acquisitionAttempt = AcquisitionAttempt::many([$id => $facts])[$id];
            $this->acquisitionLoaded = true;
        }
        if ($this->acquisitionAttempt instanceof \Throwable) { throw $this->acquisitionAttempt; }
        return $this->acquisitionAttempt;
    }

    /** Batch the attempt rows for staff readers. Date-only readers need no encrypted evidence. */
    public static function loadAcquisitionAttempts(iterable $operations, bool $withEvidence = true): void
    {
        $pending = [];
        foreach ($operations as $op) {
            if ($op->noAcquisitionStep) { continue; }
            if (!$op->acquisitionLoaded && $op->getRawOriginal('id') !== null
                && AcquisitionAttempt::key((string) $op->getRawOriginal('action')) !== null) {
                $pending[(int) $op->id][] = $op;
            }
        }
        $rows = OperationAttempts::latestAcquisitions(array_keys($pending));
        $attempts = $withEvidence ? AcquisitionAttempt::many($rows) : [];
        foreach ($pending as $id => $models) {
            foreach ($models as $op) {
                $op->acquisitionRow = $rows[$id] ?? [];
                $op->acquisitionAttempt = $attempts[$id] ?? null;
                $op->acquisitionLoaded = $withEvidence;
            }
        }
    }

    /** Called after a writer transition on this model's locked operation. */
    public function reloadAcquisitionAttempt(): void
    {
        $this->acquisitionRow = null;
        $this->acquisitionAttempt = null;
        $this->acquisitionLoaded = false;
        $this->noAcquisitionStep = false;
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->reloadAcquisitionAttempt();
        $this->noAcquisitionStep = array_key_exists('has_acquisition_step', $attributes) && $attributes['has_acquisition_step'] === null;
        unset($attributes['has_acquisition_step']);
        return parent::setRawAttributes($attributes, $sync);
    }

    protected function startedAt(): Attribute
    {
        return Attribute::get(function ($value, array $row) {
            $acquisition = $this->acquisitionRow($row);
            $start = $acquisition ? $acquisition['start'] : $value;
            return $start === null ? null : $this->asDateTime($start)->toImmutable();
        })->withoutObjectCaching();
    }

    protected function requestFingerprint(): Attribute
    {
        return Attribute::get(fn ($value, array $row) => ($attempt = $this->acquisition($row)) ? $attempt->digest() : $value);
    }

    protected function leaseExpiresAt(): Attribute
    {
        return Attribute::get(function ($value, array $row) {
            $facts = $this->acquisitionRow($row)['facts'] ?? null;
            if (!$facts) { return $value === null ? null : $this->asDateTime($value)->toImmutable(); }
            return ($row['state'] ?? null) === 'running' && $facts->current && $facts->claimUntil !== null
                ? $this->asDateTime($facts->claimUntil)->toImmutable() : null;
        })->withoutObjectCaching();
    }

    protected static function booted(): void
    {
        static::saving(static fn(self $operation)=>\Paymenter\Extensions\Servers\Domains\Services\ManagementProgressIndex::capture($operation));
        static::creating(static fn(self $operation)=>\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::guard($operation));
        static::creating(static fn(self $operation)=>\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::guard($operation));
        static::updating(static fn(self $operation)=>\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::guard($operation));
        static::creating(static fn(self $operation)=>\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::guardCreation($operation));
        static::creating(static function(self $operation):void {
            if(array_key_exists('management_unsent',$operation->attempt??[])){throw new \DomainException('Unsent management proof requires a live dispatched operation.');}
        });
        static::updating(static function (self $operation): void {
            $dirty = array_keys($operation->getDirty());
            $claimIdentity = array_intersect($dirty, ['started_at', 'request_fingerprint', 'lease_expires_at']);
            if (AcquisitionAttempt::key($operation->action) && ($claimIdentity || in_array('payload', $dirty, true))) {
                // A model loaded before a worker's seal may still have an empty, cached attempt.
                $operation->reloadAcquisitionAttempt();
                $attempt = $operation->acquisition($operation->attributes);
                if ($attempt && ($attempt->sealed() || $claimIdentity)) {
                    throw new \DomainException('Acquisition authority belongs to its operation attempt.');
                }
            }
            if (AcquisitionAttempt::key($operation->action) && $operation->acquisition($operation->attributes)) {
                $next = (int) $operation->getRawOriginal('transition_revision') + 1;
                if (in_array('state', $dirty, true)) { $operation->transition_revision = $next; }
                elseif (in_array('transition_revision', $dirty, true) && $operation->transition_revision !== $next) {
                    throw new \DomainException('Acquisition revision must advance by one.');
                }
            }
            if ($operation->action === 'renew') {
                $next = (int) $operation->getOriginal('transition_revision') + 1;
                if (in_array('state', $dirty, true)) {
                    $operation->transition_revision = $next;
                } elseif (in_array('transition_revision', $dirty, true) && $operation->transition_revision !== $next) {
                    throw new \DomainException('Renewal revision must advance by one.');
                }
                // The sealed attempt row freezes what it was sealed against: the desired state, start and request digest.
                if (array_intersect($dirty, ['payload', 'started_at', 'request_fingerprint'])
                    && \Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationAttempts::sealed((int) $operation->id, \Paymenter\Extensions\Servers\Domains\Operations\Authority\StepKey::renew()) !== null) {
                    throw new \DomainException(in_array('payload', $dirty, true) ? 'Sealed desired state is frozen.' : 'Original attempt identity is frozen.');
                }
            }
            if (array_intersect($dirty, ['id', 'domain_id', 'service_id', 'server_id', 'registrar_key', 'account_fingerprint', 'authority_generation', 'action', 'term', 'currency', 'amount', 'invoice_id', 'invoice_item_id', 'operation_key', 'funding_basis', 'authorized_by', 'funding_reason', 'retry_number', 'renewal_previous_proof'])) {
                throw new \DomainException('Operation binding and action are immutable.');
            }
        });
        static::updating(static function (self $operation): void {
            \Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::guard($operation);
            $old = $operation->getOriginal('policy') ?? []; $new = $operation->policy ?? [];
            if (isset($old['renewal']) && $operation->isDirty(['base_expires_at', 'expected_expires_at'])) {
                throw new \DomainException('Issued renewal expiry obligations are frozen.');
            }
            foreach (['funds_wait_at', 'funds_wait_until', 'native_acquisition_cancelled_at', 'native_acquisition_closure', 'held_order_refund', 'unpaid_retirement', 'account_complimentary', 'checkout', 'price', 'seller_revision', 'calendar', 'renewal', 'complimentary', 'management', 'privacy'] as $key) {
                if (array_key_exists($key, $old) && ($new[$key] ?? null) !== $old[$key]) { throw new \DomainException('Checkout evidence is frozen.'); }
            }
            $old = $operation->getOriginal('cost') ?? []; $new = $operation->cost ?? [];
            foreach (['estimated', 'currency', 'unit', 'actual'] as $key) {
                if (array_key_exists($key, $old) && ($new[$key] ?? null) !== $old[$key]) { throw new \DomainException('Estimated cost evidence is frozen.'); }
            }
        });
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\ContactSetup::guardSnapshot($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\TransferInputs::guard($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\OwnerSetup::guard($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\ManagementEvidence::guard($operation));
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::noteReturn($operation));
        static::updated(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::settle($operation));
        static::updated(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\RequestAlerts::changed($operation));
        static::updating(static function (self $operation): void {
            $before = $operation->getOriginal('policy') ?? []; $after = $operation->policy ?? [];
            if (array_key_exists('contact_disposition', $before)
                && (!array_key_exists('contact_disposition', $after) || $after['contact_disposition'] !== $before['contact_disposition'])) {
                throw new \DomainException('Contact disposition is frozen.');
            }
            foreach (['staff_actions'=>100,'renewal_resumptions'=>20] as $key=>$limit) {
                $history=$before[$key]??[]; $next=$after[$key]??[];
                if(!is_array($history)||!is_array($next)||!array_is_list($next)||count($next)>$limit||count($next)<count($history)
                    ||array_slice($next,0,count($history))!==$history){throw new \DomainException('Staff review history is append-only and bounded.');}
            }
            if(array_key_exists('renewal_replacement',$before)&&($after['renewal_replacement']??null)!==$before['renewal_replacement']){
                throw new \DomainException('Renewal replacement review is retained.');
            }
            $history = $before['reconciliation_resumptions'] ?? []; $next = $after['reconciliation_resumptions'] ?? [];
            if (!is_array($history) || !is_array($next) || !array_is_list($next) || count($next) > 20
                || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) { throw new \DomainException('Reconciliation resumptions are append-only.'); }
            if (array_key_exists('contact_readmissions', $before) || array_key_exists('contact_readmissions', $after)) {
                $history = array_key_exists('contact_readmissions', $before) ? $before['contact_readmissions'] : []; $next = $after['contact_readmissions'] ?? null;
                if (!is_array($history) || !array_is_list($history) || !is_array($next) || !array_is_list($next)
                    || count($next) > 10 || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) {
                    throw new \DomainException('Contact readmissions are append-only and bounded.');
                }
            }
            $old = $operation->getOriginal('attempt') ?? []; $new = $operation->attempt ?? [];
            if (($old['test_call_refusals'] ?? []) !== ($new['test_call_refusals'] ?? [])) {
                throw new \DomainException('TEST call refusal history belongs to the operation attempt.');
            }
            \Paymenter\Extensions\Servers\Domains\Services\BalanceRetries::guard($operation);
            if (!array_key_exists('registration_admission', $old) && array_key_exists('registration_admission', $new)
                && (($old['seal'] ?? null) !== 'absent' || ($new['seal'] ?? null) !== 'sealed' || $operation->action !== 'register')) {
                throw new \DomainException('Registration admission can only be recorded at the original seal.');
            }
            if (!array_key_exists('transfer_admission', $old) && array_key_exists('transfer_admission', $new)
                && (($old['seal'] ?? null) !== 'absent' || ($new['seal'] ?? null) !== 'sealed' || $operation->action !== 'transfer')) { throw new \DomainException('Transfer admission requires the original seal.'); }
            foreach (['transfer_admission', 'transfer_terminal', 'claim_key', 'dispatch_attempted_at', 'authorized_cost', 'renewal_mode', 'renewal', 'registration_admission', 'execution', 'send_failure', 'evidence', 'charge', 'order_candidates', 'registration', 'corrections', 'reconciliation', 'terminal', 'transfer_timeout'] as $key) {
                if (array_key_exists($key, $old) && (!array_key_exists($key, $new) || $new[$key] !== $old[$key])) {
                    throw new \DomainException('Attempt evidence is write-once.');
                }
            }
            if (isset($old['claim_key']) && $operation->isDirty(['started_at', 'request_fingerprint'])) { throw new \DomainException('Original attempt identity is frozen.'); }
            if (isset($old['reconciliation']) && $operation->isDirty(['provider_order_id', 'confirmed_expires_at', 'completed_at'])&&!\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::completionAllowed($operation)) { throw new \DomainException('Completion evidence is frozen.'); }
            if (($old['seal'] ?? null) === 'sealed' && $operation->isDirty('payload')) {
                throw new \DomainException('Sealed desired state is frozen.');
            }
            foreach (['claim', 'seal', 'refund_authorization', 'refund'] as $key) {
                $before = $operation->getOriginal('funding') ?? []; $after = $operation->funding ?? [];
                if (array_key_exists($key, $before) && (!array_key_exists($key, $after) || $after[$key] !== $before[$key])) {
                    throw new \DomainException('Claim funding evidence is frozen.');
                }
            }
            $before=$operation->getOriginal('funding')??[];$after=$operation->funding??[];
            foreach(['late_credits','dispute_holds'] as $key){
                $history=$before[$key]??[];$next=$after[$key]??[];
                if(!is_array($history)||!is_array($next)||!array_is_list($next)||array_slice($next,0,count($history))!==$history){throw new \DomainException('Money evidence is append-only.');}
            }
            foreach($before['receipt_return_results']??[] as $key=>$result){
                $next=$after['receipt_return_results'][$key]??null;
                if(!is_array($next)||in_array($result['state']??null,['credited','released'],true)&&$next!==$result){throw new \DomainException('Receipt returns are retained.');}
                foreach(['amount_minor','key','refund_id'] as $field){
                    if(isset($result[$field])&&($next[$field]??null)!==$result[$field]){throw new \DomainException('Receipt return identity is frozen.');}
                }
            }
            foreach (['auto_pay', 'auto_pay_result'] as $key) {
                $before = $operation->getOriginal('funding') ?? []; $after = $operation->funding ?? [];
                if (array_key_exists($key, $before) && (!array_key_exists($key, $after) || $after[$key] !== $before[$key])) {
                    throw new \DomainException('Auto Pay evidence is write-once.');
                }
            }
            $history = $operation->getOriginal('funding')['financial_adjustments'] ?? [];
            $next = $operation->funding['financial_adjustments'] ?? [];
            if (!is_array($history) || !array_is_list($history) || !is_array($next) || !array_is_list($next)
                || count($next) > 20 || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) {
                throw new \DomainException('Financial adjustments are append-only.');
            }
            if (($old['seal'] ?? null) === 'sealed' && ($new['seal'] ?? null) !== 'sealed') {
                throw new \DomainException('Dispatch seals cannot be removed.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Operation ledger rows are retained.'); });
    }

    public function invoice() { return $this->belongsTo(\App\Models\Invoice::class); }
    public function domain() { return $this->belongsTo(Domain::class); }
}
