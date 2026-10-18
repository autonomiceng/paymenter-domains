<?php
// Synthetic Enom 444f31c send-boundary scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, RegistrationTransport, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, Disposition, DriverResult, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, DomainOperations, WriteAcceptance, WriteFeatures};
$transport = new class extends RegistrationTransport {
    public array $calls = []; public bool $fail = false;
    protected function sendRegistration(AdmittedOperation $op, RequestContext $context, #[SensitiveParameter] array $payload, string $origin, int $timeoutMs): DriverResult
    {
        same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'No wire work under financial locks');
        $this->calls[] = compact('op', 'context', 'payload', 'origin', 'timeoutMs');
        if ($this->fail) { throw new RuntimeException('Secret synthetic raw registrar URL and response'); }
        return new DriverResult($op->binding, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved);
    }
};
$proxy = new class implements AdmittedRegistrarDriver, WriteGate {
    public AdmittedRegistrarDriver $bridge; public $before = null;
    public function requireEnabled(Action $action): void { $this->bridge->requireEnabled($action); }
    public function reserve(): void { $this->bridge->reserve(); }
    public function requireAtSeal($op, $domain, $ready): void { $this->bridge->requireAtSeal($op, $domain, $ready); }
    public function register(AdmittedOperation $op, RequestContext $ctx): DriverResult {
        if ($this->before) { ($this->before)($op, $ctx); } return $this->bridge->register($op, $ctx);
    }
    public function transferIn(AdmittedOperation $op, RequestContext $ctx): DriverResult { return $this->bridge->transferIn($op, $ctx); }
    public function renew(AdmittedOperation $op, RequestContext $ctx): DriverResult { return $this->bridge->renew($op, $ctx); }
};
$build = static function ($binding, ?WriteFeatures $features = null, ?WriteAcceptance $acceptance = null) use (&$engine, $claims, $preflight, $clock, $proxy, $transport) {
    $features ??= new WriteFeatures(registration: true);
    $acceptance ??= new WriteAcceptance($binding, true, true, 25, static fn () => true);
    $class = 'Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($binding->registrarKey === 'enom' ? 'Enom' : 'NameSilo').'\\Writer';
    $bridge = new $class($binding, $features, $acceptance, static function () use (&$engine) { return $engine; }, $transport);
    $proxy->bridge = $bridge; $proxy->before = null;
    $engine = new DomainOperations($claims, $preflight, $proxy, $clock);
    Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$binding->fingerprint);
    return $bridge;
};
$enomBinding = CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[0]->server_id)->sole());
$nsBinding = CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[1]->server_id)->sole());
