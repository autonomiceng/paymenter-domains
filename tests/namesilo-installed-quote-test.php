<?php
// Native encrypted catalogue settings and the real installed factory reproduce acceptance loss at assembly.
// Synthetic cURL only; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{Action,Environment,Query,RequestBudget,RequestContext as Context,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,Installation,RegistrarFactories,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
function hrtime(bool $number=false): int {return 0;}
function curl_init(): object {++$GLOBALS['installedQuoteWire']['inits'];return new \stdClass;}
function curl_setopt_array($handle,array $options): bool {$GLOBALS['installedQuoteWire']['options']=$options;return true;}
function curl_getinfo($handle,int $option): int {return 200;}
function curl_close($handle): void {}
function curl_exec($handle): bool {
    same(0,DB::transactionLevel(),'Installed quotes read prices outside locks');
    ++$GLOBALS['installedQuoteWire']['calls'];$options=$GLOBALS['installedQuoteWire']['options'];
    $url=parse_url($options[CURLOPT_URL]);
    same('www.namesilo.com',$url['host'],'Installed reader retains its live binding');
    same('/apibatch/getPrices',$url['path'],'Installed quote uses the background prices endpoint');
    $body=json_encode(['request'=>['operation'=>'getPrices'],'reply'=>['code'=>300,'detail'=>'synthetic',
        'com'=>['registration'=>'12.34','renew'=>'12.36','transfer'=>'12.35'],
        'net'=>['registration'=>'13.34','renew'=>'13.36','transfer'=>'13.35']]],JSON_THROW_ON_ERROR);
    return ($options[CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
}
(new Installation)->install();
$clock=new FakeClock(new \DateTimeImmutable('2026-11-03T12:00:00Z'));
$server=Server::create(['name'=>'Synthetic installed quote','type'=>'server','extension'=>'Domains','enabled'=>true]);
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'namesilo',
    'environment'=>'live','stable_identity'=>'synthetic-installed-quote-account']));
$binding=CatalogueAcceptances::binding($account);
CatalogueAcceptances::save($binding,0,[
    'com'=>['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true],
    'net'=>['register'=>[],'renew'=>[],'transfer'=>[],'registry'=>true,'renewal_safe'=>true],
]);
$verifier=new class implements AccountVerifier {
    public function verify(Environment $environment,#[\SensitiveParameter] array $credentials): string {return 'synthetic-installed-quote-account';}
};
$registry=new RegistrarRegistry;RegistrarFactories::install($registry,null,$verifier);
$GLOBALS['installedQuoteWire']=['inits'=>0,'calls'=>0,'options'=>[]];
$reader=$registry->reader($binding,['api_key'=>'synthetic-key']);
$context=static fn()=>new Context($binding,RequestOrigin::Background,$clock->now()->modify('+1 minute'),new RequestBudget(1),$clock);
$refused=$reader->quote(new Query('example.net',Action::Register,1),$context());
$actual=[$refused->disposition->value,$refused->sendState->value,$refused->evidence()->quote?->cost->amount,$GLOBALS['installedQuoteWire']['calls']];
same(['rejected','definitely_unsent',null,0],$actual,'Installed factory must refuse an unaccepted priced ending without reads: '.json_encode($actual));
same(0,$GLOBALS['installedQuoteWire']['inits'],'Unaccepted installed quote creates no transport handle');
same('12.34',$reader->quote(new Query('example.com',Action::Register,1),$context())->evidence()->quote?->cost->amount,
    'Installed factory retains the accepted ending price');
same(1,$GLOBALS['installedQuoteWire']['calls'],'Accepted installed quote reads prices once');
$legacy=new Driver(new Client($binding,'synthetic-key'),['register'],$clock);
same('13.34',$legacy->quote(new Query('example.net',Action::Register,1),$context())->evidence()->quote?->cost->amount,
    'Legacy direct action-list construction retains its quote behavior');
same(2,$GLOBALS['installedQuoteWire']['calls'],'Legacy direct quote makes its own single price read');
echo "Installed NameSilo quotes retain per-ending acceptance and legacy direct constructor behavior.\n";
$nativeSuccessful=true;
