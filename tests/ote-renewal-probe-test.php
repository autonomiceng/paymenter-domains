<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract {
    function fsync($file): bool {
        $directory=(fstat($file)['mode']&0170000)===0040000;
        if ($directory) { $GLOBALS['ote_directory_syncs']=($GLOBALS['ote_directory_syncs']??0)+1; }
        return !($GLOBALS['ote_sync_failure']??false)&&!($directory&&($GLOBALS['ote_directory_sync_failure']??false))&&\fsync($file);
    }
    function fopen($path,$mode) {
        if (is_dir($path)&&($GLOBALS['ote_directory_open_failure']??false)) { return false; }
        return \fopen($path,$mode);
    }
}
namespace {
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'PurchaseAttempt', 'RenewalProbe', 'RenewalContinuation', 'GuardedTransport'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\{GuardedTransport, PurchaseAttempt, RenewalContinuation, RenewalProbe};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse;

$refused = static function (Closure $f, string $expected): void {
    try { $f(); } catch (Throwable $error) {
        same(RuntimeException::class, $error::class, 'Renewal refusal class');
        same($expected, $error->getMessage(), 'Renewal refusal must name its guard: '.$expected.'; received '.$error->getMessage()); return;
    }
    throw new RuntimeException('Expected refusal: '.$expected);
};
$cleanup = static function (string $directory) use (&$cleanup): void {
    foreach (glob($directory.'/*') as $file) { is_dir($file) ? $cleanup($file) : unlink($file); } rmdir($directory);
};
$priorEvidence = static function (string $directory, string $before): string {
    mkdir($directory, 0700); $name = 'contract-ote-'.bin2hex(random_bytes(6)).'.com'; $utc = gmdate(DATE_ATOM);
    $attempt = ['name' => $name, 'authorized_amount' => '8.95', 'term' => 1, 'route' => '/apibatch', 'dispatch_utc' => $utc,
        'command' => 'renewDomain', 'before_expiry' => $before, 'renewal_category' => 'not_renewed'];
    PurchaseAttempt::writePrivate($directory.'/attempt-private.json', $attempt);
    PurchaseAttempt::writePrivate($directory.'/registration-attempt-private.json', array_replace($attempt, ['command' => 'registerDomain', 'route' => '/api']));
    PurchaseAttempt::writePrivate($directory.'/receipt.json', ['request_count' => 2, 'renewal_reconciliation' => ['category' => 'not_renewed'],
        'checks' => ['registration' => 'accepted_via_real_admitted_protocol'], 'requests' => [
            ['command' => 'registerDomain', 'route' => 'api', 'utc' => $utc], ['command' => 'renewDomain', 'route' => 'apibatch', 'utc' => $utc]]]);
    return $directory.'/receipt.json';
};
$probe = static function (string $scenario, string $route = 'background') use ($refused, $cleanup, $priorEvidence): void {
    $out = sys_get_temp_dir().'/ote-renewal-'.bin2hex(random_bytes(6)); mkdir($out, 0700);
    $wire = null; $calls = []; $renewed = false; $attempted = false;
    $stamp = $scenario === 'old-date' ? '2020-01-01 00:00:00' : gmdate('Y-m-d H:i:s');
    $before = gmdate('Y-m-d', strtotime('+1 year'));
    $after = (new DateTimeImmutable($before))->modify('+1 year')->format('Y-m-d');
    putenv('OTE_RENEWAL_CONTEXT='.$route); putenv('NAMESILO_SANDBOX_API_KEY=offline-secret-token');
    try {
        $fake = static function ($command, $p) use (&$wire, &$calls, &$renewed, &$attempted, $scenario, $before, $after, $stamp): TransportResponse {
            $calls[] = $command; $reply = ['code' => 300, 'detail' => 'synthetic'];
            if ($command === 'getDomainInfo') {
                $reply += ['created' => gmdate('Y-m-d'), 'expires' => $renewed ? $after : $before,
                    'status' => 'Active', 'locked' => 'Yes', 'private' => 'No', 'auto_renew' => 'No'];
                if ($attempted && $scenario === 'bad-readback') { $reply['expires']=$before; }
                if (!$attempted && $scenario === 'expired') { $reply['expires'] = gmdate('Y-m-d'); }
                if ($attempted && $scenario === 'unknown-info') { $reply = ['code' => null, 'detail' => null]; }
            } elseif ($command === 'getPrices') {
                $reply['com'] = $reply['net'] = ['registration' => '8.95', 'renew' => '8.95', 'transfer' => '8.95'];
                if ($scenario === 'quote-timeout') { throw new RuntimeException('Synthetic quote timeout'); }
                if ($scenario === 'quote-rejected') { $reply=['code'=>200,'detail'=>'synthetic']; }
                if ($scenario === 'quote-missing') { unset($reply['com']['renew']); }
                if ($scenario === 'quote-no-com') { unset($reply['com']); }
            } elseif ($command === 'renewDomain') {
                $attempted = true;
                same(1, $p['years'], 'Only one annual term'); same(0, $p['auto_renew'], 'Explicit renewal auto-renew off');
                $renewed = !in_array($scenario, ['absent', 'shape-not-renewed', 'unchanged-order', 'timeout', 'unknown-orders', 'unknown-info', 'empty-detail', 'partial-history', 'unknown-empty-orders', 'invalid-description', 'uppercase', 'trailing-dot'], true);
                if (in_array($scenario,['success','bad-readback'],true)) { $reply += ['domain' => $wire->domain, 'order_amount' => '8.95']; }
                else { $reply = ['code' => null, 'detail' => 'private account 123 and cost 8.95']; }
                if ($scenario === 'timeout') { throw new RuntimeException('synthetic timeout'); }
            } elseif ($command === 'listOrders') {
                $reply['order'] = in_array($scenario, ['absent', 'shape-not-renewed', 'expiry-only', 'timeout', 'unknown-empty-orders'], true) ? [] :
                    [['order_number' => '901', 'order_date' => $stamp, 'total' => '8.95', 'method' => 'Account Funds']];
                if (in_array($scenario, ['unknown-orders', 'unknown-empty-orders'], true)) { $reply['detail'] = null; }
                if ($scenario === 'overflow') { $reply['order'] = array_fill(0, 21, $reply['order'][0]); }
            } elseif ($command === 'orderDetails') {
                $reply += ['order_number' => '901', 'order_date' => $stamp, 'total' => '8.95', 'method' => 'Account Funds',
                    'order_details' => [['description' => $wire->domain.' - renewal', 'years_qty' => '1', 'status' => 'Processed', 'price' => '8.95', 'subtotal' => '8.95']]];
                if ($scenario === 'uppercase') { $reply['order_details'][0]['description'] = strtoupper($wire->domain).' - renewal'; }
                if ($scenario === 'trailing-dot') { $reply['order_details'][0]['description'] = $wire->domain.'. - renewal'; }
                if ($scenario === 'empty-detail') { $reply['order_details'] = []; }
                if ($scenario === 'partial-history') { $reply['page'] = '2'; $reply['order_details'][0]['description'] = $wire->domain.' - registration'; }
                if ($scenario === 'invalid-description') { $reply['order_details'][0]['description'] = 'invalid..com - registration'; }
                if ($scenario === 'wrong-amount') { $reply['order_details'][0]['subtotal'] = '8.96'; }
                if ($scenario === 'wrong-term') { $reply['order_details'][0]['years_qty'] = '2'; }
                if ($scenario === 'wrong-name') { $reply['order_details'][0]['description'] = 'contract-ote-123456789abc.com - renewal'; }
                if ($scenario === 'old-date') { $reply['order_date'] = '2020-01-01 00:00:00'; }
                if ($scenario === 'duplicate') { $reply['order_details'][] = $reply['order_details'][0]; }
                if ($scenario === 'credited') { $reply['order_details'][0]['credited_amount'] = '8.95'; }
                if ($scenario === 'detail-timeout') { throw new RuntimeException('synthetic'); }
            } else { throw new RuntimeException('Unexpected command'); }
            if ($command==='getDomainInfo') {
                $reply+=['traffic_type'=>'Custom DNS','email_verification_required'=>'No','portfolio'=>'','forward_url'=>'N/A','forward_type'=>'N/A',
                    'nameservers'=>[['nameserver'=>'ns1.example.test','position'=>1],['nameserver'=>'ns2.example.test','position'=>2]],
                    'contact_ids'=>array_fill_keys(['registrant','administrative','technical','billing'],'101')];
            }
            if ($command === 'getDomainInfo' && $attempted && in_array($scenario, ['shape-renewed', 'shape-not-renewed'], true)) {
                same([], \OteContract\Shapes::check($command, ['request' => ['operation' => $command, 'ip' => '192.0.2.1'], 'reply' => $reply]),
                    'Renewal fixture is valid before the one-field structural defect');
                unset($reply['traffic_type']);
            }
            if ($command==='renewDomain'&&in_array($scenario,['success','bad-readback'],true)) { $reply['message']='synthetic'; }
            return new TransportResponse($scenario === 'http' && $command === 'listOrders' ? 503 : 200,
                json_encode(['request' => ['ip'=>'192.0.2.1','operation' => $scenario === 'wrong-operation' && $command === 'getDomainInfo' && $attempted ? 'getPrices' : $command], 'reply' => $reply], JSON_THROW_ON_ERROR));
        };
        $priorReceipt = $route === 'interactive' ? $priorEvidence($out.'/prior', $before) : null;
        $wire = new GuardedTransport('offline-secret-token', true, $out, $fake, static fn () => 21, $scenario === 'budget' ? 24 : 40,
            continuationReceipt: $priorReceipt);
        $wire->authorizeRenewal(true);
        $binding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic');
        $thrown = false;
        try { RenewalProbe::run($wire, new Client($binding, 'offline-secret-token', $wire), $binding, new SystemClock); }
        catch (Throwable $error) {
            same(RuntimeException::class, $error::class, 'Unresolved renewal exception class');
            same('OTE renewal needs investigation.', $error->getMessage(), 'Unresolved renewal stops at its outcome guard');
            $thrown = true;
        }
        if (in_array($scenario, ['expired','budget','quote-timeout','quote-rejected','quote-missing','quote-no-com'], true)) {
            same(false, $thrown, 'Blocked probe returns without aborting');
            same(false, $attempted, 'Readback, budget and quote prevent purchase');
            if (str_starts_with($scenario,'quote-')) {
                same('blocked_without_renewal_quote',$wire->receipt['checks']['renew_probe'],'Quote failure has an explicit blocked result');
                same(['getDomainInfo','getPrices'],$calls,'Quote failure performs only preflight reads');
                $private=is_file($out.'/attempt-private.json')?PurchaseAttempt::readPrivate($out.'/attempt-private.json'):[];
                expect(!isset($private['dispatch_utc']),'Quote failure creates no purchase dispatch evidence');
            }
            same(false,RenewalProbe::completed($wire),'Skipped diagnostic cannot complete');return;
        }
        $expected = match ($scenario) { 'renewed' => 'renewed', 'absent', 'timeout' => 'not_renewed', 'success','bad-readback' => null, default => 'ambiguous' };
        if (in_array($scenario, ['shape-renewed', 'shape-not-renewed'], true)) {
            $infos = array_values(array_filter($wire->receipt['requests'], static fn ($r) => $r['command'] === 'getDomainInfo'));
            $info = $infos[array_key_last($infos)];
            same('Success', $info['read_outcome'], 'Malformed renewal info still passes the production parser');
            same(false, $info['read_validated'], 'Malformed renewal info is not validated evidence');
            same([], $wire->latest('getDomainInfo'), 'Malformed renewal info is absent from the success cache');
        }
        same($expected, $wire->receipt['renewal_reconciliation']['category'] ?? null, 'Exactly one closed renewal category: '.$scenario);
        same(!in_array($scenario, ['success','bad-readback','renewed'], true), $thrown, 'Unknown unresolved stops, success continues');
        if ($expected !== null) {
            $data = PurchaseAttempt::readPrivate($out.'/attempt-private.json');
            same($expected, $data['renewal_category'], 'Private continuation evidence retains category');
            expect(in_array('listOrders', $calls, true) && count(array_filter($calls, static fn ($c) => $c === 'getDomainInfo')) >= 2,
                'Unknown reconciles history and exact owned-name readback in same invocation');
        }
        same(1, count(array_filter($calls, static fn ($c) => $c === 'renewDomain')), 'No renewal replay');
        foreach ($wire->receipt['requests'] as $row) {
            same($row['command'] === 'renewDomain' && $route === 'interactive' ? 'api' : 'apibatch', $row['route'], 'Only renewal exchange reroutes');
        }
        $receipt = file_get_contents($out.'/receipt.json');
        foreach ([$wire->domain, '8.9500', 'private account 123 and cost 8.95', 'offline-secret-token'] as $secret) {
            expect(!str_contains($receipt, $secret), 'No private scalar in receipt');
        }
        if (!in_array($scenario,['success','bad-readback','timeout'],true)) {
            same('unknown_reply', array_values(array_filter($wire->receipt['requests'], static fn ($r) => $r['command'] === 'renewDomain'))[0]['category'], 'Null-code purchase has a closed category');
            same('private account 123 and cost 8.95', PurchaseAttempt::readPrivate($out.'/attempt-private.json')['non_success_replies'][0]['detail'], 'Unknown detail retained privately');
        }
        if ($scenario==='success') { same([], $wire->receipt['mismatches'], 'Success fixture matches the full contract'); }
        same($scenario==='success',RenewalProbe::completed($wire),'Completion needs a sent renewal and no mismatches');
        if ($scenario==='bad-readback') { same(false,$wire->receipt['checks']['renew_added_one_year'],'Wrong expiry blocks completion after send'); }
        if ($scenario === 'success') { same(true, $wire->receipt['checks']['renew_auto_renew_request_accepted'], 'Documented acknowledgement accepted'); }
        if ($scenario === 'renewed') {
            same(false, $wire->receipt['checks']['renew_auto_renew_request_accepted'], 'Unknown never promoted to documented acceptance');
            same(true, $wire->receipt['checks']['renew_added_one_year'], 'Exact year readback');
            same(true, $wire->receipt['checks']['renew_auto_renew_remained_off'], 'Flag readback');
        }
    } finally {
        putenv('OTE_RENEWAL_CONTEXT'); putenv('NAMESILO_SANDBOX_API_KEY');
        $cleanup($out);
    }
};
foreach (['success', 'renewed', 'absent', 'expiry-only', 'unchanged-order', 'wrong-amount', 'wrong-term', 'wrong-name',
    'empty-detail', 'partial-history', 'unknown-empty-orders', 'invalid-description', 'uppercase', 'trailing-dot', 'old-date', 'duplicate', 'credited', 'unknown-orders', 'unknown-info', 'timeout', 'detail-timeout', 'wrong-operation', 'http', 'overflow','expired','budget','quote-timeout','quote-rejected','quote-missing','quote-no-com','bad-readback','shape-renewed','shape-not-renewed'] as $scenario) { $probe($scenario); }
$probe('renewed', 'interactive'); $probe('absent', 'interactive');

$out = sys_get_temp_dir().'/ote-details-'.bin2hex(random_bytes(6)); mkdir($out, 0700);
try {
    $wire = new GuardedTransport('offline-secret-token', true, $out,
        static fn () => new TransportResponse(200, json_encode(['request' => ['operation' => 'contactAdd'],
            'reply' => ['code' => 210, 'detail' => 'private contact person 123']])), static fn () => 21);
    $wire->raw('https://ote.namesilo.com/apibatch', 'contactAdd', []);
    same('private contact person 123', PurchaseAttempt::readPrivate($out.'/attempt-private.json')['non_success_replies'][0]['detail'], 'Raw pre-purchase refusal detail retained privately');
    same('provider_non_success', $wire->receipt['requests'][0]['category'], 'Closed refusal category only');
    expect(!str_contains(file_get_contents($out.'/receipt.json'), 'private contact person 123'), 'Contact refusal never published');
    PurchaseAttempt::writePrivate($out.'/atomic.json', ['original' => true]);
    $GLOBALS['ote_sync_failure'] = true;
    $refused(fn () => PurchaseAttempt::writePrivate($out.'/atomic.json', ['replacement' => true]), 'Private attempt persistence failed.');
    $GLOBALS['ote_sync_failure'] = false;
    same(['original' => true], PurchaseAttempt::readPrivate($out.'/atomic.json'), 'Failed sync preserves the original private evidence');
    same([], glob($out.'/*.pending-*'), 'Failed persistence removes private temporary fixture');
    $syncs=$GLOBALS['ote_directory_syncs']??0;
    PurchaseAttempt::writePrivate($out.'/atomic.json',['replacement'=>true]);
    same($syncs+1,$GLOBALS['ote_directory_syncs'],'Atomic evidence replacement syncs its directory');
    foreach(['ote_directory_open_failure'=>'Private evidence directory open failed.','ote_directory_sync_failure'=>'Private evidence directory sync failed.']as$flag=>$message){
        $GLOBALS[$flag]=true;
        try { $refused(fn()=>PurchaseAttempt::writePrivate($out.'/atomic.json',['replacement'=>true]),$message); }
        finally { $GLOBALS[$flag]=false; }
        same([],glob($out.'/*.pending-*'),'Directory durability failure leaves no temporary files');
    }
    $secretWire = new GuardedTransport('offline-secret-token', true, $out,
        static fn () => new TransportResponse(200, json_encode(['request' => ['operation' => 'contactAdd'], 'reply' => ['code' => 210, 'detail' => 'offline-secret-token']])), static fn () => 21);
    $refused(fn () => $secretWire->raw('https://ote.namesilo.com/apibatch', 'contactAdd', []), 'OTE exchange unresolved.');
    expect(!str_contains(file_get_contents($out.'/attempt-private.json'), 'offline-secret-token'), 'Credential-bearing detail never persisted');
    $wire->receipt['leak'] = 'private contact person 123'; $refused(fn () => $wire->save(), 'Private reply detail leak refused.');
} finally { $cleanup($out); }

echo "OTE renewal: command routing, exact reconciliation, private refusals and no replay passed\n";

$out = sys_get_temp_dir().'/ote-continuation-'.bin2hex(random_bytes(6)); mkdir($out, 0700);
try {
    $name = 'contract-ote-123456789abc.com'; $utc = gmdate(DATE_ATOM); $before = gmdate('Y-m-d', strtotime('+1 year'));
    $changedExpiry = (new DateTimeImmutable($before))->modify('+1 year')->format('Y-m-d');
    $attempt = ['name' => $name, 'authorized_amount' => '8.95', 'term' => 1, 'route' => '/apibatch', 'dispatch_utc' => $utc,
        'command' => 'renewDomain', 'before_expiry' => $before, 'renewal_category' => 'not_renewed'];
    $registration = array_replace($attempt, ['command' => 'registerDomain', 'route' => '/api']);
    $prior = ['request_count' => 2, 'renewal_reconciliation' => ['category' => 'not_renewed'], 'checks' => ['registration' => 'accepted_via_real_admitted_protocol'],
        'requests' => [['command' => 'registerDomain', 'route' => 'api', 'utc' => $utc], ['command' => 'renewDomain', 'route' => 'apibatch', 'utc' => $utc]]];
    $reset = static function () use ($out, $attempt, $registration, $prior): void {
        foreach (['receipt.json' => $prior, 'attempt-private.json' => $attempt, 'registration-attempt-private.json' => $registration] as $path => $data) {
            PurchaseAttempt::writePrivate($out.'/'.$path, $data);
        }
    };
    $reset();
    $syncs=$GLOBALS['ote_directory_syncs']??0;
    same($name,RenewalContinuation::claim($out.'/receipt.json'),'POSIX directory stream sync succeeds in the pinned runtime');
    same($syncs+1,$GLOBALS['ote_directory_syncs'],'Claim syncs the parent directory');unlink($out.'/interactive-renewal-claimed');
    foreach (['ote_sync_failure'=>'Continuation claim failed.','ote_directory_open_failure'=>'Continuation directory open failed.','ote_directory_sync_failure'=>'Continuation directory sync failed.']as$flag=>$message) {
        $GLOBALS[$flag]=true;
        try { $refused(fn()=>RenewalContinuation::claim($out.'/receipt.json'),$message); }
        finally { $GLOBALS[$flag]=false; }
        expect(is_file($out.'/interactive-renewal-claimed'),'Failed durability check still consumes the claim');
        $refused(fn()=>RenewalContinuation::claim($out.'/receipt.json'),'Renewal continuation already consumed.');
        unlink($out.'/interactive-renewal-claimed');
    }
    foreach (['renewal_category' => 'ambiguous', 'name' => 'contract-ote-987654321abc.com', 'command' => 'registerDomain',
        'term' => 2, 'route' => '/api', 'before_expiry' => 'invalid', 'dispatch_utc' => '2020-01-01T00:00:00+00:00'] as $field => $bad) {
        PurchaseAttempt::writePrivate($out.'/attempt-private.json', array_replace($attempt, [$field => $bad]));
        if ($field === 'dispatch_utc') {
            $aged = $prior; $aged['requests'][1]['utc'] = $bad;
            PurchaseAttempt::writePrivate($out.'/receipt.json', $aged); // Isolate age, with matching retained timestamps.
        }
        $refused(fn () => RenewalContinuation::claim($out.'/receipt.json'), 'Renewal continuation evidence refused.'); $reset();
    }
    $bad = $prior; $bad['checks']['registration'] = 'unresolved_possibly_sent';
    PurchaseAttempt::writePrivate($out.'/receipt.json', $bad);
    $refused(fn () => RenewalContinuation::claim($out.'/receipt.json'), 'Renewal continuation evidence refused.'); $reset();
    $bad = $prior; $bad['renewal_reconciliation']['category'] = 'ambiguous';
    PurchaseAttempt::writePrivate($out.'/receipt.json', $bad);
    $refused(fn () => RenewalContinuation::claim($out.'/receipt.json'), 'Renewal continuation evidence refused.'); $reset();
    $bad = $prior; $bad['requests'][] = $bad['requests'][1]; $bad['request_count'] = 3;
    PurchaseAttempt::writePrivate($out.'/receipt.json', $bad);
    $refused(fn () => RenewalContinuation::claim($out.'/receipt.json'), 'Renewal continuation evidence refused.'); $reset();
    chmod($out.'/attempt-private.json', 0644);
    $refused(fn () => RenewalContinuation::claim($out.'/receipt.json'), 'Private evidence permissions refused.'); chmod($out.'/attempt-private.json', 0600);
    rename($out.'/attempt-private.json',$out.'/attempt-target.json');symlink($out.'/attempt-target.json',$out.'/attempt-private.json');
    $refused(fn()=>RenewalContinuation::claim($out.'/receipt.json'),'Private evidence permissions refused.');
    unlink($out.'/attempt-private.json');$reset();
    $refused(fn () => RenewalContinuation::claim($out.'/receipt.json', 59), 'Cumulative OTE budget refused.');
    putenv('OTE_RENEWAL_CONTEXT=interactive');
    $wire = new GuardedTransport('offline-secret-token', true, $out,
        static fn ($command) => new TransportResponse(200, json_encode(['request' => ['operation' => $command], 'reply' => [
            'code' => 300, 'detail' => 'synthetic'] + match ($command) {
                'getPrices' => ['com' => ['registration' => '8.95', 'renew' => '8.95', 'transfer' => '8.95']],
                'renewDomain' => ['domain' => $name, 'order_amount' => '8.95'],
                default => ['expires' => $changedExpiry, 'created' => gmdate('Y-m-d'), 'status' => 'Active',
                    'locked' => 'Yes', 'private' => 'No', 'auto_renew' => 'No'],
            }])), static fn () => 21,
        limit: 40, continuationReceipt: $out.'/receipt.json');
    same($name, $wire->domain, 'Only retained sent identity may continue');
    same($before, $wire->continuationExpiry, 'Original pre-renewal baseline retained for fresh comparison');
    $refused(fn () => RenewalContinuation::claim($out.'/receipt.json'), 'Renewal continuation already consumed.');
    foreach (['contactAdd', 'registerDomain', 'domainLock'] as $command) {
        $parameters = $command === 'contactAdd' ? [] : ['domain' => $name];
        $refused(fn () => $wire->raw('https://ote.namesilo.com/apibatch', $command, $parameters), 'Renewal continuation scope refused.');
    }
    same(0, $wire->receipt['request_count'], 'Continuation cannot start registration or management');
    $wire->purchaseAuthorization(static fn () => new \Paymenter\Extensions\Servers\Domains\Data\Money('8.95', 'USD'));
    $wire->renewalBefore($before); putenv('OTE_RENEWAL_CONTEXT=background');
    $refused(fn () => $wire->raw('https://ote.namesilo.com/apibatch', 'renewDomain', ['domain' => $name, 'years' => 1, 'auto_renew' => 0]), 'Renewal route requires claimed continuation.');
    same(0, $wire->receipt['request_count'], 'Claimed continuation cannot dispatch on the batch route');
    putenv('OTE_RENEWAL_CONTEXT=interactive');
    $binding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic');
    RenewalProbe::run($wire, new Client($binding, 'offline-secret-token', $wire), $binding, new SystemClock);
    same('Success', $wire->receipt['requests'][0]['read_outcome'], 'Fresh baseline fixture is a valid domain read');
    same(false, $wire->attempted('renewDomain'), 'Changed fresh baseline refuses the continuation purchase');
    same('blocked_without_active_owned_autorenew_off_readback', $wire->receipt['checks']['renew_probe'], 'Fresh baseline checked before purchase');
    same(false,RenewalProbe::completed($wire),'Consumed continuation with a changed expiry is not completed');
    foreach (['contactAdd', 'contactList', 'getPrices', 'getDomainInfo'] as $command) {
        putenv('OTE_REGISTRATION_CONTEXT=interactive');
        same('https://ote.namesilo.com/apibatch', GuardedTransport::purchaseOrigin($command, 'https://ote.namesilo.com/apibatch'), 'Non-purchase production route retained');
    }
} finally {
    putenv('OTE_RENEWAL_CONTEXT'); putenv('OTE_REGISTRATION_CONTEXT');
    $cleanup($out);
}

}
