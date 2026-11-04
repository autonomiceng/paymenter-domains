<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts, FactsObserved, Projection, Revision, Writer};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

// Native models and Writer-created rows are necessary: a stub cannot reproduce the retired
// nullable column beside serialized typed facts or the real account/domain lookup guards.
extract(commerceFixture());
$binding = AccountBindings::binding(Account::where('server_id', $tlds[1]->server_id)->sole());
$writer = new Writer(new Projection($native), $clock, static fn () => null, static fn () => true);
$facts = new Facts(['register'=>[1], 'renew'=>[1], 'transfer'=>[1]], [], true, false, false,
    'synthetic', $now, $now->modify('+1 day'));
$writer->apply(new FactsObserved($binding, 'net', $facts), new Revision(0, 0, 0));
$tld = Tld::where('server_id', $binding->serverId)->where('tld', 'net')->sole();
same(null, $tld->registry_fields, 'Writer discovery leaves the retired registry column empty');
$domain = Domain::create(['server_id'=>$binding->serverId, 'registrar_key'=>'namesilo',
    'account_fingerprint'=>$binding->fingerprint, 'user_id'=>900, 'service_id'=>900,
    'domain'=>'typed-contact-placeholder.net', 'sld'=>'typed-contact-placeholder', 'tld'=>'net', 'status'=>'active']);
$calls = [];
$driver = new Management($binding, static function ($origin, $command, $parameters, $timeout) use (&$calls): string {
    $calls[] = $command;
    same('getDomainInfo', $command, 'Contact preparation only reads domain ownership');
    return json_encode(['request'=>['operation'=>$command], 'reply'=>['code'=>300, 'detail'=>'synthetic',
        'created'=>'2020-01-01', 'expires'=>'2028-01-01', 'status'=>'Active',
        'locked'=>'Yes', 'private'=>'No', 'auto_renew'=>'No']]);
});
$payload = $driver->validate('contact_profile', ['role'=>'all', 'contact'=>[
    'first_name'=>'Synthetic', 'last_name'=>'Owner', 'organization_name'=>'', 'address1'=>'1 Synthetic Street',
    'address2'=>'', 'city'=>'Seattle', 'state_province'=>'WA', 'postal_code'=>'98101', 'country'=>'US',
    'email_address'=>'contact@example.com', 'phone'=>'+1.2065550100']], $domain->domain);
$request = new ManagementRequest($binding, 'typed-contact', $domain->domain, 'contact_profile', $payload, domainId:$domain->id);
$context = static fn () => new RequestContext($binding, RequestOrigin::Interactive,
    $clock->now()->modify('+25 seconds'), new RequestBudget(3), $clock);
same($payload, $driver->freeze($request->key, $domain->domain, 'contact_profile', $payload, $domain->id),
    'Current typed facts admit contact staging on a Writer-created TLD');
same([], $calls, 'Registry admission makes no provider request');
$tld->update(['registry_fields'=>[['name'=>'retired_schema']]]);
same($payload, $driver->prepare($request, $context()), 'Preparation ignores a conflicting retired schema');
same(['getDomainInfo'], $calls, 'Preparation still requires the real ownership read');

$original = (array) DB::table('domains_tlds')->where('id', $tld->id)->sole();
$rawFacts = json_decode($original['catalogue_facts'], true, flags:JSON_THROW_ON_ERROR);
$refuse = static function (array $changes) use ($tld, $driver, $domain, $payload, $request, $context, &$calls): void {
    if ($changes) { DB::table('domains_tlds')->where('id', $tld->id)->update($changes); }
    $before = $calls;
    foreach ([static fn () => $driver->freeze($request->key, $domain->domain, 'contact_profile', $payload, $domain->id),
        static fn () => $driver->prepare($request, $context())] as $call) {
        try { $call(); } catch (DomainException $error) {
            same('Registry contact schema unsupported.', $error->getMessage(), 'Current facts fail closed at both contact gates');
            continue;
        }
        throw new RuntimeException('Invalid current registry facts admitted a contact change');
    }
    same($before, $calls, 'Invalid local facts refuse before provider reads');
};
foreach ([['catalogue_converted'=>false], ['registrar_key'=>'enom'], ['account_fingerprint'=>str_repeat('f',64)],
    ['catalogue_facts'=>null],
    ...array_map(static fn ($schema) => ['catalogue_facts'=>json_encode([...$rawFacts, 'registryFields'=>$schema])],
        [null, [['name'=>'unaccepted']]])] as $changes) {
    $refuse($changes);
    DB::table('domains_tlds')->where('id', $tld->id)->update(array_intersect_key($original, $changes));
}
$clock->instant = $now->modify('+1 day');
$refuse([]);
$clock->instant = $now->modify('-1 second');
$refuse([]);
$nativeSuccessful = true;
echo "NameSilo contacts use current account-bound typed registry facts.\n";
