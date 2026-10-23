<?php
// Shared installed Enom TEST account fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';require_once __DIR__.'/staff-tlds.php';require_once __DIR__.'/native-commerce.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,DispatchSeal,Environment,ExecutionRecord,HistoryAcceptance,Query,RequestOrigin,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,EnomTestCalls,EnomTestCallLimit,EnomTestCallSchema,EnomTestRegistration,Installation,NativeCatalogue,ObservationWork,Runtime,RuntimePorts,RuntimeWork,StaffSettingsReview as Review,StaffSettingsWrites,Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$clock=new class implements Clock {
    public DateTimeImmutable $at;
    public function __construct(){$this->at=new DateTimeImmutable('2026-10-21T12:00:00Z');}
    public function now():DateTimeImmutable{return $this->at;}
};
app()->instance(Clock::class,$clock);Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$session=app('session')->driver();$session->start();app('request')->setLaravelSession($session);app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');app(Illuminate\Foundation\Vite::class)->useHotFile($hot);
require_once app_path('Classes/helpers.php');tldPanel();
$rdap=new Paymenter\Extensions\Servers\Domains\Services\Rdap(static function(string $url):array{$path=parse_url($url,PHP_URL_PATH);$data=str_ends_with($path,'dns.json')?['services'=>[[['com'],['https://rdap.example.com/']]]]:['objectClassName'=>'domain','ldhName'=>basename($path),'status'=>['active'],'events'=>[['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-10-21T00:00:00Z']]];return ['status'=>200,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];});
app()->instance(Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck::class,new Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck($rdap));
$staff=staffUser([...array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()),'admin.invoices.update']);Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic TEST registration','type'=>'server','extension'=>'Domains','enabled'=>true]);
(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
$reply=static function(array $p)use($clock):string {
    same(0,DB::transactionLevel(),'Every physical request outside financial locks');
    same('https://resellertest.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'TEST endpoint only');
    $command=$p['Command'];$name=($p['SLD']??'').'.'.($p['TLD']??'com');
    if($command==='Check'&&($GLOBALS['advanceDuringPreflight']??false)){unset($GLOBALS['advanceDuringPreflight']);$clock->at=$clock->at->modify('+1 second');}
    if(isset($GLOBALS['testRegisteredName'])){$name=$GLOBALS['testRegisteredName'];}
    $bill=$clock->now()->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');
    $body=match($command){
        'GetBalance'=>'<AvailableBalance>100.00</AvailableBalance>',
        'GetTLDList'=>'<TLDList><TLD>com</TLD></TLDList>',
        'GetTLDDetails'=>'<TLDs><TLD><TLD>com</TLD><Registration><Minimum>1</Minimum><Maximum>1</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs>',
        'GetExtAttributes'=>'<ResponseCount>0</ResponseCount>',
        'PE_GetProductPrice'=>'<ProductPrice><ProductEnabled>true</ProductEnabled><Price>10.0000</Price></ProductPrice>',
        'Check'=>'<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.(($GLOBALS['testTransferQuery']??false)?'211':(($GLOBALS['testNameUnavailable']??false)?'211':'210')).'</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP>'.(($GLOBALS['testTransferQuery']??false)?'<Properties><Transferable>true</Transferable></Properties>':'').'</Domain></Domains>',
        'Purchase'=>'<RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-21</ExpirationDate>',
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$name.'</DomainName><Status><Belongs-To>test-uid</Belongs-To><Expiration>2027-10-21</Expiration></Status></GetDomainInfo>',
        'GetDomainExp'=>'<ExpirationDate>2027-10-21</ExpirationDate>',
        'GetRenew'=>'<RenewName>0</RenewName>','GetRegLock'=>'<Reg-Lock>1</Reg-Lock>',
        'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
        'GetOrderDetail'=>'<Order><OrderID>1234</OrderID><Result>true</Result><OrderBillAmount>$10.00</OrderBillAmount><BillDate>'.$bill.'</BillDate><OrderDetail><Description>'.$name.'</Description><ProductType>Register</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>10.00</AmountPaid><ExpiryDateTime>2027-10-21</ExpiryDateTime></OrderDetail></Order>',
        default=>throw new LogicException('Unreviewed TEST command '.$command),
    };
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
resetWire(['reply'=>$reply]);
$settings=app(StaffSettingsWrites::class);$bindings=app(AccountBindings::class);$ports=app(RuntimePorts::class);
expect($settings->connectionAvailable(),'Installed TEST phase boots staff connection checks');
$write=static function(string $action,array $data=[],?int $tld=null)use($settings,$staff,$server):void {
    $r=new Review($staff->id,$server->id,$tld,$action,Review::ticket($staff->id,$server->id,$tld,$action,Review::stamp($server->id,$tld)),'Review synthetic TEST registration');$settings->run($r,$data);
};
$write('configure',['registrar'=>'enom','environment'=>'test','uid'=>'test-uid','token'=>'TEST-SECRET-TOKEN']);
$a=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($a);
same(false,$a->processing_enabled,'Identity leaves processing off');$write('probe');
