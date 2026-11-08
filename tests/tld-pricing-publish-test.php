<?php
// Saving prices publishes them; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{PriceCatalogue,PricingRule,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);
$f=commerceFixture(true);extract($f);tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$permissions[]=Staff::COSTS;$u=staffUser($permissions);Auth::setUser($u);$commands=new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands(
    new \Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$writer=new class($native,$sellers,$bindings,true) extends StaffSettingsWrites {
    public \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands $commands;
    protected function catalogue():?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands{return $this->commands;}
};$writer->commands=$commands;
$review=static fn(string $action,?int $tld)=>new Review($u->id,$t->server_id,$tld,$action,Review::ticket($u->id,$t->server_id,$tld,$action,Review::stamp($t->server_id,$tld)),'Synthetic reviewed price change');
$rows=static fn(Tld $tld)=>Price::where(['server_id'=>$tld->server_id,'account_fingerprint'=>$tld->account_fingerprint,'tld'=>$tld->tld,'currency'=>'USD'])->orderBy('id')->get()->mapWithKeys(fn(Price $p)=>[$p->action.$p->term=>$p])->all();
$fields=static fn(array $rule)=>['mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up'],
    'fixed_register'=>$rule['fixed']['register'],'fixed_renew'=>$rule['fixed']['renew'],'fixed_transfer'=>$rule['fixed']['transfer']];
// Privileged fixture drift targets the active intent; real staff changes below still use reviewed commands.
$setFixtureRule=static function(Tld $row,?array $own):void {
    $intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($row->catalogue_intent);$intent['pricing_rule']=$own;
    Illuminate\Support\Facades\DB::table('domains_tlds')->where('id',$row->id)->update(['catalogue_intent'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json($intent)]);
};

// Plain formula text.
same('cost + 30%',PricingRule::describe(PricingRule::DEFAULT),'Default formula');
same('cost + $1.00, rounded up to the next $0.50',PricingRule::describe(['mode'=>'markup_amount','markup'=>'1','round_up'=>'half']),'Amount formula');
same('cost + 12.5%, rounded up to the next .99',PricingRule::describe(['mode'=>'markup_percent','markup'=>'12.50','round_up'=>'ending_99']),'Percent formula');
same('$20.00 for the first registration year, $21.00 to renew and $22.00 to transfer',PricingRule::describe(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'20','renew'=>'21','transfer'=>'22']]),'Own price formula');

// Saving a TLD price publishes it at once; the preview shows exactly what gets published.
$rule=PricingRule::validate(['mode'=>'markup_amount','markup'=>'1.20','round_up'=>'half']);
$preview=(new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock))->preview($t->fresh(),$rule);
same(['register1','register3','renew1','transfer1'],array_map(fn($r)=>$r['action'].$r['term'],$preview),'Preview lists each sold term in order');
same(['7.50','26.50','9.50','8.50'],array_column($preview,'new'),'Preview applies the rule to each term');
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$writer->run($review('override',$t->id),$fields($rule),$now);
$after=$rows($t->fresh());
foreach($preview as $row){
    $p=$after[$row['action'].$row['term']];
    same($before[$row['action'].$row['term']]+1,$p->revision,'Saving publishes a new revision');
    same($row['new'],$p->retail===null?null:PriceCatalogue::retail($p->retail),'Published price matches the preview');
    same($row['hold'],(new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock))->page(collect([$t->fresh()]))[$t->id]['offers'][$row['action'].':'.$row['term']]->decision->reason,'Published hold matches the preview');
}
same('7.50',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'New price is on sale without a separate publish');
$plan=App\Models\Product::where('slug',\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::slug($t,'register'))->sole()->plans()->where('billing_period',1)->sole();
same('7.50',number_format((float)$plan->prices()->where('currency_code','USD')->sole()->price,2,'.',''),'Store price follows: '.json_encode($plan->prices()->get()->toArray()));

// Below-cost rows: register and transfer are held; renew is still billed at the new price and alerts staff.
$low=PricingRule::validate(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'5.00','renew'=>'7.00','transfer'=>'9.00']]);
$preview=collect((new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock))->preview($t->fresh(),$low))->keyBy(fn($r)=>$r['action'].$r['term']);
same('below_cost',$preview['register1']['hold'],'Preview flags held registration');
same('7.00',$preview['renew1']['new'],'Preview bills the below-cost renewal price');same('below_cost',$preview['renew1']['alert'],'Preview flags billed renewal');
same(null,$preview['transfer1']['hold'],'Transfer above cost is sold');
$writer->run($review('override',$t->id),$fields($low),$now);$after=$rows($t->fresh());
foreach($preview as $key=>$row){same($row['new'],$after[$key]->retail===null?null:PriceCatalogue::retail($after[$key]->retail),'Below-cost publish matches the preview');same($row['hold'],(new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock))->page(collect([$t->fresh()]))[$t->id]['offers'][$row['action'].':'.$row['term']]->decision->reason,'Below-cost hold matches');}
same('7.00',$prices->renewal($t->server_id,$t->account_fingerprint,'com','USD',$now)->retail,'Renewal billed at the below-cost price');
commerceReject(fn()=>$ports->loadBatch([new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($bindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole()),['com'],'register')],\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Public)[0][0]->price());

// Choosing the account default publishes the default prices.
$writer->run($review('remove_override',$t->id),[],$now);
same(null,\Paymenter\Extensions\Servers\Domains\Catalogue\Pricing::own($t->fresh()),'TLD follows the account default');
same('7.80',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Default price published at once');
same('Switched to Default pricing',TldFacts::history($t->server_id,$t->id)[0]['change'],'History names the change');

// Older saved costs still publish exactly what the preview shows.
$late=$now->modify('+2 days');$clock->instant=$late;$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$preview=collect((new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock))->preview($t->fresh(),$rule))->keyBy(fn($r)=>$r['action'].$r['term']);
same(['register1','register3','renew1','transfer1'],$preview->keys()->all(),'Aged preview retains every observed sold term');
same(['7.50','26.50','10.40','8.50'],$preview->pluck('new')->all(),'Aged preview shows exact publication prices, preserving retained annual renewal');
foreach($rows($t->fresh()) as $p){
    $observation=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::cost(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($p->catalogue_observation));
    expect($observation->freshUntil<=$clock->now(),'Saved cost has expired at the composed clock');
}
expect($preview->every(fn($r)=>!$r['synced']),'Preview marks every retained cost out of date');
$writer->run($review('override',$t->id),$fields($rule),$late);
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Older cost publication advances revision');same($preview[$key]['new'],PriceCatalogue::retail($p->retail),'Older cost publication matches exact preview retail');}
$agedBinding=$bindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole());
foreach([['register',1],['register',3],['transfer',1]] as [$action,$term]){
    $offer=$ports->loadBatch([new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($agedBinding,['com'],$action,$term)],\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Public)[0][0];
    same(false,$offer->decision->available,'Publishing aged costs cannot authorize acquisition');
    commerceReject(fn()=>$offer->price());
}
$writer->run($review('remove_override',$t->id),[],$late);$clock->instant=$now;
same('Costs are missing. Use Sync prices on the TLD list, then save again. Nothing was changed.',
    SettingsActions::refusal(new DomainException('Costs required before publication.'),'override'),'Plain missing-cost refusal');

// Saving the account default publishes every TLD that follows it and names the ones it could not publish.
$net=Tld::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'tld'=>'net','enabled'=>true,'min_years'=>1,'max_years'=>1,'catalogue_converted'=>true,'registry_fields'=>[],'catalogue_intent'=>'{"pricing_rule":null}']);
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$summary=$writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'50','round_up'=>'whole']),$now);
same(['published'=>2,'failed'=>['Synthetic #'.$t->server_id=>['.net']],'skipped'=>[]],$summary,'Default change publishes followers and names skipped TLDs');
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));$outcome=array_pop($history);$save=array_pop($history);
same('publication_completed',$outcome['phase'],'Publication outcome is durably recorded after the loop');
same($summary,$outcome['outcome'],'Reloaded outcome retains published count and failed TLDs');
same('completed',$save['phase'],'Original local-save audit remains');
foreach(['key','actor_id','reason','action','input'] as $field){same($save[$field],$outcome[$field],'Publication outcome retains review '.$field);}
same($u->id,$outcome['actor_id'],'Outcome actor is the reviewing staff member');
same('9.00',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Follower published with the new default');
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Follower revision advanced');}
same('markup_percent',(new PricingRules)->defaultRule()['mode'],'Default saved');
// A TLD with its own prices does not follow the default.
$writer->run($review('override',$t->id),$fields($rule),$now);$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
same(['published'=>1,'failed'=>['Synthetic #'.$t->server_id=>['.net']],'skipped'=>[]],$writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'40','round_up'=>'none']),$now),'Own-price TLD is not republished');
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Own-price TLD revisions unchanged');
$history=TldFacts::history($t->server_id,$t->id);
same('Default pricing changed: publishing finished',$history[0]['change'],'Newest history row names the publication result');
same('Default pricing changed',$history[1]['change'],'Local-save history row keeps the original change label');
// Saving prices publishes them, so staff without the publish permission cannot save.
$weak=staffUser(array_values(array_diff($permissions,[Review::permission('publish')])));Auth::setUser($weak);
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));$default=(new PricingRules)->defaultRule();
foreach([['override',$t->id,$fields($rule)],['remove_override',$t->id,[]],['default_pricing',null,pricingForm(['mode'=>'markup_percent','markup'=>'10','round_up'=>'none'])]] as [$action,$tld,$data]){
    $denied=$action==='default_pricing'?pricingReview($weak->id,'Synthetic reviewed price change'):new Review($weak->id,$t->server_id,$tld,$action,Review::ticket($weak->id,$t->server_id,$tld,$action,Review::stamp($t->server_id,$tld)),'Synthetic reviewed price change');
    tldRefusal(fn()=>$writer->run($denied,$data,$now),'Current staff permissions required.');
}
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Denied saves publish nothing');
same($default,(new PricingRules)->defaultRule(),'Denied default save changes nothing');
Auth::setUser($u);
// Staff may save pending intent without renewal cost; that intent cannot authorize a sale.
$renew=$rows($t->fresh())['renew1'];$cost=$renew->getRawOriginal('catalogue_observation');Price::whereKey($renew->id)->update(['catalogue_observation'=>null]);
$beforeMissing=array_map(fn(Price $p)=>[$p->revision,$p->retail],$rows($t->fresh()));
$pending=$writer->run($review('override',$t->id),$fields($low),$now);
same([], $pending['failed'],'Missing cost does not block recording pending staff intent');
same($low,\Paymenter\Extensions\Servers\Domains\Catalogue\Pricing::own($t->fresh()),'Pending intent retains exactly the reviewed rule');
$missingAfter=$rows($t->fresh());
same($beforeMissing['renew1'],[$missingAfter['renew1']->revision,$missingAfter['renew1']->retail],'Missing renewal evidence preserves its exact retained revision and retail');
foreach(['register1'=>'5.00','register3'=>'15.00','transfer1'=>'9.00'] as $key=>$retail){
    same([$beforeMissing[$key][0]+1,$retail],[$missingAfter[$key]->revision,PriceCatalogue::retail($missingAfter[$key]->retail)],'Independent observed price is published exactly: '.$key);
}
$missingBinding=$bindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole());
foreach(['register','transfer'] as $action){
    commerceReject(fn()=>$ports->loadBatch([new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($missingBinding,['com'],$action)],\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Public)[0][0]->price());
}
Price::whereKey($renew->id)->update(['catalogue_observation'=>$cost]);
// Only TLDs on this account follow its default, and a TLD that switches to its own prices meanwhile is left alone.
// A TLD left over from an earlier account binding.
$org=Tld::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'tld'=>'org','enabled'=>true,'min_years'=>1,'max_years'=>1,'catalogue_converted'=>true,'registry_fields'=>[],'catalogue_intent'=>'{"pricing_rule":null}']);
Tld::whereKey($org->id)->update(['account_fingerprint'=>'synthetic-earlier-account']);
$writer->run($review('remove_override',$t->id),[],$now);$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
// The switch lands after the reviewed default is saved, before the TLD is published.
$switch=pricingReview($u->id);$armed=true;
Tld::retrieved(function(Tld $tld)use(&$armed,$t,$setFixtureRule,$rule):void{if($armed&&$tld->id===$t->id&&Illuminate\Support\Facades\DB::transactionLevel()===0&&(new PricingRules)->defaultRule()['markup']==='20.00'){$armed=false;$setFixtureRule($tld,$rule);}});
same(['published'=>1,'failed'=>['Synthetic #'.$t->server_id=>['.net']],'skipped'=>[]],$switchResult=$writer->run($switch,pricingForm(['mode'=>'markup_percent','markup'=>'20','round_up'=>'none']),$now),'Other-account and switched TLDs are not published: '.json_encode([$switchResult,$armed,\Paymenter\Extensions\Servers\Domains\Catalogue\Pricing::own($t->fresh())]));
same('markup_percent',(new PricingRules)->defaultRule()['mode'],'Default saved before the switch');same('20.00',(new PricingRules)->defaultRule()['markup'],'New default saved');
expect(!$armed,'Switch happened during the default save');
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Switched TLD revisions unchanged');
// An empty follower set still records its completed outcome.
foreach(Tld::all() as $row){$setFixtureRule($row,$rule);}
$empty=$writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'25','round_up'=>'none']),$now);
same(['published'=>0,'failed'=>[],'skipped'=>[]],$empty,'No followers changes only the default');
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));same($empty,end($history)['outcome'],'Empty publication outcome is retained');
// Losing the final audit cannot undo the already committed default and follower publication.
$setFixtureRule($t->fresh(),null);$auditWrites=0;$failAudit=true;
App\Models\Setting::saving(static function($setting)use(&$auditWrites,&$failAudit):void{
    if($failAudit&&$setting->key===Review::KEY&&++$auditWrites===3){throw new RuntimeException('Synthetic outcome audit failure');}
});
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$failedAudit=$writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'30','round_up'=>'none']),$now);$failAudit=false;
same(['published'=>1,'failed'=>[],'skipped'=>[],'audit_failed'=>true],$failedAudit,'Audit failure honestly preserves the completed publication summary');
same(3,$auditWrites,'Failure was injected into the separate outcome audit, after both accounts recorded the save');
same('30.00',(new PricingRules)->defaultRule()['markup'],'Audit failure leaves the committed default in place');
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Audit failure does not roll back published prices');}
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));same('completed',end($history)['phase'],'Failed outcome audit leaves the original save audit');
$published=new ReflectionMethod(SettingsActions::class,'published');
same("Published new prices for 1 TLD. Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.",$published->invoke(null,$failedAudit),'Warning body gives exact publication results without claiming rollback');
same("No prices changed. Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.",$published->invoke(null,['published'=>0,'failed'=>[],'skipped'=>[],'audit_failed'=>true]),'Empty-result audit warning claims no change');
// The real staff action sends a warning, with the committed publication count, instead of claiming refusal.
// The real page keeps the installed adapter contract; direct projection cases above use their explicit fixture ports.
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));pinStaffClock($now);$auditWrites=0;$failAudit=true;
Livewire\Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class)
    ->mountAction('default_pricing')->assertActionMounted('default_pricing')
    ->setActionData(['mode'=>'markup_percent','markup'=>'35','round_up'=>'none','reason'=>'Record a synthetic publication audit failure'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->warning()
        ->title("Saved, but the result wasn't recorded")
        ->body("Published new prices for 1 TLD. Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved."));
$failAudit=false;same(3,$auditWrites,'Staff warning exercises the failed outcome audit');
same('35.00',(new PricingRules)->defaultRule()['markup'],'Staff warning leaves the new default committed');
// One save covers every account. Each publishes alone, a failure names its account, and saving again publishes what it missed.
$other=$tlds[1];$otherServer=App\Models\Server::findOrFail($other->server_id);$otherServer->update(['name'=>'Second account']);
$setFixtureRule($other->fresh(),null);$revisions=static fn(Tld $tld)=>array_map(fn(Price $p)=>$p->revision,$rows($tld->fresh()));
$missing=$rows($t->fresh())['renew1'];$cost=$missing->getRawOriginal('catalogue_observation');Price::whereKey($missing->id)->update(['catalogue_observation'=>null]);
$before=$revisions($other);$form=pricingForm(['mode'=>'markup_percent','markup'=>'45','round_up'=>'none']);
same(['published'=>1,'failed'=>['Synthetic #'.$t->server_id=>['.com']],'skipped'=>[]],$writer->run(pricingReview($u->id),$form,$now),'A failed account is named and the other still publishes');
foreach($revisions($other) as $key=>$revision){same($before[$key]+1,$revision,'The other account published');}
Price::whereKey($missing->id)->update(['catalogue_observation'=>$cost]);$before=$revisions($other);
same(['published'=>1,'failed'=>[],'skipped'=>[]],$writer->run(pricingReview($u->id),$form,$now),'Saving again publishes only the account that missed out');
same($before,$revisions($other),'An account already on the rule is left alone');
same('8.70',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Saving again repaired the failed account');
// A disabled account reads the same rule, so publish skips it. Its history still records the save, and the dialog says so.
$otherServer->update(['enabled'=>false]);$reach=new ReflectionMethod(SettingsActions::class,'reach');$reachDefault=new ReflectionMethod(SettingsActions::class,'reachDefault');
same(['default'=>1,'own'=>0,'waiting'=>[],'off'=>['Second account']],$reach->invoke(null),'Dialog counts enabled accounts and names the one that is off');
same('1 TLD uses Default pricing.',$reachDefault->invoke(null,$reach->invoke(null)),'Dialog count reads plainly');
$before=$revisions($other);
same(['published'=>1,'failed'=>[],'skipped'=>['Second account']],$writer->run(pricingReview($u->id),pricingForm(['markup'=>'46']),$now),'A disabled account is skipped');
same($before,$revisions($other),'Disabled account prices wait');same('46.00',(new PricingRules)->rule($other->fresh())['markup'],'A disabled account reads the same rule');
foreach([$t->server_id,$other->server_id] as $id){
    $history=Review::historyRows(App\Models\Server::findOrFail($id));$entry=end($history);
    same('Edit default pricing',$entry['action'],'Every account records the save');same('all_accounts',$entry['after']['scope'],'History says the save covers every account');
}
expect(Illuminate\Support\Facades\DB::table(Review::KEYS)->where('server_id',Review::ALL_ACCOUNTS)->where('review_key',$entry['key'])->exists(),'One key is held for the whole business');
$otherServer->update(['enabled'=>true]);
// A business-wide review goes stale when any account changes; a one-account review goes stale when the shared rule changes.
$everyAccount=pricingReview($u->id);$oneAccount=$review('override',$t->id);$intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($other->fresh()->catalogue_intent);$intent['longest']=2;Tld::whereKey($other->id)->update(['catalogue_intent'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json($intent)]);
tldRefusal(fn()=>$writer->run($everyAccount,pricingForm(['markup'=>'47']),$now),'Settings changed. Reload this page.');
$oneAccount=$review('override',$t->id);$writer->run(pricingReview($u->id),pricingForm(['markup'=>'47']),$now);
tldRefusal(fn()=>$writer->run($oneAccount,$fields($rule),$now),'Settings changed. Reload this page.');
// The save above also moved this account's prices, so change only the shared rule here.
$shared=(new PricingRules)->defaultRule();$oneAccount=$review('override',$t->id);saveDefaultRule([...$shared,'markup'=>'48']);
tldRefusal(fn()=>$writer->run($oneAccount,$fields($rule),$now),'Settings changed. Reload this page.');saveDefaultRule($shared);
// A TLD with its own pricing that counts the card fee moves only when the fee really changes.
$writer->run(new Review($u->id,$other->server_id,$other->id,'override',Review::ticket($u->id,$other->server_id,$other->id,'override',Review::stamp($other->server_id,$other->id)),'Synthetic reviewed price change'),
    $fields(['mode'=>'markup_fee_percent','markup'=>'5.00','round_up'=>'none','fixed'=>$rule['fixed']]),$now);
same(1,$reach->invoke(null)['own'],'Dialog counts the TLD with its own pricing');$before=$revisions($other);
same(['published'=>1,'failed'=>[],'skipped'=>[]],$writer->run(pricingReview($u->id),pricingForm(['markup'=>'48']),$now),'A rule change leaves own card-fee pricing alone');
same($before,$revisions($other),'Own card-fee TLD waits for a fee change');$held=$revisions($t);
same(['published'=>1,'failed'=>[],'skipped'=>[]],$writer->run(pricingReview($u->id),pricingForm(['fee_percent'=>'3.00']),$now),'A fee change republishes own card-fee pricing');
foreach($revisions($other) as $key=>$revision){same($before[$key]+1,$revision,'Own card-fee TLD republished');}
same($held,$revisions($t),'A fee change leaves a percentage default alone');
// A fee change it can't follow is named, not counted as unchanged, and saving again once the cost is back moves it.
$gap=$rows($other->fresh())['renew1'];$cost=$gap->getRawOriginal('catalogue_observation');Price::whereKey($gap->id)->update(['catalogue_observation'=>null]);$before=$revisions($other);$form=pricingForm(['fee_percent'=>'3.50']);
same(['published'=>0,'failed'=>['Second account #'.$other->server_id=>['.com']],'skipped'=>[]],$writer->run(pricingReview($u->id),$form,$now),'Own card-fee TLD with a missing cost is named');
same($before,$revisions($other),'A missing cost keeps the old prices');Price::whereKey($gap->id)->update(['catalogue_observation'=>$cost]);
same(['published'=>1,'failed'=>[],'skipped'=>[]],$writer->run(pricingReview($u->id),$form,$now),'Saving again publishes once the cost is back');
foreach($revisions($other) as $key=>$revision){same($before[$key]+1,$revision,'Own card-fee TLD caught up');}$before=$revisions($other);
same(['published'=>0,'failed'=>[],'skipped'=>[]],$writer->run(pricingReview($u->id),$form,$now),'Once caught up it is unchanged');same($before,$revisions($other),'Unchanged prices stay');
// The save needs both rights it replaced, and the two old actions no longer save.
$weak=staffUser(array_values(array_diff($permissions,[Review::permission('card_fee')])));Auth::setUser($weak);
tldRefusal(fn()=>$writer->run(pricingReview($weak->id,'Synthetic reviewed price change'),pricingForm(),$now),'Current staff permissions required.');Auth::setUser($u);
expect(!isset(Staff::permissions()[Review::permission('default_pricing')]),'Default pricing adds no permission of its own');
foreach(['default_rule','card_fee'] as $old){tldRefusal(fn()=>$writer->run(new Review($u->id,$t->server_id,null,$old,Review::ticket($u->id,$t->server_id,null,$old,Review::stamp($t->server_id,null)),'Synthetic reviewed price change'),[],$now),'Unsupported settings action.');}
require __DIR__.'/Support/pricing-upgrade.php';
echo "Saving prices publishes them, matches the preview, keeps below-cost rules and refuses missing costs.\n";$nativeSuccessful=true;
