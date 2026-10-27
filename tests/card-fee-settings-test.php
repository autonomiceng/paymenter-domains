<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
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
echo "Account card fee, audit, mode changes and permissions passed.\n";$nativeSuccessful=true;
