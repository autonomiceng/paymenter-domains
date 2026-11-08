<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\ComplimentaryAccountSetting;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;
staffPanel();ComplimentaryAccountSetting::boot();app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser(['admin.domains.fund']);$plain=staffUser(['admin.users.update']);
config(['session.driver'=>'file','session.files'=>getenv('PAYMENTER_TEST_FIXTURE').'/storage/framework/sessions']);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$post=static function($actor,int $target,array $data)use($app,$kernel){
    Illuminate\Support\Once::flush();$app['auth']->forgetGuards();$app['session']->forgetDrivers();
    $app['session']->driver()->start();auth()->login($actor);
    session()->put('user_session',App\Models\UserSession::create(['user_id'=>$actor->id,'last_activity'=>now()])->ulid);
    $token=session()->token();$app['session']->driver()->save();$cookie=config('session.cookie');
    $jar=[$cookie=>$app['encrypter']->encrypt(CookieValuePrefix::create($cookie,$app['encrypter']->getKey()).session()->getId(),false)];
    $app['auth']->forgetGuards();$app['session']->forgetDrivers();
    $request=Request::create('http://domains-tests.invalid/domains/staff/customers/'.$target.'/complimentary','POST',['_token'=>$token,...$data],$jar,[],['HTTP_ACCEPT'=>'application/json']);
    $response=$kernel->handle($request);$kernel->terminate($request,$response);return $response;
};
$rows=static fn()=>DB::table('domains_complimentary_accounts')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
$before=$rows();
foreach([$user->id,PHP_INT_MAX]as$target){same(403,$post($plain,$target,['enabled'=>'1','reason'=>'Unauthorised gift'])->getStatusCode(),'Funding permission is required before customer lookup');}
same($before,$rows(),'Denied route requests add no audit entries');
foreach([['enabled'=>'1'],['enabled'=>'1','reason'=>str_repeat('r',501)],['enabled'=>'2','reason'=>'Invalid toggle']]as$data){same(422,$post($staff,$user->id,$data)->getStatusCode(),'Native POST validates reason and toggle');}
same($before,$rows(),'Invalid route requests add no audit entries');
$response=$post($staff,$user->id,['enabled'=>'1','reason'=>'Customer gift']);same(302,$response->getStatusCode(),'Valid native POST redirects to customer editor');
expect(str_ends_with($response->headers->get('Location'),'/users/'.$user->id.'/edit'),'Save returns to the edited customer');
$current=ComplimentaryAccounts::current($user->id);same([$staff->id,true,'Customer gift'],[$current['actor'],$current['enabled'],$current['reason']],'Native POST retains the authenticated staff actor and reason');
same(count($before)+1,count($rows()),'Valid POST appends exactly one audit entry');
$enabled=$rows();same(302,$post($staff,$user->id,['enabled'=>'1','reason'=>'Repeated gift'])->getStatusCode(),'Repeated enabled POST succeeds');same($enabled,$rows(),'Repeated setting is idempotent');
same(302,$post($staff,$user->id,['reason'=>'Gift ended'])->getStatusCode(),'Unchecked native form disables future gifts');
same(false,ComplimentaryAccounts::current($user->id)['enabled'],'Disable is recorded');
$disabled=$rows();same(302,$post($staff,$user->id,['reason'=>'Repeated end'])->getStatusCode(),'Repeated disabled POST succeeds');same($disabled,$rows(),'Repeated disable adds no audit entry');
same(404,$post($staff,PHP_INT_MAX,['enabled'=>'1','reason'=>'Unknown customer'])->getStatusCode(),'Unknown customer returns not found');same($disabled,$rows(),'Unknown customer cannot create an audit entry');
echo "Native complimentary account POST permissions, validation, audit and repeat saves passed\n";$nativeSuccessful=true;
