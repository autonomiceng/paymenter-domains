<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceCatalogue,PricingRule,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
$f=commerceFixture(true);extract($f);tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);
$review=static fn(string $action,?int $tld)=>new Review($u->id,$t->server_id,$tld,$action,Review::ticket($u->id,$t->server_id,$tld,$action,Review::stamp($t->server_id,$tld)),'Update prices for these TLDs');
$rows=static fn()=>Price::where('server_id',$t->server_id)->orderBy('id')->get()->map(fn($p)=>[$p->id,$p->revision,$p->retail,$p->eligibility])->all();
same(['percent'=>'2.90','fixed'=>'0.30'],CardFee::read($t->server_id),'Default card fee');
$oldRule=(new PricingRules)->rule($t);$before=$rows();
same(['published'=>0,'failed'=>[]],$writer->run($review('card_fee',null),['fee_percent'=>'3.10','fee_fixed'=>'0.40'],$now),'Changing fee does not publish ordinary modes');
same($oldRule,(new PricingRules)->rule($t->fresh()),'Ordinary saved rule unchanged');same($before,$rows(),'Ordinary published revisions unchanged');
same(CardFee::DEFAULT,CardFee::read($tlds[1]->server_id),'Fee belongs to one account');
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));$audit=end($history);
same('Edit card fee',$audit['action'],'Card fee change audited');
same(CardFee::DEFAULT,$audit['before']['card_fee'],'Audit has old fee');same(['percent'=>'3.10','fixed'=>'0.40'],$audit['after']['card_fee'],'Audit has new fee');
$feeRule=['mode'=>'markup_fee_percent','markup'=>'5','round_up'=>'ending_99'];
$writer->run($review('override',$t->id),$feeRule,$now);
$snapshot=$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now);
same('6.99',$snapshot->retail,'Fee mode publishes');
$before=$rows();
same(['published'=>1,'failed'=>[]],$writer->run($review('card_fee',null),['fee_percent'=>'10','fee_fixed'=>'1'],$now),'Fee change republishes fee modes only');
same('8.99',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'New fee used in published price');
same('6.99',$snapshot->retail,'Earlier snapshot stays unchanged');
same(['percent'=>'10.00','fixed'=>'1.00'],(new PricingRules)->rule($t->fresh())['card_fee'],'Fee mode uses account setting');
$writer->run($review('override',$t->id),['mode'=>'markup_percent','markup'=>'30','round_up'=>'none'],$now);
same($oldRule,(new PricingRules)->rule($t->fresh()),'Switch back retains legacy rule shape and hash');
same('7.80',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Switch back restores old calculation');
$weak=staffUser(array_values(array_diff($permissions,[Review::permission('card_fee')])));Auth::setUser($weak);
$denied=new Review($weak->id,$t->server_id,null,'card_fee',Review::ticket($weak->id,$t->server_id,null,'card_fee',Review::stamp($t->server_id,null)),'Update card fee estimate');
tldRefusal(fn()=>$writer->run($denied,['fee_percent'=>'2.9','fee_fixed'=>'0.3'],$now),'Current staff permissions required.');
same(['percent'=>'10.00','fixed'=>'1.00'],CardFee::read($t->server_id),'Denied fee change saves nothing');
Auth::setUser($u);
$before=$rows();
try{$writer->run($review('card_fee',null),['fee_percent'=>'100','fee_fixed'=>'0'],$now);expect(false,'Invalid fee escaped');}catch(InvalidArgumentException){}
same($before,$rows(),'Invalid fee leaves publications alone');same(['percent'=>'10.00','fixed'=>'1.00'],CardFee::read($t->server_id),'Invalid fee leaves setting alone');
// Default followers republish while an ordinary own rule on the same account stays unchanged.
$writer->run($review('default_rule',null),$feeRule,$now);
$writer->run($review('remove_override',$t->id),[],$now);
$own=$t->fresh()->replicate();$own->fill(['tld'=>'net','use_default_rule'=>false,'pricing_mode'=>'markup_percent','markup'=>'30','round_up'=>'none'])->save();
foreach(Price::where(['server_id'=>$t->server_id,'tld'=>'com'])->get() as $p){$copy=$p->replicate();$copy->tld='net';$copy->save();}
$ownRows=static fn()=>Price::where(['server_id'=>$t->server_id,'tld'=>'net'])->orderBy('id')->get()->map(fn($p)=>$p->getRawOriginal())->all();
$ordinaryBefore=$ownRows();$followerBefore=$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now);
same(['published'=>1,'failed'=>[]],$writer->run($review('card_fee',null),['fee_percent'=>'20','fee_fixed'=>'2'],$now),'Default fee-mode followers republish');
$followerAfter=$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now);
expect($followerAfter->revision>$followerBefore->revision,'Default follower has a new published revision');same('10.99',$followerAfter->retail,'Default follower uses new fee');
same($ordinaryBefore,$ownRows(),'Ordinary own-rule prices and revisions stay unchanged');
// Synced costs stay usable until the next sync, even after their old freshness window.
$later=$now->modify('+2 days');
same(['published'=>1,'failed'=>[]],$writer->run($review('card_fee',null),['fee_percent'=>'21','fee_fixed'=>'2'],$later),'Old synced follower still republishes');
expect($prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$later)->revision>$followerAfter->revision,'Old synced cost produces a new published revision');
// Missing annual cost still blocks publication without undoing the saved fee.
$renewal=Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'renew'])->sole();$cost=$renewal->wholesale;$renewal->update(['wholesale'=>null,'revision'=>$renewal->revision+1]);$before=$rows();
same(['published'=>0,'failed'=>['.com']],$writer->run($review('card_fee',null),['fee_percent'=>'22','fee_fixed'=>'2'],$later),'Follower missing annual cost reports its name');
same(['percent'=>'22.00','fixed'=>'2.00'],CardFee::read($t->server_id),'Fee still commits when publication cost is missing');same($before,$rows(),'Failed publication retains every old revision');
$renewal->update(['wholesale'=>$cost,'revision'=>$renewal->revision+1]);
// Register a real postcommit race on the matching fee save, after the completed audit and before fanout.
$server=App\Models\Server::findOrFail($t->server_id);$default=$server->settings()->where('key','domains_pricing_default_rule')->sole()->value;
$pending=true;$raced=false;
App\Models\Setting::saved(static function($setting)use($t,$server,&$pending,&$raced):void{
    if(!$pending||$setting->key!==CardFee::KEY||(int)$setting->settingable_id!==$t->server_id||($setting->fresh()->value['percent']??null)!=='12.00'){return;}
    $pending=false;
    DB::afterCommit(static function()use($server,&$raced):void{
        same(0,DB::transactionLevel(),'Fault occurs after fee transaction commits');
        $history=Review::historyRows($server);$audit=end($history);
        same(['percent'=>'12.00','fixed'=>'1.00'],$audit['after']['card_fee'],'Completed audit precedes the injected fault');
        $server->settings()->where('key','domains_pricing_default_rule')->firstOrFail()->update(['type'=>'string','value'=>'unreadable']);$raced=true;
    });
});
same(['published'=>0,'failed'=>['.com']],$writer->run($review('card_fee',null),['fee_percent'=>'12','fee_fixed'=>'1'],$now),'Postcommit unreadable default becomes a named publication failure');
expect($raced&&!$pending,'Genuine one-shot postcommit fault exercised');
same(['percent'=>'12.00','fixed'=>'1.00'],CardFee::read($t->server_id),'Committed fee survives postcommit default failure');same($ordinaryBefore,$ownRows(),'Postcommit fallback still excludes ordinary own rules');
$server->settings()->where('key','domains_pricing_default_rule')->firstOrFail()->update(['type'=>'array','value'=>$default]);
// An unreadable saved fee can be repaired through the full native form, including fee-mode rules.
pinStaffClock($now);app()->instance(StaffSettingsWrites::class,$writer);
$own->update(['pricing_mode'=>'markup_fee_percent','markup'=>'100']);
$setting=$server->settings()->where('key',CardFee::KEY)->sole();$setting->update(['type'=>'string','value'=>'unreadable']);
$brokenFee=$setting->fresh()->getRawOriginal();$before=$rows();$historyBefore=Review::historyRows($server);$keysBefore=DB::table(Review::KEYS)->count();
$display=new \Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueCache(app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class));
$display->remember(static fn()=>['retained display']);
$mountFee=static fn()=>Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSuccessful()
    ->mountAction(setupAction('card_fee',$t->server_id))->assertActionMounted(setupAction('card_fee',$t->server_id));
$page=$mountFee();$defaults=$page->get('mountedActions.0.data');
same('2.90',$defaults['fee_percent'],'Unreadable fee form prefills default percent');same('0.30',$defaults['fee_fixed'],'Unreadable fee form prefills default fixed amount');
// The default's 5% markup can cover 60%; the own rule's 100% markup cannot.
$page->setActionData(['fee_percent'=>'60','fee_fixed'=>'0','reason'=>'Refuse impossible own fee-mode rule'])->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't save")->body('No price can cover the card fee with this markup. Lower the markup or the card fee. Nothing was changed.'));
same($brokenFee,$setting->fresh()->getRawOriginal(),'Impossible own rule rolls back the new fee');
same($historyBefore,Review::historyRows($server),'Impossible fee adds no audit');same($keysBefore,DB::table(Review::KEYS)->count(),'Impossible fee rolls back the review key');
same($before,$rows(),'Impossible fee leaves published revisions alone');same(['retained display'],$display->cachedRows(),'Rolled-back fee does not invalidate the display cache');
try{$writer->run($review('card_fee',null),['fee_percent'=>'99','fee_fixed'=>'0'],$now);expect(false,'Impossible default escaped');}
catch(InvalidArgumentException $e){same('This percentage cannot cover the card fee. Lower the markup or card fee.',$e->getMessage(),'Impossible default rule refused');}
same($brokenFee,$setting->fresh()->getRawOriginal(),'Impossible default rolls back the repair');
same($historyBefore,Review::historyRows($server),'Impossible default adds no audit');same(['retained display'],$display->cachedRows(),'Impossible default rolls back cache invalidation');
$page=$mountFee();
$page->setActionData(['fee_percent'=>'3.1','fee_fixed'=>'0.4','reason'=>'Repair unreadable card fee estimate'])->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Saved')->body('Published new prices for 2 TLDs.'))->assertSet('mountedActions',[]);
same(['percent'=>'3.10','fixed'=>'0.40'],CardFee::read($t->server_id),'Native form repairs the saved fee');
$history=Review::historyRows($server);$audit=end($history);
same('unavailable',$audit['before']['card_fee'],'Repair audit retains unavailable old fee');same('unavailable',$audit['before']['rule'],'Repair audit retains unavailable old default rule');
same(['percent'=>'3.10','fixed'=>'0.40'],$audit['after']['card_fee'],'Repair audit contains the validated new fee');
same(['percent'=>'3.10','fixed'=>'0.40'],(new PricingRules)->rule($own->fresh())['card_fee'],'Own fee mode uses the repaired fee');
same('6.99',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Default follower republishes after repair');
same('13.65',$prices->current($t->server_id,$t->account_fingerprint,'net','register',1,'USD',$now)->retail,'Own fee rule republishes after repair');
same(null,$display->cachedRows(),'Successful repair invalidates display cache after commit');same(0,$reads->calls,'No provider calls in card fee changes or repair');
echo "Account card fee, audit, mode changes and permissions passed.\n";$nativeSuccessful=true;
