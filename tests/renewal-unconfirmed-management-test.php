<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainManagement, HoldingLocks, ManagementEvidence, RenewalFunding, WriteFeatures};

$management = new DomainManagement($bindings, $clock, new WriteFeatures(management: true), true);
$failures = [];
foreach (['auth_code_email', 'nameservers'] as $action) {
    $peer = null; $driver = null; $calls = [];
    try {
        [$op, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-unknown-'.str_replace('_', '-', $action).'-placeholder.com'), beforeBill:
            static function ($d) use ($action, $management, $user, $clock, &$peer, &$driver, &$calls): void {
                Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
                $binding = AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $d->server_id)->sole());
                $driver = new Management($binding, static function ($origin, $command, $parameters, $timeout) use ($d, &$calls): string {
                    same(0, DB::transactionLevel(), 'Management exchanges stay outside locks');
                    $calls[] = $command;
                    if (in_array($command, ['SynchAuthInfo', 'ModifyNS'], true)) { throw new RuntimeException('Synthetic lost management reply'); }
                    $body = match ($command) {
                        'GetDomainInfo' => '<GetDomainInfo><DomainName DomainNameID="456">'.$d->domain.'</DomainName><Status><Belongs-To>account-enom</Belongs-To></Status></GetDomainInfo>',
                        'GetDNS' => '<dns>ns1.example.com</dns><dns>ns2.example.com</dns>',
                        default => throw new LogicException('Unexpected management exchange'),
                    };
                    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
                });
                $input = $action === 'nameservers' ? ['nameservers' => ['ns3.example.net', 'ns4.example.net']] : [];
                $peer = $management->request($user, $d->id, $action, $input, hash('sha256', $action), $driver);
                same('uncertain', $management->run($peer->id, $driver)->state, 'Lost management reply remains unknown');
                same('uncertain', $management->reconcile($peer->id, $driver)->state, 'Readback cannot resolve this management request');
                expect(!ManagementEvidence::resolved($peer->fresh(), $d), 'Fixture has an unresolved management peer');
            });
        $before = $peer->fresh()->getRawOriginal(); $managementCalls = $calls;
        DB::transaction(static fn () => RenewalFunding::requireCurrent($op, HoldingLocks::take($d)));
        // The exception is renewal-specific: lock changes and another email still wait for this peer.
        commerceReject(fn () => $management->request($user, $d->id, 'lock', ['locked' => false], hash('sha256', 'lock-'.$action), $driver));
        commerceReject(fn () => $management->request($user, $d->id, 'auth_code_email', [], hash('sha256', 'email-'.$action), $driver));
        [$engine, $writer, $completion] = $compose($b); $writes = $state->writes;
        same('pending', $engine->run($op->id, $writer)->state, 'Paid renewal dispatches beside unconfirmed '.$action);
        same($writes + 1, $state->writes, 'Renewal sends once');
        $state->expiry = '2028-10-18'; $state->charge = '8.00';
        same('succeeded', $completion->run($op->id)->state, 'Exact renewal charge and expiry complete beside '.$action);
        same('2028-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'Renewal advances confirmed expiry');
        same($before, $peer->fresh()->getRawOriginal(), 'Renewal preserves the unknown management operation byte for byte');
        same($managementCalls, $calls, 'Renewal never resends or acknowledges management');
        expect(!isset($peer->fresh()->attempt['management_ack']), 'No management acknowledgement is invented');
        echo 'PASS renewal beside unconfirmed '.$action."\n";
    } catch (Throwable $error) {
        $failures[] = $action.': '.$error->getMessage();
        echo 'FAIL renewal beside unconfirmed '.$action.': '.$error->getMessage()."\n";
    }
    Cache::flush();
}
if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
$nativeSuccessful = true;
