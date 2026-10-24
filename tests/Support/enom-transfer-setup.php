<?php
// Minimal native installed transfer fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';require_once __DIR__.'/staff-tlds.php';require_once __DIR__.'/native-commerce.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,NativeCatalogue,StaffSettingsReview as Review,StaffSettingsWrites};
$clock=new class implements Clock {public DateTimeImmutable $at;public function __construct(){$this->at=new DateTimeImmutable('2026-10-22T00:10:00Z');}public function now():DateTimeImmutable{return $this->at;}};
app()->instance(Clock::class,$clock);Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$session=app('session')->driver();$session->start();app('request')->setLaravelSession($session);app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');app(Illuminate\Foundation\Vite::class)->useHotFile($hot);require_once app_path('Classes/helpers.php');tldPanel();
$rdapMode=(object)['mode'=>'ok','calls'=>0];$rdap=new \Paymenter\Extensions\Servers\Domains\Services\Rdap(static function(string $url,int $timeout)use($rdapMode):array {
    same(0,DB::transactionLevel(),'Transfer RDAP outside locks');expect($timeout>0&&$timeout<=8000,'Bounded RDAP');$rdapMode->calls++;
    if(str_ends_with(parse_url($url,PHP_URL_PATH),'dns.json')){return ['status'=>200,'body'=>json_encode(['services'=>[[['com'],['https://rdap.example.com/']]]])];}
    if($rdapMode->mode==='unknown'){throw new RuntimeException('Synthetic unavailable registry lookup');}
    if($rdapMode->mode==='404'){return ['status'=>404,'body'=>'{"errorCode":404}'];}
    return ['status'=>200,'body'=>json_encode(['objectClassName'=>'domain','ldhName'=>basename(parse_url($url,PHP_URL_PATH)),'status'=>[$rdapMode->mode==='locked'?'client transfer prohibited':'active'],'events'=>[['eventAction'=>'registration','eventDate'=>$rdapMode->mode==='young'?'2026-10-21T00:00:00Z':'2020-01-01T00:00:00Z'],['eventAction'=>'transfer','eventDate'=>in_array($rdapMode->mode,['young','recent'],true)?'2026-10-21T00:00:00Z':'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-10-21T00:00:00Z']]])];
});app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Rdap::class,$rdap);
$staff=staffUser([...array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()),'admin.invoices.update']);Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic TEST transfer','type'=>'server','extension'=>'Domains','enabled'=>true]);(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
$transferState=(object)['name'=>'setup.example.com','bill'=>'','pending'=>true,'charge'=>true];require __DIR__.'/enom-transfer-wire.php';
$setupReply=static function(array $p)use($transferReply):string {
    $body=match($p['Command']){
        'GetTLDList'=>'<TLDList><TLD>com</TLD></TLDList>',
        'GetTLDDetails'=>'<TLDs><TLD><TLD>com</TLD><Registration><Minimum>1</Minimum><Maximum>1</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs>',
        'GetExtAttributes'=>'<ResponseCount>0</ResponseCount>',default=>null,
    };return $body===null?$transferReply($p):'<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$setupReply]);config(['domains.enom_test_daily_call_cap'=>1000]);
$settings=app(StaffSettingsWrites::class);$write=static function(string $action,array $data=[],?int $tld=null)use($settings,$staff,$server):void{$r=new Review($staff->id,$server->id,$tld,$action,Review::ticket($staff->id,$server->id,$tld,$action,Review::stamp($server->id,$tld)),'Review synthetic TEST transfer');$settings->run($r,$data);};
$write('configure',['registrar'=>'enom','environment'=>'test','uid'=>'test-uid','token'=>'TEST-SECRET-TOKEN']);$a=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($a);$write('probe');
$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'test_registration_accepted'=>true,'transfer'=>true]);
$write('default_rule',['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);$write('import',['ending'=>'com']);$tld=Tld::where('server_id',$server->id)->sole();
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);$write('publish',[],$tld->id);$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>true],$tld->id);
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);$transferProduct=app(NativeCatalogue::class)->product($tld,'transfer');
Illuminate\Support\Facades\Schema::create('cache',static function($t):void{$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration')->index();});Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void{$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);Queue::fake();$write('processing',['enabled'=>true,'confirmed'=>true]);
$user=commerceUser();$user->update(['email'=>'transfer-owner@example.com']);foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}

$offlinePsl=static fn():never=>throw new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');app()->instance(\Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new \Paymenter\Extensions\Servers\Domains\Services\PslTransport($offlinePsl));
app(\Paymenter\Extensions\Servers\Domains\Services\ObservationWork::class)->enroll($server->id);
DB::table('domains_runtime_turns')->insertOrIgnore(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'turn'=>0,'due_at'=>$clock->now()->getTimestamp()+3600,'finished_at'=>0]);
