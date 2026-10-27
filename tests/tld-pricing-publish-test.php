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

// Missing or stale costs: nothing is saved and nothing is published.
$late=$now->modify('+2 days');$before=array_map(fn(Price $p)=>[$p->revision,$p->retail],$rows($t->fresh()));$columns=$t->fresh()->only(['use_default_rule','pricing_mode','markup','round_up']);
tldRefusal(fn()=>$writer->run($review('override',$t->id),$fields($rule),$late),'Fresh costs required before publication.');
same($before,array_map(fn(Price $p)=>[$p->revision,$p->retail],$rows($t->fresh())),'Stale costs leave prices alone');
same($columns,$t->fresh()->only(['use_default_rule','pricing_mode','markup','round_up']),'Stale costs leave the rule alone');
same('Costs are missing or out of date. Sync prices on the account page, then save again. Nothing was changed.',
    SettingsActions::refusal(new DomainException('Fresh costs required before publication.'),'override'),'Plain refusal');
expect(collect(PriceCatalogue::preview($t->fresh(),$rule,$late))->every(fn($r)=>!$r['fresh']),'Preview marks stale costs');

// Saving the account default publishes every TLD that follows it and names the ones it could not publish.
$net=Tld::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'tld'=>'net','enabled'=>true,'min_years'=>1,'max_years'=>1,'use_default_rule'=>true]);
$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
$summary=$writer->run($review('default_rule',null),['mode'=>'markup_percent','markup'=>'50','round_up'=>'whole'],$now);
same(['published'=>1,'failed'=>['.net']],$summary,'Default change publishes followers and names skipped TLDs');
same('9.00',$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now)->retail,'Follower published with the new default');
foreach($rows($t->fresh()) as $key=>$p){same($before[$key]+1,$p->revision,'Follower revision advanced');}
same('markup_percent',(new PricingRules)->defaultRule($t->server_id)['mode'],'Default saved');
// A TLD with its own prices does not follow the default.
$writer->run($review('override',$t->id),$fields($rule),$now);$before=array_map(fn(Price $p)=>$p->revision,$rows($t->fresh()));
same(['published'=>0,'failed'=>['.net']],$writer->run($review('default_rule',null),['mode'=>'markup_percent','markup'=>'40','round_up'=>'none'],$now),'Own-price TLD is not republished');
same($before,array_map(fn(Price $p)=>$p->revision,$rows($t->fresh())),'Own-price TLD revisions unchanged');
same('Default pricing changed',TldFacts::history($t->server_id,$t->id)[0]['change'],'Default change in TLD history');
echo "Saving prices publishes them, matches the preview, keeps below-cost rules and refuses stale costs.\n";$nativeSuccessful=true;
