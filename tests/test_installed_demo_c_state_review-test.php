<?php
// Real native pages and stored catalogue facts are the affected boundaries; a pure codec stub cannot prove page safety.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Models\{Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule,PricingRules};
extract(commerceFixture());tldPanel();pinStaffClock($now);$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
$failures=[];$case=static function(string $name,Closure $check)use(&$failures):void{try{$check();}catch(Throwable $e){$failures[]=$name.': '.substr($e->getMessage(),0,220);echo 'FAIL '.$name.': '.substr($e->getMessage(),0,220)."\n";}};
$original=$t->getRawOriginal();
$case('malformed intent renders safely',function()use($t,$actor){
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>'{"sell":']);
    $fact=TldFacts::snapshot($t->fresh());expect($fact['unreadable'],'Malformed row is unreadable');
    Livewire::actingAs($actor)->test(ListTlds::class)->assertSee('.com');
    Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertSee('Missing');
    expect(str_contains(TldFacts::accountsTable($t->fresh(),Staff::requireView())->toHtml(),'Missing'),'Account table renders the malformed row');
});
DB::table('domains_tlds')->where('id',$t->id)->update($original);
$case('unread feed retains real account cause',function()use($t,$now){
    DB::table('domains_prices')->where('server_id',$t->server_id)->delete();
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_retained_annual_retail'=>null,'updated_at'=>$now->modify('-1 hour'),'created_at'=>$now->modify('-1 hour')]);
    DB::table('domains_sales_policies')->where('tld',$t->tld)->update(['updated_at'=>$now->modify('-1 hour'),'created_at'=>$now->modify('-1 hour')]);
    $queue=['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint];
    foreach(['read_deadline','catalogue_call_capacity','account_unavailable'] as $failure){
        DB::table('domains_observation_queues')->updateOrInsert($queue,['offer_work'=>OfferState::json(['feed_due'=>$now->getTimestamp()+3600]),'offer_feed_succeeded_at'=>null,'offer_feed_failure'=>$failure]);
        $fact=TldFacts::snapshot($t->fresh());$state=TldFacts::priceState($fact);
        expect(str_contains($state['cause'],'Price feed not read yet: '.TldFacts::syncCause($failure)),'Unread feed names '.$failure.': '.$state['cause']);
        same('danger',$state['tone'],'Selected unread cost stays red');
        same('Check registrar account',$fact['sales']['reason'],'Account blocker is not overridden');
    }
    DB::table('domains_observation_queues')->where($queue)->update(['offer_feed_succeeded_at'=>$now->getTimestamp()-7200]);
    $fact=TldFacts::snapshot($t->fresh());expect(!str_contains(TldFacts::priceState($fact)['cause'],"Not in the registrar's price feed"),'Success before selection is not proof of absence');
    DB::table('domains_observation_queues')->where($queue)->update(['offer_feed_succeeded_at'=>$now->getTimestamp(),'offer_feed_failure'=>null]);
    $fact=TldFacts::snapshot($t->fresh());expect(str_contains(TldFacts::priceState($fact)['cause'],"Not in the registrar's price feed; not for sale"),'Success after selection proves a feed gap');
    DB::table('domains_sales_policies')->where('tld',$t->tld)->update(['updated_at'=>null,'created_at'=>null]);
    $fact=TldFacts::snapshot($t->fresh());expect(!str_contains(TldFacts::priceState($fact)['cause'],"Not in the registrar's price feed"),'Unknown selection time is not proof of absence');
});
DB::table('domains_tlds')->where('id',$t->id)->update($original);
foreach(['markup_percent','fixed','default'] as $kind){$case('invalid '.$kind.' wording',function()use($t,$kind,$actor){
    $rule=[...PricingRule::DEFAULT,'mode'=>$kind==='fixed'?'fixed':'markup_percent','markup'=>'invalid'];
    if($kind==='fixed'){$rule['fixed']=['register'=>'0','renew'=>'0','transfer'=>'0'];}
    if($kind==='default'){\App\Models\Setting::where('key',PricingRules::KEY)->firstOrFail()->update(['value'=>$rule]);$intent=[];}
    else{$intent=['pricing_rule'=>$rule];}
    DB::table('domains_tlds')->where('id',$t->id)->update(['use_default_rule'=>$kind==='default','pricing_mode'=>$kind==='fixed'?'fixed':'markup_percent','catalogue_intent'=>OfferState::json($intent)]);
    $fact=TldFacts::snapshot($t->fresh());$cause=TldFacts::priceState($fact)['cause'];
    $expected=match($kind){'fixed'=>'Invalid fixed price rule','default'=>'Invalid default pricing rule',default=>'Invalid markup rule'};
    expect(str_contains($cause,$expected),'Correct rule kind: '.$cause);
    if($kind!=='fixed'){expect(!str_contains($cause,'all three positive prices')&&!str_contains($fact['sales']['reason'],'Invalid fixed'),'Markup/default do not pretend to be fixed');}
    $html=TldFacts::accountsTable($t->fresh(),Staff::requireView())->toHtml();expect(str_contains($html,'Missing')&&str_contains($html,'href=')&&str_contains($html,$kind==='default'?'Edit default pricing':'Edit prices'),'Invalid rule keeps a repair link');
    $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('override')->call('$refresh');
    $modal=$page->effects['partials']['action-modals.0']??'';expect(str_contains($modal,$expected),'Modal names its actual invalid rule');
});DB::table('domains_tlds')->where('id',$t->id)->update($original);\App\Models\Setting::where('key',PricingRules::KEY)->firstOrFail()->update(['value'=>PricingRule::DEFAULT]);}
expect($failures===[],implode("\n",$failures));$nativeSuccessful=true;echo "Independent review state regressions passed.\n";
