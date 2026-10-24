<?php
// Scenarios adapted from Enom 444f31c tests/Enom/registration-setup.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\{CustomProperty, Invoice, InvoiceTransaction, Role, User};
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Models\{ContactProfile, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactDetails, ContactProfiles, ContactProfileSchema,
    ContactRecovery, ContactSetup, ContactValidation, InvoiceBinding, PublicationEligibility, RegistryInputs};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$fixtureEnding = 'test';
extract(commerceFixture(true, ending: $fixtureEnding)); ContactProfileSchema::install(); ContactProfileSchema::verify();
$migration = require dirname(__DIR__).'/Domains/database/migrations/2026_10_18_000003_create_domains_contact_profiles.php';
$migration->down(); ContactProfileSchema::verify();
$clock = new FakeClock($now); $admission = new CartAdmission($native, $sellers, new CheckoutEligibility($bindings, $clock), $clock);
$admission->boot(); (new InvoiceBinding($admission, $native, $prices, $clock))->boot();
$user = commerceUser(); $user->update(['email' => 'contact@example.com']); $other = commerceUser();
$staff = commerceUser(); $role = Role::create(['name' => 'Domain manager', 'permissions' => ['admin.domains.manage']]); $staff->update(['role_id' => $role->id]);
tldPanel();$panel = Filament\Facades\Filament::getCurrentPanel();
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn () => App\Admin\Resources\CustomPropertyResource::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
DB::beginTransaction();
$setupStaff = staffUser(['admin.domains.view']);
CustomProperty::query()->delete();
$define = static fn (string $key) => CustomProperty::create(['key' => $key, 'name' => $key, 'model' => User::class,
    'type' => 'string', 'non_editable' => false, 'required' => true, 'show_on_invoice' => false]);
$checkProperties = static function (array $missing) use ($setupStaff): void {
    $before = DB::table('custom_properties')->orderBy('id')->get()->toJson();
    for ($i = 0; $i < 2; $i++) {
        same($missing, ContactDetails::missingRequiredProperties(), 'Exact missing user property definitions');
        $page = Livewire::actingAs($setupStaff)->test(RegistrarSetup::class)->assertSuccessful();
        if ($missing) {
            $page->assertSee("Customers can't enter ".implode(', ', $missing).'. Add them as custom properties.')
                ->assertSee('Open custom properties')->assertSeeHtml(e(App\Admin\Resources\CustomPropertyResource::getUrl('index')));
        } else {
            $page->assertDontSee("Customers can't enter")->assertDontSee('Open custom properties');
        }
        same($before, DB::table('custom_properties')->orderBy('id')->get()->toJson(), 'Setup check never changes definitions');
        unset($page); gc_collect_cycles();
    }
};
$checkProperties(['address', 'city', 'state', 'zip', 'country', 'phone']);
foreach (['address', 'city', 'state', 'zip', 'country', 'phone'] as $key) { $define($key); }
$checkProperties([]); // Optional company_name and address2 definitions are not required.
CustomProperty::where('key', 'city')->update(['model' => App\Models\Service::class]);
$checkProperties(['city']); // Another model's definition cannot satisfy a user contact key.
CustomProperty::where('key', 'city')->update(['model' => User::class]);
$checkProperties([]);
CustomProperty::query()->update(['model' => App\Models\Service::class]);
$checkProperties(['address', 'city', 'state', 'zip', 'country', 'phone']);
DB::rollBack();
Illuminate\Support\Facades\Auth::logout();
function contactProperty(User $user, string $key, string $value): void
{
    $user->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $value]);
}
foreach (['company_name' => 'Synthetic Organization', 'address' => '1 Main Street', 'address2' => 'Suite 2', 'city' => 'Seattle',
    'state' => 'WA', 'zip' => '98101', 'country' => 'United States', 'phone' => '(206) 555-0100'] as $key => $value) { contactProperty($user, $key, $value); }
$contacts = ContactDetails::collect($user);
$enom = ContactDetails::validate('enom', $contacts); $ns = ContactDetails::validate('namesilo', $contacts);
same(ContactDetails::ROLES, array_keys($enom), 'Four explicit Enom roles');
same('US', $enom['registrant']['country'], 'Country label normalized');
same('+1.2065550100', $enom['registrant']['phone'], 'Frozen Enom phone format');
same('2065550100', $ns['registrant']['phone'], 'NameSilo national phone omits prefix');
same($ns, ContactDetails::validate('namesilo', $ns), 'Validation is idempotent');
function contactReject(callable $call): void
{
    try { commerceReject($call); }
    catch (RuntimeException $error) { throw new RuntimeException('Expected refusal at contact test line '.debug_backtrace()[0]['line'], 0, $error); }
}
$contactError = static function (array $input, string $field, string $registrar = 'enom'): void {
    try { ContactDetails::validate($registrar, $input); expect(false, 'Expected contact field refusal'); }
    catch (ContactValidation $error) {
        expect(in_array($field, $error->fields, true), 'Refusal identifies exact field');
        expect(!str_contains($error->getMessage(), 'private-value'), 'Safe contact message');
    }
};
foreach (ContactDetails::ROLES as $r) {
    foreach (['first_name', 'last_name', 'address1', 'city', 'postal_code', 'country', 'email_address', 'phone', 'state_province'] as $field) {
        $bad = $enom; $bad[$r][$field] = ''; $contactError($bad, 'contacts.'.$r.'.'.$field);
    }
    foreach (['email_address' => 'private-value', 'phone' => 'private-value', 'address1' => "private-value\n", 'city' => str_repeat('x', 256)] as $field => $value) {
        $bad = $enom; $bad[$r][$field] = $value; $contactError($bad, 'contacts.'.$r.'.'.$field);
    }
    $bad = $enom; unset($bad[$r]); $contactError($bad, 'contacts.'.$r.'.first_name');
}
$bad = $enom; $bad['admin']['first_name'] = str_repeat('x', 33);
ContactDetails::validate('enom', $bad); $contactError($bad, 'contacts.admin.first_name', 'namesilo');
$bad = $enom; $bad['registrant']['state_province'] = 'Washington';
ContactDetails::validate('enom', $bad); $contactError($bad, 'contacts.registrant.state_province', 'namesilo');
foreach (['US', 'CA', 'AU'] as $country) {
    $bad = $enom; $bad['registrant']['country'] = $country; $bad['registrant']['state_province'] = '';
    $contactError($bad, 'contacts.registrant.state_province');
}
contactProperty($user, 'country', 'ca'); contactProperty($user, 'phone', '416 555 0100'); contactProperty($user, 'state', 'ON');
same('4165550100', ContactDetails::validate('namesilo', ContactDetails::collect($user))['tech']['phone'], 'Canadian phone');
contactProperty($user, 'country', 'United Kingdom'); contactProperty($user, 'phone', '+44 20 7946 0123'); contactProperty($user, 'state', '');
same('+44.2079460123', ContactDetails::validate('enom', ContactDetails::collect($user))['tech']['phone'], 'International phone without required state');
$contactError(ContactDetails::collect($user), 'contacts.registrant.state_province', 'namesilo');
expect(ContactSetup::capture($user, $tlds[1])['setup_required'], 'NameSilo GB state missing keeps setup incomplete');
expect(!ContactSetup::capture($user, $tlds[0])['setup_required'], 'Enom GB blank state remains valid');
$countries = config('app.countries'); config(['app.countries' => ['AA' => 'Duplicate', 'BB' => 'Duplicate']]);
contactProperty($user, 'country', 'Duplicate'); same('', ContactDetails::collect($user)['admin']['country'], 'Ambiguous country never guessed');
config(['app.countries' => $countries]); contactProperty($user, 'country', 'US'); contactProperty($user, 'state', 'WA'); contactProperty($user, 'phone', "\t2065550100");
$contactError(ContactDetails::collect($user), 'contacts.registrant.phone');
contactProperty($user, 'phone', '(206) 555-0100');
// Publication remains off for registry endings; only frozen NameSilo .us nexus has preparation validation.
foreach (['enom', 'namesilo'] as $registrar) {
    foreach (['us', 'ca', 'eu', 'co.us'] as $ending) {
        expect(!RegistryInputs::supported($registrar, $ending, []), 'Unaccepted registry gate');
        $values=['usnc'=>'C11','usap'=>'P3'];
        if($registrar==='namesilo' && $ending==='us'){
            same($values,RegistryInputs::validate($registrar,$ending,[],$values),'Explicit frozen nexus preparation only');
            $unpublished=clone $tlds[1];$unpublished->tld='us';
            same(false,ContactSetup::capture($user,$unpublished)['registry_supported'],'Native capture still grants no .us acceptance');
        }else{contactReject(static fn () => RegistryInputs::validate($registrar,$ending,[],$values));}
    }
    foreach ([null, [['name' => 'us_nexus', 'required' => true]]] as $schema) { contactReject(static fn () => RegistryInputs::validate($registrar, 'com', $schema, [])); }
    contactReject(static fn () => RegistryInputs::validate($registrar, 'com', [], ['command' => 'override']));
}
DB::transaction(static function () use ($tlds): void {
    $tld = $tlds[0]->fresh(); $tld->registry_fields = [['name' => 'us_nexus']]; $tld->save();
    contactReject(static fn () => PublicationEligibility::require($tld, 'register', 1));
    $tld->registry_fields = []; $tld->save();
});
// Native checkout binds the real snapshot, no customer contact/EPP/profile IDs enter the cart.
$frozenName = reservedFixtureName('frozen-contact.test');
$product = $native->product($tlds[0], 'register'); $cart = commerceCart($user); commerceAdd($cart, $product, $frozenName); commerceCheckout($user, $cart);
$op = Domain::where('domain', $frozenName)->sole()->operations()->sole();
expect(!$op->payload['setup_required'], 'Valid contact setup captured at binding'); ContactSetup::validate($op);
ContactSetup::requireReady($op);
foreach ([['setup_required' => true], ['registry_supported' => false], ['contacts' => []]] as $change) {
    $candidate = clone $op; $candidate->payload = array_replace($op->payload, $change);
    contactReject(static fn () => ContactSetup::requireReady($candidate));
}
$frozen = $op->payload; contactProperty($user, 'address', '2 Updated Street');
same($frozen, $op->fresh()->payload, 'Account edit never updates a queued/unpaid snapshot');
contactReject(static fn () => $op->update(['payload' => array_replace($frozen, ['contacts' => []])]));
$op->refresh(); // A refused Eloquent save leaves the caller's in-memory attributes dirty.
$raw = DB::table('domains_operations')->where('id', $op->id)->first();
expect(!str_contains($raw->payload, 'Main Street') && !isset($op->toArray()['payload']), 'Encrypted and hidden snapshots');
expect(!$op->domain->service->properties()->where('key', 'contacts')->exists(), 'No native contact property');
require __DIR__.'/Support/frozen-contact-lifecycle.php';
// Invalid paid setup is held; invalid unpaid setup cannot gain a paid hold.
contactProperty($user, 'phone', 'private-value');
$heldName = reservedFixtureName('held-contact.test');
$cart = commerceCart($user); commerceAdd($cart, $product, $heldName); commerceCheckout($user, $cart);
$held = Domain::where('domain', $heldName)->sole()->operations()->sole(); $heldPayload = $held->payload;
contactReject(static fn () => ContactRecovery::hold($held->id)); same('awaiting_payment', $held->fresh()->state, 'Unpaid cannot recover');
DB::table('invoices')->where('id', $held->invoice_id)->update(['status' => 'paid']);
contactReject(static fn () => ContactRecovery::hold($held->id)); // native paid flag alone is insufficient
InvoiceTransaction::create(['invoice_id' => $held->invoice_id, 'amount' => $held->amount, 'fee' => 0, 'status' => 'succeeded']);
$beforeMoney = serialize([DB::table('invoices')->get(), DB::table('invoice_items')->get(), DB::table('invoice_transactions')->get()]);
ContactRecovery::hold($held->id); $rawHeld = $held->fresh()->getAttributes(); ContactRecovery::hold($held->id);
same('contact_details_required', $held->fresh()->safe_code, 'Supported registry with invalid contacts remains a contact hold');
same($rawHeld, $held->fresh()->getAttributes(), 'Repeated hold is read-only'); same($heldPayload, $held->fresh()->payload, 'Hold freezes invalid original contacts');
contactReject(static fn () => ContactSetup::requireReady($held->fresh()));
DB::table('domains_operations')->where('id', $held->id)->update(['safe_code' => 'funding_review']);
$foreignHold = $held->fresh()->getAttributes(); contactReject(static fn () => ContactRecovery::hold($held->id));
contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Cannot release another hold'));
same($foreignHold, $held->fresh()->getAttributes(), 'Foreign hold bytes unchanged');
DB::table('domains_operations')->where('id', $held->id)->update($rawHeld);
contactReject(static fn () => ContactRecovery::replace($other, $held->id, 'Corrected details'));
contactReject(static fn () => ContactRecovery::replace($user, $held->id, 'Corrected details'));
same($heldPayload, $held->fresh()->payload, 'Invalid correction rolls back');
contactProperty($user, 'phone', '(206) 555-0100');
// Rules/country changes can leave stale flags despite valid frozen data: audited recovery must work.
foreach ([['setup_required' => true, 'registry_supported' => true], ['setup_required' => false, 'registry_supported' => false]] as $flags) {
    ContactSetup::recoveryWrite(static fn () => $held->fresh()->update(['state' => 'queued', 'safe_code' => null,
        'payload' => array_replace($heldPayload, ['contacts' => $enom], $flags)]));
    ContactRecovery::hold($held->id); same('contact_details_required', $held->fresh()->safe_code, 'Stale flags become recoverable hold');
    ContactSetup::requireReady(ContactRecovery::replace($staff, $held->id, 'Revalidated owner contact setup'));
    DB::table('domains_operations')->where('id', $held->id)->update($rawHeld);
}
// A privileged fixture simulates retained unsupported registry setup; recovery never edits it away.
ContactSetup::recoveryWrite(static fn () => $held->fresh()->update(['payload' => array_replace($heldPayload,
    ['contacts' => $enom, 'registry_schema' => [['name' => 'unaccepted']], 'registry_supported' => false])]));
ContactRecovery::hold($held->id); same('registry_details_unsupported', $held->fresh()->safe_code, 'Unsupported registry stays held');
contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Cannot invent registry support'));
DB::table('domains_operations')->where('id', $held->id)->update($rawHeld);
// Positive setup flags never bypass invalid frozen contacts.
ContactSetup::recoveryWrite(static fn () => $held->fresh()->update(['state' => 'queued', 'safe_code' => null,
    'payload' => array_replace($heldPayload, ['setup_required' => false, 'registry_supported' => true])]));
ContactRecovery::hold($held->id); same('contact_details_required', $held->fresh()->safe_code, 'Invalid frozen contacts override positive setup flags');
DB::table('domains_operations')->where('id', $held->id)->update($rawHeld);
// Both defects must classify as unsupported registry, even from an existing contact hold.
$bothPayload = array_replace($heldPayload, ['registry_schema' => [['name' => 'unaccepted']], 'registry_supported' => false]);
ContactSetup::recoveryWrite(static fn () => $held->fresh()->update(['payload' => $bothPayload]));
ContactRecovery::hold($held->id);
same('registry_details_unsupported', $held->fresh()->safe_code, 'Unsupported registry takes precedence over invalid frozen contacts');
same($bothPayload, $held->fresh()->payload, 'Registry classification preserves both frozen defects');
$bothHeld = $held->fresh()->getAttributes(); ContactRecovery::hold($held->id);
same($bothHeld, $held->fresh()->getAttributes(), 'Repeated unsupported registry hold is read-only');
contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Contacts cannot repair unsupported registry'));
same($bothHeld, $held->fresh()->getAttributes(), 'Refused contact recovery preserves the registry hold');
DB::table('domains_operations')->where('id', $held->id)->update($rawHeld);
$peer = Operation::create(['domain_id' => $held->domain_id, 'service_id' => $held->service_id, 'server_id' => $held->server_id,
    'registrar_key' => $held->registrar_key, 'account_fingerprint' => $held->account_fingerprint, 'authority_generation' => 1,
    'action' => 'contacts', 'term' => 1, 'currency' => 'USD', 'amount' => '0.00', 'funding_basis' => 'non_billable_management',
    'state' => 'uncertain', 'operation_key' => 'contact-uncertain-peer']);
contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Cannot ignore unresolved peer'));
DB::table('domains_operations')->where('id', $peer->id)->delete(); // fixture-only removal, not a ledger API
foreach ([['attempt' => ['execution' => null]], ['attempt' => ['seal' => 'unknown']], ['attempt' => ['seal' => 'sealed']],
    ['attempt' => ['send_state' => 'sent', 'seal' => 'absent']], ['attempt' => ['provider_reference' => null]],
    ['attempt' => ['charge' => null]], ['attempt' => ['order_candidates' => []]], ['cost' => $held->fresh()->cost + ['execution' => null]],
    ['attempt' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => null]],
    ['attempt' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'rejection_basis' => null]],
    ['policy' => $held->fresh()->policy + ['dispatch_attempted_at' => null]], ['provider_order_id' => '123'], ['started_at' => now()],
    ['completed_at' => now()], ['lease_expires_at' => now()], ['funding' => ['refund' => null]], ['state' => 'uncertain'],
    ['request_fingerprint' => 'claimed'], ['safe_code' => 'different_hold'],
    ['policy' => $held->fresh()->policy + ['contact_readmissions' => array_fill(0, 10, [])]]] as $changes) {
    DB::beginTransaction(); ContactSetup::recoveryWrite(static fn () => $held->fresh()->update($changes)); DB::commit();
    contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Reviewed corrected details'));
    // Test-only restore of exact pre-case bytes; no runtime evidence-rewriting API.
    DB::table('domains_operations')->where('id', $held->id)->update($rawHeld);
}
foreach (['', 'bad', "bad\nreason", str_repeat('x', 501), str_repeat('é',4), "valid\xff"] as $reason) {
    contactReject(static fn () => ContactRecovery::replace($staff, $held->id, $reason));
}
// Native owner/route/lifecycle, generation, reservation and signed-net funding remain authoritative.
$serviceId = $held->service_id; $productId = $held->domain->service->product_id;
foreach ([['services', $serviceId, ['user_id' => $other->id]], ['services', $serviceId, ['status' => 'cancelled']],
    ['services', $serviceId, ['currency_code' => 'EUR']], ['products', $productId, ['server_id' => $tlds[1]->server_id]],
    ['domains_domains', $held->domain_id, ['authority_generation' => 2]],
    ['domains_domains', $held->domain_id, ['reservation_name' => null]]] as [$table, $id, $change]) {
    $before = (array) DB::table($table)->where('id', $id)->first(); DB::table($table)->where('id', $id)->update($change);
    contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Reviewed changed authority'));
    DB::table($table)->where('id', $id)->update($before);
}
$reversal = InvoiceTransaction::create(['invoice_id' => $held->invoice_id, 'amount' => '-0.01', 'fee' => 0, 'status' => 'succeeded']);
contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Funding is no longer complete'));
DB::table('invoice_transactions')->where('id', $reversal->id)->delete();
$recovered = ContactRecovery::replace($staff, $held->id, str_repeat('🦦',500));
same('queued', $recovered->state, 'Same paid never-sent obligation readmitted, no new operation');
ContactSetup::requireReady($recovered);
same('2 Updated Street', $recovered->payload['contacts']['tech']['address1'], 'Staff uses OWNER account details');
same($heldPayload['registry_schema'], $recovered->payload['registry_schema'], 'Registry snapshot preserved');
same((int) $staff->id, $recovered->policy['contact_readmissions'][0]['actor_id'], 'Recovery actor audited');
same($beforeMoney, serialize([DB::table('invoices')->get(), DB::table('invoice_items')->get(), DB::table('invoice_transactions')->get()]), 'Recovery changes no financial rows');
contactReject(static fn () => ContactRecovery::replace($staff, $held->id, 'Repeated recovery refused'));
// Provider profiles: shared across one owner's roles, never across owners/accounts; no in-place mutation.
$account = Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[1]->server_id)->sole();
$binding = Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account);
$p = ContactProfiles::recordCreated($binding, $user->id, '123', $ns['registrant'], false);
same($p->id, ContactProfiles::recordCreated($binding, $user->id, '123', $ns['registrant'], false)->id, 'Idempotent profile evidence');
contactReject(static fn () => ContactProfiles::recordCreated($binding, $other->id, '123', $ns['registrant'], false));
contactReject(static fn () => ContactProfiles::recordCreated($binding, $user->id, '124', $ns['registrant'], true));
contactReject(static fn () => ContactProfiles::recordCreated($binding, $user->id, '123', ['first_name' => 'Changed'] + $ns['registrant'], false));
contactReject(static fn () => $p->update(['user_id' => $other->id])); contactReject(static fn () => $p->delete());
$roles = array_fill_keys(ContactDetails::ROLES, (int) $p->id);
same(['registrant' => '123', 'administrative' => '123', 'technical' => '123', 'billing' => '123'], ContactProfiles::roles($binding, $user->id, $roles), 'NameSilo four-role wire names');
contactReject(static fn () => ContactProfiles::roles($binding, $other->id, $roles));
$wrong = new AccountBinding('namesilo', $binding->serverId + 100, Environment::Test, 'other-account');
contactReject(static fn () => ContactProfiles::roles($wrong, $user->id, $roles));
$profileRaw = DB::table('domains_contact_profiles')->where('id', $p->id)->first();
expect(!str_contains($profileRaw->details, 'Main Street') && !isset($p->toArray()['details']), 'Profile IDs/details encrypted and hidden');
// APP_KEY rotates encryption only; the retained index key keeps provider uniqueness stable.
$originalEncrypter = app('encrypter'); $originalKey = config('app.key');
$rotatedKey = random_bytes(32);
$rotated = new Illuminate\Encryption\Encrypter($rotatedKey, config('app.cipher'));
$rotated->previousKeys([$originalEncrypter->getKey()]);
app()->instance('encrypter', $rotated); Illuminate\Database\Eloquent\Model::encryptUsing($rotated);
config(['app.key' => 'base64:'.base64_encode($rotatedKey)]);
try {
    same($p->id, ContactProfiles::recordCreated($binding, $user->id, '123', $ns['registrant'], false)->id, 'Rotated key preserves idempotency');
    contactReject(static fn () => ContactProfiles::recordCreated($binding, $other->id, '123', $ns['registrant'], false));
    $next = ContactProfiles::recordCreated($binding, $user->id, '125', $ns['registrant'], false);
    expect($next->id !== $p->id, 'Rotated key accepts genuinely new evidence');
    same($p->fresh()->details['index_key'], $next->details['index_key'], 'Same retained encrypted index key');
    same('123', ContactProfiles::roles($binding, $user->id, $roles)['registrant'], 'Old profile resolves through previous encryption key');
} finally {
    app()->instance('encrypter', $originalEncrypter); Illuminate\Database\Eloquent\Model::encryptUsing($originalEncrypter);
    config(['app.key' => $originalKey]);
}
$migration->down(); ContactProfileSchema::install(); same(2, ContactProfile::count(), 'Reinstall retains owner mappings');
// Non-billable contacts can close only as audited never-sent terminal disposition, never refund/replay.
$management = static function () use ($op): Operation {
    return Operation::create(['domain_id' => $op->domain_id, 'service_id' => $op->service_id, 'server_id' => $op->server_id,
        'registrar_key' => $op->registrar_key, 'account_fingerprint' => $op->account_fingerprint, 'authority_generation' => $op->authority_generation,
        'action' => 'contacts', 'term' => 1, 'currency' => 'USD', 'amount' => '0.00', 'funding_basis' => 'non_billable_management',
        'state' => 'held', 'operation_key' => 'contact-close-'.Operation::count(), 'payload' => ['contacts' => $op->payload['contacts']]]);
};
$manage = $management(); contactReject(static fn () => ContactRecovery::closeUnsent($user, $manage->id, 'Stop this change'));
contactReject(static fn () => $manage->update(['payload' => ['contacts' => []]]));
foreach ([['services', $op->service_id, ['user_id' => $other->id]], ['services', $op->service_id, ['status' => 'cancelled']],
    ['products', $op->domain->service->product_id, ['server_id' => $tlds[1]->server_id]]] as [$table, $id, $change]) {
    $before = (array) DB::table($table)->where('id', $id)->first(); DB::table($table)->where('id', $id)->update($change);
    contactReject(static fn () => ContactRecovery::closeUnsent($staff, $manage->id, 'Authority changed before closure'));
    same('held', $manage->fresh()->state, 'Changed authority cannot close contacts');
    DB::table($table)->where('id', $id)->update($before);
}
$closed = ContactRecovery::closeUnsent($staff, $manage->id, 'Abandon the unsent contact change');
same('rejected', $closed->state, 'Terminal disposition retained'); same('non_refundable', $closed->policy['contact_disposition']['financial'], 'Contacts never create credit');
same('definitely_unsent', $closed->attempt['rejection_basis'], 'Explicit terminal proof');
contactReject(static fn () => ContactRecovery::closeUnsent($staff, $manage->id, 'Duplicate disposition'));
same($frozenName, $op->domain->fresh()->reservation_name, 'Management disposition never releases holding');
// Synthetic 301/302 remain purchases with discrepancy; even recorded-null execution blocks recovery.
$parser = new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ResponseParser;
$fallbackName = reservedFixtureName('example.com');
$request = Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::register($fallbackName, 1, false, '123', ['ns1.example.net', 'ns2.example.net']);
foreach ([301, 302] as $code) {
    $body = json_encode(['request' => ['operation' => 'registerDomain'], 'reply' => ['code' => $code, 'detail' => 'private-value', 'domain' => $fallbackName, 'order_amount' => '9.25']]);
    $reply = $parser->parse($body, $request, 'secret');
    same(Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Fallback, $reply->outcome, 'Fallback is a purchased discrepancy');
    $sent = $management(); $sent->update(['attempt' => ['execution' => ['code' => $code]]]);
    contactReject(static fn () => ContactRecovery::closeUnsent($staff, $sent->id, 'Cannot close a fallback'));
    same('held', $sent->fresh()->state, 'Fallback cannot become unsent terminal or refund');
}
same(0, DB::transactionLevel(), 'No leaked transaction'); $nativeSuccessful = true;
echo "Contact snapshots, provider schemas, registry gates, owner profiles and audited unsent recovery passed.\n";
