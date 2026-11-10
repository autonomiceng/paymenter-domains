<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A real web-kernel GET; a signed-in customer comes back only through the saved session cookie.
declare(strict_types=1);
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
function webVisit(?App\Models\User $who, string $path, array $headers=[]): Symfony\Component\HttpFoundation\Response
{
    $app=app();$jar=[];
    config(['session.driver'=>'file','session.files'=>getenv('PAYMENTER_TEST_FIXTURE').'/storage/framework/sessions']);
    Auth::logout();Illuminate\Support\Once::flush();$app['auth']->forgetGuards();$app['session']->forgetDrivers();
    if ($who!==null) {
        $app['session']->driver()->start();Auth::login($who);
        session()->put('user_session',App\Models\UserSession::create(['user_id'=>$who->id,'last_activity'=>now()])->ulid);$app['session']->driver()->save();
        $jar[config('session.cookie')]=$app['encrypter']->encrypt(CookieValuePrefix::create(config('session.cookie'),$app['encrypter']->getKey()).session()->getId(),false);
        $app['auth']->forgetGuards();$app['session']->forgetDrivers();
    }
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$server=[];foreach($headers as $name=>$value){$server['HTTP_'.strtoupper(str_replace('-','_',$name))]=$value;}
    $request=Request::create('http://domains-tests.invalid'.$path,'GET',[],$jar,[],$server);
    // No terminate: its callbacks reload site settings from the database, dropping the settings a test chose.
    return $kernel->handle($request);
}
