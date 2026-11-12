<?php
// Shared installed sales setup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,DispatchSeal,Environment,ExecutionRecord,HistoryAcceptance,Query,RequestOrigin,SendState};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomTestCalls, EnomTestCallLimit, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, Installation, NativeCatalogue, ObservationWork, Runtime, RuntimePorts, RuntimeWork, StaffSettingsReview as Review, StaffSettingsWrites, Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;

if(is_file($receipt.'.enom-sales-fixture')){$resumeEnomFixture=json_decode(file_get_contents($receipt.'.enom-sales-fixture'),true,512,JSON_THROW_ON_ERROR);}
require __DIR__.'/enom-test-registration-fixture.php';
if(isset($resumeEnomFixture['at'])){$clock->at=new DateTimeImmutable($resumeEnomFixture['at']);}
require_once __DIR__.'/installed-offer-evidence.php';
config(['domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true]);
if(!isset($resumeEnomFixture)){
saveDefaultRule(['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
$tld=installedOfferEvidence($binding,$clock);
$write('test_renewal',['accept'=>true,'verified'=>true]);
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);
$write('publish',[],$tld->id);$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
}
if(!Illuminate\Support\Facades\Schema::hasTable('cache')){
Illuminate\Support\Facades\Schema::create('cache',static function($t):void {$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration')->index();});
Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
}
config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);Queue::fake();
$user=isset($resumeEnomFixture)?App\Models\User::findOrFail($resumeEnomFixture['user']):commerceUser();$user->update(['email'=>'registration-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->updateOrCreate(['key'=>$key],['name'=>$key,'value'=>$value]);}

if(isset($resumeEnomFixture)){$tld=Tld::findOrFail($resumeEnomFixture['tld']);}
else{$write('processing',['enabled'=>true,'confirmed'=>true]);$write('publish',[],$tld->id);}
$product=app(NativeCatalogue::class)->product($tld,'register');$worker=app(RuntimeWork::class);
$new=static function(string $name)use($user,$product,$clock):Operation {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart=commerceCart($user);commerceAdd($cart,$product,$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);
    same('paid',App\Models\Invoice::findOrFail($op->invoice_id)->status,'Native payment marks the invoice paid');
    return $op->fresh();
};
file_put_contents($receipt.'.enom-sales-fixture',json_encode(['staff'=>$staff->id,'server'=>$server->id,'user'=>$user->id,'tld'=>$tld->id],JSON_THROW_ON_ERROR));
