<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
foreach (['Shapes', 'ParserDiagnostics', 'PurchaseAttempt', 'GuardedTransport'] as $class) { require __DIR__.'/contract/'.$class.'.php'; }
use OteContract\GuardedTransport;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Environment, RegistrarRequest, RequestContext, TransportResponse};

$output = sys_get_temp_dir().'/ote-cache-'.bin2hex(random_bytes(6)); mkdir($output, 0700);
$clock = static fn () => 21;
$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR)['getDomainInfo'];
try {
    $requests=[];
    $optional=new GuardedTransport('offline-secret-token',false,$output,
        static function($command,$parameters)use(&$requests):TransportResponse {
            $requests[]=$parameters;
            return new TransportResponse(200,json_encode(['request'=>['operation'=>$command],
                'reply'=>['code'=>300,'detail'=>'synthetic','order'=>[]]]));
        },$clock);
    set_error_handler(static function(int $level,string $message):never { throw new ErrorException($message,0,$level); });
    try {
        foreach([[],['date_from'=>'2026-10-01'],['date_to'=>'2026-10-22']]as$filters){
            $optional->raw('https://ote.namesilo.com/apibatch','listOrders',$filters);
            $entry=$optional->receipt['requests'][array_key_last($optional->receipt['requests'])];
            same('Success',$entry['read_outcome'],'Omitted dates parse without warnings');
            expect(!($entry['transport_failed']??false),'Optional dates do not fail the exchange');
        }
        try { $optional->raw('https://ote.namesilo.com/apibatch','orderDetails',[]); throw new LogicException('Missing order accepted'); }
        catch(RuntimeException $error){same('OTE order number required.',$error->getMessage(),'Missing order is refused by policy');}
        same(3,count($requests),'Missing order refused before sender');
    } finally { restore_error_handler(); }
    $dispatch = static function (GuardedTransport $wire, string $entrypoint, array $parameters): void {
        if ($entrypoint === 'raw') { $wire->raw('https://ote.namesilo.com/api', 'orderDetails', $parameters); return; }
        // Corrupt mappings test the transport independently of the request factory's validation.
        $type = new ReflectionClass(RegistrarRequest::class);
        $request = $type->newInstanceWithoutConstructor();
        $type->getConstructor()->invoke($request, 'orderDetails', $parameters);
        if ($entrypoint === 'send') { $wire->send(Environment::Sandbox, RequestContext::Interactive, $request, 'offline-secret-token'); }
        else { $wire->sendWithin(Environment::Sandbox, RequestContext::Interactive, $request, 'offline-secret-token', 25000); }
    };
    $violations = [];
    foreach (['raw', 'send', 'sendWithin'] as $entrypoint) {
        $calls = 0;
        $valid = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR)['orderDetails'];
        $wire = new GuardedTransport('offline-secret-token', false, $output,
            static function ($command, $parameters) use (&$calls, $valid): TransportResponse {
                $calls++;
                $valid['reply']['order_number'] = is_scalar($parameters['order_number'] ?? null) ? (string) $parameters['order_number'] : '901';
                return new TransportResponse(200, json_encode($valid, JSON_THROW_ON_ERROR));
            }, $clock);
        foreach (['901', '902'] as $id) {
            $dispatch($wire, $entrypoint, ['order_number' => $id]);
            same((string) $id, $wire->latest('orderDetails')['order_number'] ?? null, 'Scalar ID supplies latest evidence: '.$entrypoint);
            same((string) $id, $wire->orderDetail((string) $id)['order_number'] ?? null, 'Scalar ID supplies detail evidence: '.$entrypoint);
        }
        $details = [$wire->orderDetail('901'), $wire->orderDetail('902')];
        foreach (['missing' => [], 'null' => ['order_number' => null], 'empty' => ['order_number' => ''],
            'array' => ['order_number' => []], 'nested' => ['order_number' => ['901']], 'boolean' => ['order_number' => true],
            'float' => ['order_number' => 901.0], 'object' => ['order_number' => new stdClass]] as $case => $parameters) {
            $dispatch($wire, $entrypoint, ['order_number' => '901']);
            $before = $wire->receipt; $beforeCalls = $calls; $error = null;
            try { $dispatch($wire, $entrypoint, $parameters); } catch (Throwable $caught) { $error = $caught; }
            $label = $entrypoint.' '.$case;
            if (!$error instanceof RuntimeException || $error->getMessage() !== 'OTE order number required.') { $violations[] = $label.' policy refusal'; }
            if ($calls !== $beforeCalls) { $violations[] = $label.' sender advanced'; }
            if ($wire->receipt !== $before) { $violations[] = $label.' request receipt changed'; }
            if ($wire->latest('orderDetails') !== []) { $violations[] = $label.' latest evidence retained'; }
            if ([$wire->orderDetail('901'), $wire->orderDetail('902')] !== $details) { $violations[] = $label.' unrelated detail changed'; }
            foreach (['', 'Array', '1'] as $invalidId) {
                if ($wire->orderDetail($invalidId) !== []) { $violations[] = $label.' malformed detail cached'; }
            }
        }
    }
    same([], $violations, 'Malformed order IDs: '.implode('; ', $violations));
    foreach (['raw', 'send', 'sendWithin'] as $entrypoint) {
        $beforeCalls = $calls; $beforeCount = $wire->receipt['request_count'];
        $dispatch($wire, $entrypoint, ['order_number' => 902]);
        same($beforeCalls + 1, $calls, 'Integer order reaches sender once: '.$entrypoint);
        same($beforeCount + 1, $wire->receipt['request_count'], 'Integer order consumes one request: '.$entrypoint);
        same('902', $wire->orderDetail('902')['order_number'] ?? null, 'Integer order caches validated detail: '.$entrypoint);
    }
    $wire->send(Environment::Sandbox, RequestContext::Interactive, RegistrarRequest::orderDetails('901'), 'offline-secret-token');
    same('901', $wire->latest('orderDetails')['order_number'] ?? null, 'Public request factory retains the valid string path');
    foreach (['throw', 'decode', 'http', 'budget', 'origin', 'environment', 'credential'] as $failureMode) {
        $attempts = 0;
        $cached = new GuardedTransport('offline-secret-token', false, $output,
            static function ($command) use (&$attempts, $failureMode, $fixture): TransportResponse {
                if (++$attempts === 2 && $failureMode === 'throw') { throw new RuntimeException('Synthetic timeout'); }
                return new TransportResponse($attempts === 2 && $failureMode === 'http' ? 503 : 200,
                    $attempts === 2 && $failureMode === 'decode' ? '{' : json_encode($fixture));
            }, $clock, $failureMode === 'budget' ? 1 : 60);
        $cached->raw('https://ote.namesilo.com/api', 'getDomainInfo', ['domain' => $cached->domain]);
        expect($cached->latest('getDomainInfo') !== [], 'First read is available');
        same('Success', $cached->receipt['requests'][0]['read_outcome'], 'First domain read succeeds in the real parser');
        try {
            if (in_array($failureMode, ['environment', 'credential'], true)) {
                $cached->send($failureMode === 'environment' ? Environment::Live : Environment::Sandbox, RequestContext::Interactive,
                    RegistrarRequest::domainInfo($cached->domain), $failureMode === 'credential' ? 'wrong-offline-key' : 'offline-secret-token');
            } else {
                $cached->raw($failureMode === 'origin' ? 'https://other.test/api' : 'https://ote.namesilo.com/api',
                    'getDomainInfo', ['domain' => $cached->domain]);
            }
        } catch (RuntimeException) { /* Dependent readback still needs fresh evidence. */ }
        same([], $cached->latest('getDomainInfo'), 'Failed attempt clears prior reply: '.$failureMode);
    }
    foreach (['getDomainInfo', 'orderDetails'] as $command) {
        $valid = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR)[$command];
        if ($command === 'orderDetails') { $valid['reply']['order_number'] = '901'; }
        foreach (['shape', 'non_success', 'operation'] as $invalid) {
            $calls = 0;
            $wire = new GuardedTransport('offline-secret-token', false, $output,
                static function () use (&$calls, $valid, $invalid): TransportResponse {
                    $body = $valid;
                    if (++$calls === 2) {
                        if ($invalid === 'shape') { unset($body['request']['ip']); }
                        if ($invalid === 'non_success') { $body['reply']['code'] = 400; $body['reply']['detail'] = 'Synthetic declined reply'; }
                        if ($invalid === 'operation') { $body['request']['operation'] = 'other'; }
                    }
                    return new TransportResponse(200, json_encode($body));
                }, $clock);
            $parameters = $command === 'orderDetails' ? ['order_number' => '901'] : ['domain' => $wire->domain];
            $wire->raw('https://ote.namesilo.com/api', $command, $parameters);
            expect($wire->latest($command) !== [], 'Valid read remains available: '.$command);
            $wire->raw('https://ote.namesilo.com/api', $command, $parameters);
            same([], $wire->latest($command), 'Invalid read cannot supply latest evidence: '.$command.' '.$invalid);
            if ($command === 'orderDetails') { same([], $wire->orderDetail('901'), 'Invalid detail cannot corroborate: '.$invalid); }
        }
    }
    $valid = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR)['orderDetails'];
    $valid['reply']['order_number'] = '901';
    $wrongOrder = $valid; $wrongOrder['reply']['order_number'] = '902';
    same([], \OteContract\Shapes::check('orderDetails', $wrongOrder), 'Wrong order remains structurally valid');
    $calls = 0;
    $wire = new GuardedTransport('offline-secret-token', false, $output,
        static function () use (&$calls, $valid, $wrongOrder): TransportResponse {
            return new TransportResponse(200, json_encode(++$calls === 1 ? $valid : $wrongOrder, JSON_THROW_ON_ERROR));
        }, $clock);
    $wire->raw('https://ote.namesilo.com/api', 'orderDetails', ['order_number' => '901']);
    expect($wire->latest('orderDetails') !== [], 'Matching order supplies latest evidence');
    expect($wire->orderDetail('901') !== [], 'Matching order supplies detail evidence');
    $wire->raw('https://ote.namesilo.com/api', 'orderDetails', ['order_number' => '901']);
    same('Unknown', $wire->receipt['requests'][1]['read_outcome'], 'Real parser rejects the different order number');
    same([], $wire->latest('orderDetails'), 'Parser-rejected read cannot supply latest evidence');
    same([], $wire->orderDetail('901'), 'Parser-rejected read cannot corroborate the requested order');
    same([], $wire->orderDetail('902'), 'Different order is not cached under its own number');
    $attempts = 0;
    $cached = new GuardedTransport('offline-secret-token', false, $output,
        static function () use (&$attempts): TransportResponse {
            if (++$attempts === 2) { throw new RuntimeException('Synthetic timeout'); }
             $body = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true)['orderDetails'];
            $body['reply']['order_number'] = '901';
            return new TransportResponse(200, json_encode($body));
        }, $clock);
    $cached->raw('https://ote.namesilo.com/api', 'orderDetails', ['order_number' => '901']);
    expect($cached->orderDetail('901') !== [], 'First order detail is available');
    try { $cached->raw('https://ote.namesilo.com/api', 'orderDetails', ['order_number' => '901']); }
    catch (RuntimeException) { /* A failed reread cannot leave corroborating evidence. */ }
    same([], $cached->orderDetail('901'), 'Failed attempt clears cached order detail');
} finally {
    foreach (glob($output.'/*') as $file) { unlink($file); } rmdir($output);
}
echo "OTE failed attempts clear latest replies and order details\n";
