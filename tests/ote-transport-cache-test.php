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
    $attempts = 0;
    $cached = new GuardedTransport('offline-secret-token', false, $output,
        static function () use (&$attempts): TransportResponse {
            if (++$attempts === 2) { throw new RuntimeException('Synthetic timeout'); }
            return new TransportResponse(200, json_encode(['request' => ['operation' => 'orderDetails'],
                'reply' => ['code' => 300, 'detail' => 'synthetic', 'order_number' => '901']]));
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
