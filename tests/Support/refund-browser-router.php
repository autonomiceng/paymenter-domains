<?php
// Serves the seeded fixture app to a local browser. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixture=(string)getenv('PAYMENTER_TEST_FIXTURE');
$path=(string)(parse_url((string)$_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
if($path!=='/'&&!str_contains($path,'..')&&is_file($fixture.'/public'.$path)&&!str_ends_with($path,'.php')){return false;}
foreach(['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),'APP_URL'=>(string)getenv('REFUND_BROWSER_URL'),
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
    // Upstream has no compiled theme assets; these inert URLs are never fetched.
    $hot=storage_path('framework/refund-browser-assets');file_put_contents($hot,'http://assets.example.test');
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
        return redirect('/admin');
    });
    // Test-only readout of the saved money, decrypted the same way the app reads it.
    Illuminate\Support\Facades\Route::get('/refund-browser-facts',static function(){
        $seed=json_decode((string)file_get_contents((string)getenv('REFUND_BROWSER_SEED')),true,flags:JSON_THROW_ON_ERROR);
        $ops=[];
        foreach(['refund','failing','unknown'] as $key){
            $op=Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($seed[$key]['id']);$invoice=App\Models\Invoice::findOrFail($seed[$key]['invoice']);
            $ops[$key]=['state'=>$op->state,'safe_code'=>$op->safe_code,'funding_keys'=>array_keys($op->funding??[]),
                'staff_actions'=>array_map(fn($a)=>[$a['action'],$a['phase'],$a['reason']],$op->policy['staff_actions']??[]),'refund'=>$op->funding['refund']??null,
                'invoice'=>['status'=>$invoice->status,'transactions'=>$invoice->transactions()->get(['amount','gateway_id','transaction_id'])->toArray()]];
        }
        return response()->json(['operations'=>$ops,
            'credits'=>App\Models\Credit::where('user_id',$seed['user'])->get(['amount','currency_code'])->map(fn($c)=>[(string)$c->amount,$c->currency_code])->all()]);
    });
});
$app->handleRequest(Illuminate\Http\Request::capture());
