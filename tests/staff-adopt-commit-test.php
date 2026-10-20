<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use Livewire\Livewire;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\AdoptDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\RefusalCode;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, AdoptionEnrollment, AdoptionReader, AdoptionRefused};
extract(adoptionCommitFixture()); staffPanel();
app()->instance(AdoptionReader::class, $reader); app()->instance(AdoptionCommit::class, $commit); app()->instance(Clock::class, $clock);
$viewOnly = staffUser([Staff::VIEW]); Livewire::actingAs($viewOnly)->test(AdoptDomain::class)->assertForbidden();
config(['domains.adoption.enabled' => false]);
$page = Livewire::actingAs($staff)->test(AdoptDomain::class)->assertSee('Adoption is not enabled yet')->assertSee('Choose a dedicated domain service.')
    ->assertSee('Still needed before adoption can be switched on')->assertSee('Switching adoption on is a site configuration change, not a staff action.');
foreach (['A0:', 'Scenario baseline', 'Provider contract', 'Before activation', 'enrollment is retained'] as $jargon) { expect(!str_contains($page->html(), $jargon), 'Staff checklist has no internal jargon '.$jargon); }
same(0, count($wire->calls), 'Wizard GET never reads registrar');
$choose = ['customer' => $owner->id, 'service' => $service->id, 'account' => $binding->serverId];
$page->callAction('choose', data: $choose)->assertHasNoActionErrors()->assertSee('example.com')->assertSee('2. Fresh readback');
same(0, count($wire->calls), 'Choosing mapping performs no wire');
$page->callAction('readback', data: ['reason' => 'Verified native dedicated service for original customer', 'do_not_renew' => true])->assertHasNoActionErrors()
    ->assertSee('Verified preview')->assertSee('No renewal')->assertActionDisabled('confirm')
    ->assertSee('Oct 20, 2027 06:14 UTC')->assertSee('Preview valid until')->assertDontSee('Expiry (UTC)')->assertDontSee('2027-10-20T06:14')
    ->assertSee('Confirmation is unavailable because adoption is switched off on this site');
$html = $page->html(); $a = Adoption::sole(); $page->assertSee($a->valid_until->setTimezone(new DateTimeZone('UTC'))->format('M j, Y H:i:s').' UTC');
foreach ([$a->request_key, $a->request_digest, 'synthetic-secret', 'account-enom', 'provider_domain_id', 'account_fingerprint'] as $secret) { expect(!str_contains($html, $secret), 'Browser excludes private authority '.$secret); }
$page->callAction('confirm', data: ['verified' => true]); same(0, Domain::count(), 'Default off UI cannot confirm');
config(['domains.adoption.enabled' => true]);
$clock->time = $clock->time->modify('+120 seconds');
$page->callAction('confirm', data: ['verified' => true])->assertSee('Confirmation not completed')->assertSee('this attempt changed and charged nothing, so read a fresh preview to try again'); same(0, Domain::count(), 'Stale POST rolls back');
$role = fn (array $p) => DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode($p)]);
$role(array_values(array_diff($permissions, [Staff::ADOPT])));
$page->callAction('readback', data: ['reason' => 'Preview without adopt permission', 'do_not_renew' => true])->assertActionDisabled('confirm')
    ->assertSee('your role can preview but not confirm adoptions');
$role(array_values(array_diff($permissions, ['admin.services.update'])));
$page->callAction('readback', data: ['reason' => 'Preview without service update permission', 'do_not_renew' => true])->assertDontSee('Confirmation is unavailable')
    ->assertSee('Simultaneous staff and customer edits have not yet been checked');
$page->callAction('confirm', data: ['verified' => true])->assertSee('your role is missing a permission adoption needs'); same(0, Domain::count(), 'Permission refusal rolls back');
$role($permissions);
// Every typed code has staff text independent of the exception's diagnostic message.
$refused = new ReflectionMethod(AdoptDomain::class, 'refused');
$pageSource = array_slice(file($refused->getFileName()), $refused->getStartLine() - 1, $refused->getEndLine() - $refused->getStartLine() + 1);
expect(!str_contains(implode('', $pageSource), 'getMessage'), 'Page never reads exception messages to select refusal text');
$diagnostic = new ReflectionProperty(Exception::class, 'message');
foreach (RefusalCode::cases() as $code) {
    [$message, $sentence] = match ($code) {
        RefusalCode::VerificationChanged => ['Verification details changed or expired. Verify again.', 'the preview expired or a detail changed'],
        RefusalCode::RequestChanged => ['Adoption request changed. Verify again.', 'the preview expired or a detail changed'],
        RefusalCode::Permission => ['You do not have permission to adopt registrations.', 'missing a permission'],
        RefusalCode::ResultReview => ['Adoption result needs staff review.', 'do not match this preview'],
        RefusalCode::IdentityReview => ['Registration identity needs staff review.', 'do not match this preview'],
        RefusalCode::HoldingReview => ['Adoption holding requires review.', 'do not match this preview'],
        RefusalCode::Disabled => ['Adoption is not enabled yet.', 'switched off on this site'],
    };
    $error = new AdoptionRefused($code); same($message, $error->getMessage(), 'Original diagnostic preserved '.$code->value);
    $text = $refused->invoke(null, $error);
    expect(str_contains($text, $sentence) && str_contains($text, 'this attempt changed and charged nothing'), 'Every refusal code has staff text '.$code->value);
    $diagnostic->setValue($error, 'private-diagnostic-changed');
    same($text, $refused->invoke(null, $error), 'Diagnostic wording cannot change staff outcome '.$code->value);
    expect(str_contains($refused->invoke(null, new DomainException($message)), 'outcome is unknown'), 'Untyped matching text never claims refusal '.$code->value);
}
foreach ([new RuntimeException('private-sink-detail'), new DomainException('private-sink-detail')] as $unknown) {
    $text = $refused->invoke(null, $unknown); expect(str_contains($text, 'outcome is unknown') && !str_contains($text, 'private-sink-detail') && !str_contains($text, 'charged nothing'), 'Unmapped refusal is uncertain and hides exception text');
}
$when = new ReflectionMethod(AdoptDomain::class, 'when');
foreach (['2027-10-20' => 'Oct 20, 2027', '2027-10-20T08:14:00+02:00' => 'Oct 20, 2027 06:14 UTC', '2027-10-20T06:14:00Z' => 'Oct 20, 2027 06:14 UTC', '' => 'Unconfirmed', 'not-a-date' => 'Unconfirmed'] as $raw => $shown) { same($shown, $when->invoke(null, $raw), 'Staff date '.$raw); }
same('Unconfirmed', $when->invoke(null, null), 'Missing staff date');
$page->callAction('readback', data: ['reason' => 'Rechecked after stale evidence', 'do_not_renew' => true])->assertHasNoActionErrors()->assertSee('Verified preview');
$calls = count($wire->calls); $money = scenarioMoney();
$page->mountAction('confirm'); expect(str_contains((string) $page->instance()->getMountedAction()->getModalDescription(), 'Expiry: Oct 20, 2027 06:14 UTC'), 'Confirm modal shows UTC expiry');
// The own-class container seam injects a postcommit enrollment failure without changing native services.
$enrollment = new class {
    public array $ids = [];
    public ?int $transactionLevel = null;
    public ?string $state = null;
    public function repair(int $id): void
    {
        $this->ids[] = $id;
        $this->transactionLevel = DB::transactionLevel();
        $this->state = Adoption::where('domain_id', $id)->sole()->state;
        throw new RuntimeException('private-enrollment-failure');
    }
};
$clockSeen = null;
app()->bind(AdoptionEnrollment::class, static function ($app, array $parameters) use (&$clockSeen, $enrollment) {
    $clockSeen = $parameters['clock']; return $enrollment;
});
$handler = new class extends \Monolog\Handler\AbstractHandler {
    public array $warnings = [];
    public function handle(\Monolog\LogRecord $record): bool
    {
        if ($record->message !== 'Adoption enrollment deferred.') { return false; }
        $this->warnings[] = $record->context;
        throw new RuntimeException('private-logger-failure');
    }
};
$logger = logger()->getLogger(); $logger->pushHandler($handler);
try {
    $page->setActionData(['verified' => true])->callMountedAction()->assertHasNoActionErrors()->assertSee('Adopted')->assertSee('Renewal off')->assertSee('Expires Oct 20, 2027')
        ->assertDontSee('Confirmation not completed')->assertDontSee('outcome is unknown')->assertDontSee('private-enrollment-failure')->assertDontSee('private-logger-failure');
} finally { $logger->popHandler(); app()->offsetUnset(AdoptionEnrollment::class); }
same([Domain::sole()->id], $enrollment->ids, 'Failing enrollment was invoked for the committed holding');
same(0, $enrollment->transactionLevel, 'Enrollment runs after the adoption transaction');
same('committed', $enrollment->state, 'Adoption is durable before enrollment fails');
same($clock, $clockSeen, 'Container enrollment retains the confirmer clock');
same([['domain_id' => Domain::sole()->id, 'exception_class' => RuntimeException::class]], $handler->warnings, 'Failed warning contains only safe identifiers');
same('committed', Adoption::findOrFail($page->get('previewId'))->state, 'Double failure retains committed adoption');
same(Domain::sole()->id, $page->get('adoptedId'), 'Page receives the committed domain despite double failure');
same(null, $page->get('refusal'), 'Double failure is the adopted outcome, never a refusal');
same(1, Domain::count(), 'Wizard uses real confirmer'); same($calls, count($wire->calls), 'Confirmation no provider call'); same($money, scenarioMoney(), 'Wizard no financial fabrication');
$page->assertActionHidden('confirm'); same(1, Domain::count(), 'Completed UI has no second confirm action');
DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW])]); $page->call('$refresh')->assertForbidden();
$nativeSuccessful = true; echo "Native adoption wizard, stale refusal, safe browser state and permission rechecks passed\n";
