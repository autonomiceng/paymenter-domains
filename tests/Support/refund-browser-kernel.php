<?php
// Answers one browser request with the seeded fixture app's own HTTP kernel. MIT, Copyright (c) 2026 Autonomic Engineering.
// refund-browser.cjs writes the request as JSON on stdin and reads the reply as JSON from descriptor 3; nothing listens on a port.
declare(strict_types=1);
$fixture=(string)getenv('PAYMENTER_TEST_FIXTURE');
$wire=json_decode((string)stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
// The app runs as an HTTP request, as under the PHP web server: in console mode Laravel skips the CSRF check.
foreach(['APP_ENV'=>'testing','APP_RUNNING_IN_CONSOLE'=>'false','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),'APP_URL'=>(string)getenv('REFUND_BROWSER_URL'),
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>(string)getenv('REFUND_BROWSER_DATABASE'),'CACHE_STORE'=>'file','QUEUE_CONNECTION'=>'sync',
    'SESSION_DRIVER'=>'file','MAIL_MAILER'=>'array','TELEMETRY_ENABLED'=>'false','LOG_CHANNEL'=>'single'] as $key=>$value){
    putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;
}
$loader=require $fixture.'/vendor/autoload.php';
$loader->addPsr4('App\\',$fixture.'/app/',true);
$loader->addPsr4('Paymenter\\Extensions\\',$fixture.'/extensions/',true);
$app=require $fixture.'/bootstrap/app.php';
$app->booted(static function()use($app):void{
    config(['settings.mail_disabled'=>true,'settings.default_currency'=>'USD','settings.tax_enabled'=>false,'audit.enabled'=>false]);
    // Upstream has no compiled theme assets; the driver answers this prefix with empty files.
    $hot=storage_path('framework/refund-browser-assets');file_put_contents($hot,(string)getenv('REFUND_BROWSER_URL').'/refund-browser-assets');
    app(Illuminate\Foundation\Vite::class)->useHotFile($hot);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $instant=new DateTimeImmutable((string)getenv('REFUND_BROWSER_CLOCK'));
    $app->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($instant) implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
        public function __construct(private DateTimeImmutable $instant) {}
        public function now(): DateTimeImmutable { return $this->instant; }
    });
    // Test-only sign-in: the theme's login page needs compiled assets this fixture does not have.
    Illuminate\Support\Facades\Route::middleware('web')->get('/refund-browser-login',static function(){
        $user=App\Models\User::where('email',(string)getenv('REFUND_BROWSER_EMAIL'))->sole();
        Illuminate\Support\Facades\Auth::login($user);session()->regenerate();
        session()->put('user_session',App\Models\UserSession::create(['user_id'=>$user->id,'last_activity'=>now()])->ulid);
        // A cookie scoped to this path, which the browser keeps from the redirect to /admin.
        return redirect('/admin')->withCookie(cookie('refund-browser-scoped','1',0,'/refund-browser-login'));
    });
    // Test-only readout of the saved money, decrypted the same way the app reads it.
    Illuminate\Support\Facades\Route::get('/refund-browser-facts',static function(){
        $seed=json_decode((string)file_get_contents((string)getenv('REFUND_BROWSER_SEED')),true,flags:JSON_THROW_ON_ERROR);
        $ops=[];
        foreach(['refund','failing','unknown'] as $key){
            if(!isset($seed[$key])){continue;}
            $op=Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($seed[$key]['id']);$invoice=App\Models\Invoice::findOrFail($seed[$key]['invoice']);
            $ops[$key]=['state'=>$op->state,'safe_code'=>$op->safe_code,'funding_keys'=>array_keys($op->funding??[]),
                'staff_actions'=>array_map(fn($a)=>[$a['action'],$a['phase'],$a['reason']],$op->policy['staff_actions']??[]),'refund'=>$op->funding['refund']??null,
                'invoice'=>['status'=>$invoice->status,'transactions'=>$invoice->transactions()->get(['amount','gateway_id','transaction_id'])->toArray()]];
        }
        return response()->json(['operations'=>$ops,
            'credits'=>App\Models\Credit::where('user_id',$seed['user'])->get(['amount','currency_code'])->map(fn($c)=>[(string)$c->amount,$c->currency_code])->all()]);
    });
});

/** Rebuild the browser's request as the PHP web server would have presented it. */
function browserRequest(array $wire): Illuminate\Http\Request
{
    $server=['REMOTE_ADDR'=>'127.0.0.1','SERVER_PROTOCOL'=>'HTTP/1.1'];
    foreach($wire['headers'] as $name=>$value){
        $key=strtoupper(str_replace('-','_',(string)$name));
        $server[in_array($key,['CONTENT_TYPE','CONTENT_LENGTH'],true)?$key:'HTTP_'.$key]=(string)$value;
    }
    $cookies=[];
    foreach(explode(';',(string)($wire['headers']['cookie']??'')) as $pair){
        [$name,$value]=array_pad(explode('=',trim($pair),2),2,'');
        if($name!==''){$cookies[$name]=urldecode($value);}
    }
    $content=base64_decode((string)$wire['body'],true);
    if(!is_string($content)){throw new RuntimeException('Browser request body is not base64');}
    $type=(string)($server['CONTENT_TYPE']??'');
    if(str_starts_with($type,'multipart/')){throw new RuntimeException('The refund pages send no file uploads');}
    $parameters=[];
    if(str_starts_with($type,'application/x-www-form-urlencoded')){parse_str($content,$parameters);}
    Illuminate\Http\Request::enableHttpMethodParameterOverride();
    return Illuminate\Http\Request::create((string)$wire['url'],(string)$wire['method'],$parameters,$cookies,[],$server,$content);
}

$request=browserRequest($wire);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$response=$kernel->handle($request);
ob_start();
$response->sendContent();
$body=(string)ob_get_clean();
$headers=array_map(static fn(array $values):string=>implode(', ',$values),$response->headers->allPreserveCaseWithoutCookies());
$reply=fopen('php://fd/3','wb')?:throw new RuntimeException('The driver opens the reply descriptor');
fwrite($reply,json_encode(['status'=>$response->getStatusCode(),'headers'=>$headers,
    'cookies'=>array_map('strval',$response->headers->getCookies()),'body'=>base64_encode($body)],JSON_THROW_ON_ERROR));
fclose($reply);
$kernel->terminate($request,$response);
