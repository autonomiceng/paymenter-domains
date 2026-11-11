<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native binding coverage.
declare(strict_types=1);
require __DIR__.'/Support/binding-local.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Disposition, DriverResult, Environment, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, FoundationSchema, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
use Paymenter\Extensions\Servers\Domains\Services\{LocalAccountEnrollment, StaffAuditSchema, StaffSettingsReview, SalesPolicySchema, NameSiloAccountGuard};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox;
foreach ([10, 11, 12] as $id) { Server::forceCreate(['id' => $id, 'name' => 'Local registrar', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]); }
$before = count($wire->calls);
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-one']));
same($before, count($wire->calls), 'Local enrollment requires staff review before authentication');
$firstReview = $review(10);
$localBinding = $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-one'], $firstReview);
same([[WireEnvironment::Live, WireContext::Interactive, 'getAccountBalance', [], 10000]], $wire->calls, 'Enrollment only authenticates through one bounded balance read');
foreach ([Environment::Live, Environment::Test] as $bootEnvironment) {
    config(['domains.namesilo_account.environment' => $bootEnvironment->value]);
    $bootRegistry = new RegistrarRegistry;
    LocalAccountEnrollment::bootstrap($bootRegistry, $wire);
    $before = count($wire->calls); $authenticated = false;
    try { $bootRegistry->authenticate('namesilo', $bootEnvironment, ['api_key' => 'placeholder-bootstrap-key']); $authenticated = true; }
    catch (DomainException) { /* Report missing bootstrap transport/composition through the assertion below. */ }
    expect($authenticated, 'The bootstrap-built authenticator must authenticate through the injected transport');
    same([[$bootEnvironment === Environment::Live ? WireEnvironment::Live : WireEnvironment::Sandbox,
        WireContext::Interactive, 'getAccountBalance', [], 10000]], array_slice($wire->calls, $before), 'Bootstrap authenticates once in the configured environment with a bounded balance read');
}
config(['domains.namesilo_account.environment' => 'live']);
$a = Account::where('server_id', 10)->sole(); $identity = $a->stable_identity;
expect(str_starts_with($identity, LocalAccountEnrollment::PREFIX), 'Local lineage is explicit, random and separate from provider identity');
expect(!str_contains($identity, 'placeholder-key'), 'Credentials never become local identity');
same([false, null], [$a->processing_enabled, $a->live_site], 'Authentication grants neither processing nor a live site');
$reject(fn () => $local->reader(10));
$local->confirmLiveSite(10, 'https://live.example.com');
$before = count($wire->calls);
expect($local->configurationAvailable(10), 'The original local composition accepts its saved account');
expect(!$bindings->configurationAvailable(10), 'A registry without local enrollment must refuse the saved local account');
same('configuration_unavailable', $bindings->configurationStatus(10), 'Readiness reports the missing local composition safely');
$reject(fn () => $bindings->reader(10));
same($before, count($wire->calls), 'Composition refusal performs no authentication');
$revision = $local->credentialRevision($localBinding);
$local->requireCredentialRevision($localBinding, $revision);
// Real encrypted settings and a grammar that records SQLite's omitted locks are needed here.
$conn = DB::connection(); $grammar = $conn->getQueryGrammar();
$strict = new class($conn) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public array $locks = [];
    protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
    {
        $this->locks[] = [$query->from,$query->wheres??[]]; return '';
    }
};
$checkRevisions = static function () use ($local, $localBinding, $revision): void {
    same($revision, $local->holdingCredentialRevision($localBinding), 'Holding checks retain the local credential revision');
    same($revision, $local->offerCredentialRevision($localBinding), 'Offer checks retain the local credential revision');
    $local->requireOfferReadiness($localBinding, Server::findOrFail(10), Account::where('server_id', 10)->sole());
};
// Each settings lock must name exactly one key with a conjunctive equality predicate.
$exactKeys=static function(array $locks):array {
    $keys=[];
    foreach($locks as [$table,$wheres]){
        if($table!=='settings'){continue;}
        $predicates=array_values(array_filter($wheres,static fn($where)=>($where['column']??null)==='key'));
        if(count($predicates)!==1){throw new LogicException('One settings key required.');}
        $where=$predicates[0];
        if(($where['type']??null)!=='Basic'||($where['operator']??null)!=='='||($where['boolean']??null)!=='and'||!is_string($where['value']??null)){
            throw new LogicException('Exact settings key required.');
        }
        $keys[]=$where['value'];
    }
    return $keys;
};
$predicate=static fn(string $key)=>['type'=>'Basic','column'=>'key','operator'=>'=','boolean'=>'and','value'=>$key];
$badLocks=[
    [['settings',[]],['settings',[$predicate('domains_credentials'),$predicate('domains_verified_credentials')]],['settings',[$predicate(LocalAccountEnrollment::KEY)]]],
    [['settings',[[...$predicate('domains_credentials'),'operator'=>'>=']]]],
    [['settings',[[...$predicate('domains_credentials'),'boolean'=>'or']]]],
    [['settings',[[...$predicate('domains_credentials'),'type'=>'In']]]],
];
foreach($badLocks as $locks){
    $refused=false;try{$exactKeys($locks);}catch(LogicException){$refused=true;}
    expect($refused,'Broad or non-equality settings predicates cannot satisfy the lock assertion');
}
same(['domains_credentials'],$exactKeys([['settings',[$predicate('domains_credentials')]]]),'A single exact key passes the assertion');
$conn->setQueryGrammar($strict);
try {
    $checkRevisions();
    same([], $strict->locks, 'Read-only credential checks never attempt a row lock');
    foreach([
        'holding'=>static fn()=>same($revision,$local->holdingCredentialRevision($localBinding),'Holding revision under locks'),
        'offer'=>static fn()=>same($revision,$local->offerCredentialRevision($localBinding),'Offer revision under locks'),
        'readiness'=>static fn()=>$local->requireOfferReadiness($localBinding,Server::findOrFail(10),Account::where('server_id',10)->sole()),
    ] as $name=>$check){
        $strict->locks=[];
        DB::transaction($check);
        $settings=array_values(array_filter($strict->locks,static fn($lock)=>$lock[0]==='settings'));
        $counts=array_count_values($exactKeys($settings));ksort($counts);
        $expected=['domains_credentials'=>1,'domains_verified_credentials'=>1,LocalAccountEnrollment::KEY=>$name==='readiness'?1:2];ksort($expected);
        same($expected,$counts,$name.' locks every credential setting with the required enrollment reread');
        same(array_sum($expected),count($settings),$name.' has no broad or unaccounted settings locks');
    }
} finally { $conn->setQueryGrammar($grammar); }
same($before, count($wire->calls), 'Credential checks never contact the registrar');
foreach (Server::findOrFail(10)->settings()->get() as $setting) {
    expect($setting->encrypted, 'Enrollment secrets, revision and audit are encrypted on first save');
    expect(!str_contains($setting->getRawOriginal('value'), 'placeholder-key-one'), 'Enrollment stores no plaintext credentials');
}
$facts = json_decode(Server::findOrFail(10)->settings()->where('key', LocalAccountEnrollment::KEY)->sole()->value, true, 8, JSON_THROW_ON_ERROR);
same(false, $facts['provider_confirmed_account'], 'Authentication makes no provider identity assertion');
$guardClock = new \Paymenter\Extensions\Servers\Domains\Data\SystemClock;
$guardContext = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($localBinding,
    \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Interactive, $guardClock->now()->modify('+10 seconds'),
    new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1), $guardClock);
NameSiloAccountGuard::check($localBinding, $guardContext, static fn () => throw new RuntimeException('Default contact must not be read for local enrollment'));
same(1, $guardContext->budget->remaining(), 'Local Live account guard needs no default email acceptance');
$local->setProcessing(10, true, syntheticActivation());
$rotated = $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-two'], $review(10));
same($localBinding->fingerprint, $rotated->fingerprint, 'Key rotation retains local budget and holding lineage');
same([$identity, 'https://live.example.com', false], [$a->fresh()->stable_identity, $a->fresh()->live_site, $a->fresh()->processing_enabled], 'Rotation preserves identity/site and pauses processing');
$reject(fn () => $local->requireCredentialRevision($localBinding, $revision));
foreach (['same-key' => ['placeholder-key-two'], 'A-B-A' => ['placeholder-key-one', 'placeholder-key-two']] as $scenario => $keys) {
    $holdingRevision = $local->holdingCredentialRevision($localBinding);
    $previousRevision = $local->credentialRevision($localBinding);
    foreach ($keys as $key) { $replacement = $local->configure(10, 'namesilo', Environment::Live, ['api_key' => $key], $review(10)); }
    expect($previousRevision !== $local->credentialRevision($localBinding), 'Reviewed replacement creates a new persisted revision');
    same($localBinding->fingerprint, $replacement->fingerprint, 'Same-key replacement retains holding identity');
    DB::transaction(static function () use ($local, $localBinding, $holdingRevision, $scenario): void {
        expect(!hash_equals($holdingRevision, $local->holdingCredentialRevision($localBinding)), $scenario.' replacement must refuse the captured holding revision despite identical final key bytes');
        same($local->credentialRevision($localBinding), $local->holdingCredentialRevision($localBinding), 'Local holding fence retains the persisted credential revision');
    });
}
$secondRevision = $local->credentialRevision($localBinding);
expect($secondRevision !== $revision, 'Rotation replaces the credential revision');
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-one'], $firstReview));
$before = count($wire->calls);
$reject(fn () => $local->configure(10, 'namesilo', Environment::Test, ['api_key' => 'placeholder-key-three'], $review(10)));
same($before, count($wire->calls), 'Environment repurpose refuses before authentication');
$reject(fn () => $local->configure(11, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-two'], $review(11)));
same($before, count($wire->calls), 'Duplicate key cannot create a new local budget lineage');
$wire->during = static function (): void { Server::findOrFail(10)->settings()->create(['key' => 'domains_setup_note', 'value' => 'Concurrent staff edit', 'type' => 'string']); };
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-three'], $review(10)));
same($secondRevision, $local->credentialRevision($localBinding), 'Concurrent setting edit cannot overwrite a newer reviewed snapshot');
$beforeRows = Account::count();
$wire->body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":110,"detail":"placeholder-key-rejected"}}';
ini_set('zend.exception_ignore_args', '0');
try { $local->configure(11, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-rejected'], $review(11)); expect(false, 'Rejected authentication accepted'); }
catch (DomainException $error) {
    same('Registrar refused the key.', $error->getMessage(), 'A documented key refusal keeps its safe reason');
    same(null, $error->getPrevious(), 'Authentication retains no credential-bearing cause');
    expect(!str_contains(print_r($error->getTrace(), true), 'placeholder-key-rejected'), 'Authentication failure trace hides credentials');
}
ini_set('zend.exception_ignore_args', '1');
same($beforeRows, Account::count(), 'Failed authentication creates no account');
expect(!Server::findOrFail(11)->settings()->whereIn('key', ['domains_credentials', LocalAccountEnrollment::KEY])->exists(), 'Failed authentication saves no secrets');
// A registrar that does not answer is not a refused key: staff are told to try again, nothing is saved.
foreach (['HTTP 503 page' => [503, '<html><body>Service Unavailable</body></html>', false], 'deadline' => [200, '', true]] as $case => [$status, $body, $timeout]) {
    [$wire->status, $wire->body, $wire->timeout] = [$status, $body, $timeout];
    $before = count($wire->calls); $audit = count(StaffSettingsReview::historyRows(Server::findOrFail(11)));
    try { $local->configure(11, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-unanswered'], $review(11)); expect(false, $case.' accepted'); }
    catch (DomainException $error) {
        same('Registrar key could not be confirmed.', $error->getMessage(), $case.' says the registrar did not answer');
        same(null, $error->getPrevious(), $case.' retains no cause');
    }
    same($before + 1, count($wire->calls), $case.' sends one read and no retry');
    same($beforeRows, Account::count(), $case.' creates no account');
    expect(!Server::findOrFail(11)->settings()->whereIn('key', ['domains_credentials', LocalAccountEnrollment::KEY])->exists(), $case.' saves no secrets');
    $rows = StaffSettingsReview::historyRows(Server::findOrFail(11));
    same([$audit + 2, 'authentication_refused'], [count($rows), end($rows)['phase'] ?? null], $case.' audit still records the refusal');
}
[$wire->status, $wire->timeout] = [200, false];
$malformedKey = "placeholder-key-\xFF";
$malformedError = null;
$before = count($wire->calls);
$previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
try { $local->configure(11, 'namesilo', Environment::Live, ['api_key' => $malformedKey], $review(11)); }
catch (Throwable $error) { $malformedError = $error; }
finally { ini_set('zend.exception_ignore_args', $previousIgnoreArgs); }
expect($malformedError !== null, 'Malformed credential is refused');
for ($error = $malformedError; $error !== null; $error = $error->getPrevious()) {
    expect(!str_contains($error->getMessage(), $malformedKey), 'Malformed credential is absent from exception messages');
    expect(!str_contains(print_r($error->getTrace(), true), $malformedKey), 'Malformed credential is absent from exception trace arguments');
}
same(InvalidArgumentException::class, $malformedError::class, 'Malformed credential uses the safe validation exception');
same('Registrar credentials invalid.', $malformedError->getMessage(), 'Malformed credential uses the existing safe validation error');
same(null, $malformedError->getPrevious(), 'Malformed credential retains no original exception');
same($before, count($wire->calls), 'Malformed credential is rejected before authentication');
$wire->body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"synthetic","balance":"0.00"}}';
$other = $local->configure(12, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-other'], $review(12));
expect(!$other->matches($localBinding), 'Independent local accounts have distinct random lineage');
config(['app.url' => 'https://copied.example.com']);
$before = count($wire->calls);
$reject(fn () => $local->configure(10, 'namesilo', Environment::Live, ['api_key' => 'placeholder-key-three'], $review(10)));
same($before, count($wire->calls), 'Copied site cannot rotate or recover the original live binding');
$reject(fn () => $local->requireCredentialRevision($localBinding, $secondRevision));
config(['app.url' => 'https://live.example.com']);
$credentialSetting = Server::findOrFail(10)->settings()->where('key', 'domains_credentials')->sole();
$credentialSetting->value = '{"api_key":"placeholder-key-tampered"}'; $credentialSetting->save();
expect(!$local->configurationAvailable(10), 'Unreviewed credential changes cannot authenticate the binding');
$reject(fn () => $local->requireCredentialRevision($localBinding, $secondRevision));
foreach (StaffSettingsReview::historyRows(Server::findOrFail(10)) as $entry) {
    expect(!str_contains(json_encode($entry, JSON_THROW_ON_ERROR), 'placeholder-key'), 'Audit contains no key or raw balance response');
}
echo "binding-local-enrollment-test.php passed\n";$nativeSuccessful=true;
