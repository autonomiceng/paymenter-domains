<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native local binding fixture.
declare(strict_types=1);
require __DIR__.'/binding.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Disposition, DriverResult, Environment, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, FoundationSchema, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

// Local enrollment needs the real native settings encryption, review locks and retained lineage.
// A unit fixture cannot establish their atomic behavior together.
config(['domains.namesilo_account.environment' => 'live', 'app.url' => 'https://live.example.com']);
$localBoot = new RegistrarRegistry;
\Paymenter\Extensions\Servers\Domains\Services\RegistrarFactories::bootstrap($localBoot);
expect(in_array('namesilo', $localBoot->keys(), true), 'NameSilo local enrollment is composed without an identity verifier');
require __DIR__.'/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Services\{LocalAccountEnrollment, StaffAuditSchema, StaffSettingsReview, SalesPolicySchema, NameSiloAccountGuard};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
SalesPolicySchema::install(); StaffAuditSchema::install();
$staff = staffUser(['admin.domains.view', 'admin.domains.manage-policy', StaffSettingsReview::permission('configure')]);
$review = static function (int $id) use ($staff): StaffSettingsReview {
    return new StaffSettingsReview((int) $staff->id, $id, null, 'configure',
        StaffSettingsReview::ticket((int) $staff->id, $id, null, 'configure', StaffSettingsReview::stamp($id, null)), 'Rotate the synthetic local account key');
};
$wire = new class implements DeadlineTransport {
    public array $calls = [];
    public string $body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"synthetic","balance":"0.00"}}';
    public ?Closure $during = null;
    public int $status = 200;
    public bool $timeout = false;
    public function send(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey): TransportResponse
    { throw new LogicException('Authentication requires a deadline.'); }
    public function sendWithin(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        same(0, DB::transactionLevel(), 'Key authentication never holds database locks');
        $this->calls[] = [$environment, $context, $request->command, $request->parameters(), $timeoutMs];
        if ($this->during) { $during = $this->during; $this->during = null; $during(); }
        if ($this->timeout) { throw new RuntimeException('synthetic deadline elapsed'); }
        return new TransportResponse($this->status, $this->body);
    }
};
$auth = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\CredentialAuthenticator(Environment::Live, $wire);
$localRegistry = new RegistrarRegistry;
$localRegistry->register('namesilo', ['api_key'], new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\AccountVerifier, $factory, authenticator: $auth);
$local = new AccountBindings($localRegistry); app()->instance(AccountBindings::class, $local);
