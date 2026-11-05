<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Reuse the installed native contact gates with a verified read-only vendor tree.
require __DIR__.'/namesilo-management-facts-test.php';
$nativeSuccessful=false;$clock->instant=$now;
$facts=fixtureNameSiloFacts($binding,'net',$facts);
\Illuminate\Support\Facades\DB::table('domains_tlds')->where('id',$tld->id)->update([
    'catalogue_facts'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::encodeFacts($facts)]);
// TEST evidence can be withdrawn or replaced without changing the account fingerprint.
$account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $binding->serverId)->sole();
$evidence = config('domains.namesilo_test.accounts');
foreach (['revision', 'withdrawn', 'schema'] as $change) {
    $changed = $evidence;
    if ($change === 'revision') { $changed[$account->stable_identity]['credential_revision'] = 'withdrawn-revision'; }
    elseif ($change === 'withdrawn') { unset($changed[$account->stable_identity]['facts']['net']); }
    else { $changed[$account->stable_identity]['facts']['net']['registry_fields'] = [['name'=>'unaccepted']]; }
    config(['domains.namesilo_test.accounts'=>$changed]);
    $refuse([]);
    config(['domains.namesilo_test.accounts'=>$evidence]);
}
$nativeSuccessful=true;echo "NameSilo TEST contact gates reject withdrawn protocol provenance.\n";
