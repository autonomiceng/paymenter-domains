<?php
// Saving prices publishes them; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{PriceCatalogue,PricingRule,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
$f=commerceFixture(true);extract($f);tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);
$review=static fn(string $action,?int $tld)=>new Review($u->id,$t->server_id,$tld,$action,Review::ticket($u->id,$t->server_id,$tld,$action,Review::stamp($t->server_id,$tld)),'Synthetic reviewed price change');
$rows=static fn(Tld $tld)=>PriceCatalogue::sold($tld)->mapWithKeys(fn(Price $p)=>[$p->action.$p->term=>$p])->all();
$fields=static fn(array $rule)=>['mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up'],
    'fixed_register'=>$rule['fixed']['register'],'fixed_renew'=>$rule['fixed']['renew'],'fixed_transfer'=>$rule['fixed']['transfer']];

// Plain formula text.
same('cost + 30%',PricingRule::describe(PricingRule::DEFAULT),'Default formula');
same('cost + $1.00, rounded up to the next $0.50',PricingRule::describe(['mode'=>'markup_amount','markup'=>'1','round_up'=>'half']),'Amount formula');
same('cost + 12.5%, rounded up to the next .99',PricingRule::describe(['mode'=>'markup_percent','markup'=>'12.50','round_up'=>'ending_99']),'Percent formula');
same('$20.00 a year to register, $21.00 to renew and $22.00 to transfer',PricingRule::describe(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'20','renew'=>'21','transfer'=>'22']]),'Own price formula');

// Saving a TLD price publishes it at once; the preview shows exactly what gets published.
$rule=PricingRule::validate(['mode'=>'markup_amount','markup'=>'1.20','round_up'=>'half']);
$preview=PriceCatalogue::preview($t->fresh(),$rule,$now);
same(['register1','register3','renew1','transfer1'],array_map(fn($r)=>$r['action'].$r['term'],$preview),'Preview lists each sold term in order');
same(['7.50','22.50','9.50','8.50'],array_column($preview,'new'),'Preview applies the rule to each term');
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$writer->run($review('override',$t->id),$fields($rule),$now);
$after=$rows($t->fresh());
foreach($preview as $row){
    $p=$after[$row['action'].$row['term']];
    same($before[$row['action'].$row['term']]+1,$p->revision,'Saving publishes a new revision');
    same($row['new'],$p->retail===null?null:PriceCatalogue::retail($p->retail),'Published price matches the preview');
    same($row['hold'],$p->hold_reason,'Published hold matches the preview');
}
same('7.50',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'New price is on sale without a separate publish');
$plan=App\Models\Product::where('slug',\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::slug($t,'register'))->sole()->plans()->where('billing_period',1)->sole();
same('7.50',number_format((float)$plan->prices()->where('currency_code','USD')->sole()->price,2,'.',''),'Store price follows');

// Below-cost rows: register and transfer are held; renew is still billed at the new price and alerts staff.
$low=PricingRule::validate(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'5.00','renew'=>'7.00','transfer'=>'9.00']]);
$preview=collect(PriceCatalogue::preview($t->fresh(),$low,$now))->keyBy(fn($r)=>$r['action'].$r['term']);
same('below_cost',$preview['register1']['hold'],'Preview flags held registration');
same('7.00',$preview['renew1']['new'],'Preview bills the below-cost renewal price');same('below_cost',$preview['renew1']['alert'],'Preview flags billed renewal');
same(null,$preview['transfer1']['hold'],'Transfer above cost is sold');
$writer->run($review('override',$t->id),$fields($low),$now);$after=$rows($t->fresh());
foreach($preview as $key=>$row){same($row['new'],$after[$key]->retail===null?null:PriceCatalogue::retail($after[$key]->retail),'Below-cost publish matches the preview');same($row['hold'],$after[$key]->hold_reason,'Below-cost hold matches');}
same('7.00',$prices->renewal($t->server_id,$t->account_fingerprint,'com','USD',$now)->retail,'Renewal billed at the below-cost price');
commerceReject(fn()=>$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now));

// Choosing the account default publishes the default prices.
$writer->run($review('remove_override',$t->id),[],$now);
expect($t->fresh()->use_default_rule,'TLD follows the account default');
same('7.80',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Default price published at once');
same('Switched to the account default',TldFacts::history($t->server_id,$t->id)[0]['change'],'History names the change');

// Older saved costs still publish exactly what the preview shows.
$late=$now->modify('+2 days');$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$preview=collect(PriceCatalogue::preview($t->fresh(),$rule,$late))->keyBy(fn($r)=>$r['action'].$r['term']);
expect($preview->isNotEmpty()&&$preview->every(fn($r)=>$r['synced']),'Preview keeps older synced costs');
$writer->run($review('override',$t->id),$fields($rule),$late);
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Older cost publication advances revision');same($preview[$key]['new'],PriceCatalogue::retail($p->retail),'Older cost publication matches exact preview retail');}
$writer->run($review('remove_override',$t->id),[],$late);
same('Costs are missing. Use Sync prices on the TLD list, then save again. Nothing was changed.',
    SettingsActions::refusal(new DomainException('Costs required before publication.'),'override'),'Plain missing-cost refusal');

// Saving the account default publishes every TLD that follows it and names the ones it could not publish.
$net=Tld::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'tld'=>'net','enabled'=>true,'min_years'=>1,'max_years'=>1,'use_default_rule'=>true]);
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$defaultReview=$review('default_rule',null);
$summary=$writer->run($defaultReview,['mode'=>'markup_percent','markup'=>'50','round_up'=>'whole'],$now);
same(['published'=>1,'failed'=>['.net']],$summary,'Default change publishes followers and names skipped TLDs');
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));$outcome=array_pop($history);$save=array_pop($history);
same('publication_completed',$outcome['phase'],'Publication outcome is durably recorded after the loop');
same($summary,$outcome['outcome'],'Reloaded outcome retains published count and failed TLDs');
same('completed',$save['phase'],'Original local-save audit remains');
foreach(['key','actor_id','reason','action','input'] as $field){same($save[$field],$outcome[$field],'Publication outcome retains review '.$field);}
same($u->id,$outcome['actor_id'],'Outcome actor is the reviewing staff member');
same('9.00',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Follower published with the new default');
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Follower revision advanced');}
same('markup_percent',(new PricingRules)->defaultRule($t->server_id)['mode'],'Default saved');
// A TLD with its own prices does not follow the default.
$writer->run($review('override',$t->id),$fields($rule),$now);$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
same(['published'=>0,'failed'=>['.net']],$writer->run($review('default_rule',null),['mode'=>'markup_percent','markup'=>'40','round_up'=>'none'],$now),'Own-price TLD is not republished');
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Own-price TLD revisions unchanged');
$history=TldFacts::history($t->server_id,$t->id);
same('Default pricing changed: publishing finished',$history[0]['change'],'Newest history row names the publication result');
same('Default pricing changed',$history[1]['change'],'Local-save history row keeps the original change label');
// Saving prices publishes them, so staff without the publish permission cannot save.
$weak=staffUser(array_values(array_diff($permissions,[Review::permission('publish')])));Auth::setUser($weak);
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));$default=(new PricingRules)->defaultRule($t->server_id);
foreach([['override',$t->id,$fields($rule)],['remove_override',$t->id,[]],['default_rule',null,['mode'=>'markup_percent','markup'=>'10','round_up'=>'none']]] as [$action,$tld,$data]){
    $denied=new Review($weak->id,$t->server_id,$tld,$action,Review::ticket($weak->id,$t->server_id,$tld,$action,Review::stamp($t->server_id,$tld)),'Synthetic reviewed price change');
    tldRefusal(fn()=>$writer->run($denied,$data,$now),'Current staff permissions required.');
}
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Denied saves publish nothing');
same($default,(new PricingRules)->defaultRule($t->server_id),'Denied default save changes nothing');
Auth::setUser($u);
// A sold term without a one-year renewal cost refuses the save.
$renew=$rows($t->fresh())['renew1'];$cost=$renew->wholesale;Price::whereKey($renew->id)->update(['wholesale'=>null]);
$beforeMissing=array_map(fn(Price $p)=>[$p->revision,$p->retail],$rows($t->fresh()));
$columns=$t->fresh()->only(['use_default_rule','pricing_mode','markup','round_up']);
tldRefusal(fn()=>$writer->run($review('override',$t->id),$fields($low),$now),'Costs required before publication.');
same($beforeMissing,array_map(fn(Price $p)=>[$p->revision,$p->retail],$rows($t->fresh())),'Missing renewal cost preserves exact revisions and retail');
same($columns,$t->fresh()->only(['use_default_rule','pricing_mode','markup','round_up']),'Missing renewal cost leaves the rule alone');
Price::whereKey($renew->id)->update(['wholesale'=>$cost]);
// Only TLDs on this account follow its default, and a TLD that switches to its own prices meanwhile is left alone.
// A TLD left over from an earlier account binding.
$org=Tld::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'tld'=>'org','enabled'=>true,'min_years'=>1,'max_years'=>1,'use_default_rule'=>true]);
Tld::whereKey($org->id)->update(['account_fingerprint'=>'synthetic-earlier-account']);
$writer->run($review('remove_override',$t->id),[],$now);$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
// The switch lands after the reviewed default is saved, before the TLD is published.
$switch=$review('default_rule',null);$armed=true;
Tld::retrieved(function(Tld $tld)use(&$armed,$t):void{if($armed&&$tld->id===$t->id&&Illuminate\Support\Facades\DB::transactionLevel()===0){$armed=false;Tld::whereKey($t->id)->update(['use_default_rule'=>false]);}});
same(['published'=>0,'failed'=>['.net']],$writer->run($switch,['mode'=>'markup_percent','markup'=>'20','round_up'=>'none'],$now),'Other-account and switched TLDs are not published');
same('markup_percent',(new PricingRules)->defaultRule($t->server_id)['mode'],'Default saved before the switch');same('20.00',(new PricingRules)->defaultRule($t->server_id)['markup'],'New default saved');
expect(!$armed,'Switch happened during the default save');
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Switched TLD revisions unchanged');
// An empty follower set still records its completed outcome.
Tld::where('server_id',$t->server_id)->update(['use_default_rule'=>false]);
$empty=$writer->run($review('default_rule',null),['mode'=>'markup_percent','markup'=>'25','round_up'=>'none'],$now);
same(['published'=>0,'failed'=>[]],$empty,'No followers changes only the default');
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));same($empty,end($history)['outcome'],'Empty publication outcome is retained');
// Losing the final audit cannot undo the already committed default and follower publication.
Tld::whereKey($t->id)->update(['use_default_rule'=>true]);$auditWrites=0;$failAudit=true;
App\Models\Setting::saving(static function($setting)use(&$auditWrites,&$failAudit):void{
    if($failAudit&&$setting->key===Review::KEY&&++$auditWrites===2){throw new RuntimeException('Synthetic outcome audit failure');}
});
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$failedAudit=$writer->run($review('default_rule',null),['mode'=>'markup_percent','markup'=>'30','round_up'=>'none'],$now);$failAudit=false;
same(['published'=>1,'failed'=>[],'audit_failed'=>true],$failedAudit,'Audit failure honestly preserves the completed publication summary');
same(2,$auditWrites,'Failure was injected into the separate outcome audit');
same('30.00',(new PricingRules)->defaultRule($t->server_id)['markup'],'Audit failure leaves the committed default in place');
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Audit failure does not roll back published prices');}
$history=Review::historyRows(App\Models\Server::findOrFail($t->server_id));same('completed',end($history)['phase'],'Failed outcome audit leaves the original save audit');
$published=new ReflectionMethod(SettingsActions::class,'published');
same("Published new prices for 1 TLD. Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.",$published->invoke(null,$failedAudit,'default_rule'),'Warning body gives exact publication results without claiming rollback');
same("Card fee saved. No TLDs count the card fee in their prices, so no prices changed. Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.",$published->invoke(null,['published'=>0,'failed'=>[],'audit_failed'=>true],'card_fee'),'Card-fee audit warning retains its own empty-result wording without claiming a default changed');
// The real staff action sends a warning, with the committed publication count, instead of claiming refusal.
app()->instance(StaffSettingsWrites::class,$writer);pinStaffClock($now);$auditWrites=0;$failAudit=true;
Livewire\Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$t->server_id])
    ->mountAction(setupAction('default_rule',$t->server_id))->assertActionMounted(setupAction('default_rule',$t->server_id))
    ->setActionData(['mode'=>'markup_percent','markup'=>'35','round_up'=>'none','reason'=>'Record a synthetic publication audit failure'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->warning()
        ->title("Saved, but the result wasn't recorded")
        ->body("Published new prices for 1 TLD. Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved."));
$failAudit=false;same(2,$auditWrites,'Staff warning exercises the failed outcome audit');
same('35.00',(new PricingRules)->defaultRule($t->server_id)['markup'],'Staff warning leaves the new default committed');
echo "Saving prices publishes them, matches the preview, keeps below-cost rules and refuses missing costs.\n";$nativeSuccessful=true;
