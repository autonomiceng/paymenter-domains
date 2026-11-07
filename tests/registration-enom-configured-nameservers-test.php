<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic registrar transport only.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/site-dns-default.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\RegistrationDns;

$template = DB::transaction(fn () => WatchProfile::create(['name' => 'Registration nameservers', 'enabled' => true,
    'nameservers' => ['ns1.provider.example', 'ns2.provider.example'], 'website_endpoints' => [], 'email_endpoints' => [],
    'edited_by' => $user->id, 'reason' => 'Synthetic registration default']));
siteDnsFixture($template->id);
$op = $new(placeholderComFixtureName('configured-enom-placeholder.com'));
same(['ns1.provider.example', 'ns2.provider.example'], $op->payload['nameservers'], 'Checkout freezes the site nameservers');
DB::transaction(fn () => $template->update(['nameservers' => ['ns1.later.example', 'ns2.later.example'], 'revision' => $template->revision + 1]));
$forms = []; $registered = [];
$exchange = static function ($origin, $command, $parameters, $timeout) use (&$forms, &$registered): string {
    same(0, DB::transactionLevel(), 'Registrar calls stay outside financial locks');
    $forms[] = [$command, $parameters];
    if ($command === 'Purchase') {
        $registered = ($parameters['UseDNS'] ?? null) === 'default'
            ? ['dns1.name-services.com', 'dns2.name-services.com']
            : [$parameters['NS1'] ?? '', $parameters['NS2'] ?? ''];
        // A captured order without expiry has no immediate ModifyNS follow-up.
        return '<interface-response><Command>Purchase</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID></interface-response>';
    }
    throw new LogicException('Unconfirmed registration must not send an ancillary write.');
};
$binding = $bindingFor(0);
$makeEngine($binding, $exchange);
$result = $runRegistration($op);
same(['Purchase'], array_column($forms, 0), 'Exactly one purchase without ancillary writes');
same(['ns1.provider.example', 'ns2.provider.example'], $registered, 'The new registration uses the frozen nameservers at Purchase');
expect(!isset($forms[0][1]['UseDNS']), 'Custom nameservers never request registrar defaults');
same('No', $forms[0][1]['IgnoreNSFail'], 'Nameserver validation remains enabled');
same('unknown', $result->attempt['execution']['disposition'], 'Incomplete purchase remains unresolved until read-only proof');
$money = $nativeMoney();
$observation->name = $op->domain->domain; $observation->reference = '1234'; $observation->nameservers = $registered;
$observation->fault = 'captured-charge';
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Unique order and ownership proof completes the original purchase');
same($registered, $result->domain->fresh()->observation['nameservers']['nameservers'] ?? null, 'Readback confirms the registered nameservers');
expect(!RegistrationDns::pending($result, $result->domain->fresh()), 'Confirmed configured nameservers need no customer mismatch warning');
same($money, $nativeMoney(), 'Nameserver confirmation preserves native financial records');
$engine->execute($result->id);
same(['Purchase'], array_column($forms, 0), 'Completion never replays the purchase');
$nativeSuccessful = true;
echo "Configured Enom nameservers survive an incomplete reply and read-only completion.\n";
