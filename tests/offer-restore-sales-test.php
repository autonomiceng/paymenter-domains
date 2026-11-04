<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Facts,FactsObserved,OfferState,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsReview as Review};
tldPanel();pinStaffClock($now);$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
$b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());$ports=CallerPorts::routed();$q=new Query($b,[$t->tld],'register');
// Independent synthetic registry/term evidence, never inferred from bridge prices or a checkbox.
$ports->apply(new FactsObserved($b,$t->tld,new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$ports->readOffers($q,Context::Staff)[0]->revision);
$base=OfferState::decode($t->fresh()->catalogue_intent);$base['transfers']=false;
$set=static function(array $stops,bool $sell=true)use($t,$base):void{DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>json_encode([...$base,'sell'=>$sell,'stops'=>$stops],JSON_THROW_ON_ERROR)]);};
$set(['register']);$facts=$t->fresh()->catalogue_facts;$calls=$reads->calls;
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionVisible('restore_sales');
$page->mountAction('restore_sales')->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded')->assertActionHidden('restore_sales');
$catalogue=StaffCatalogue::routed();$current=$t->fresh();
same([true,true,true,false,3],array_map(fn($key)=>$catalogue->setting($current,$key),['enabled','register','renew','transfer','max_years']),'Restore writes accepted actions and preserves longest term');
same($facts,$current->catalogue_facts,'Restore never manufactures independent facts');same($calls,$reads->calls,'Restore uses saved evidence without provider calls');
$history=Review::historyRows(App\Models\Server::find($t->server_id));expect(count($history)>0,'Restore records reviewed staff history');
// Historical partial stops remain reachable; unsupported transfers do not show a recovery action.
foreach([['register'],['renew'],['register','renew','transfer'],[]] as $stops){
 $set($stops);$page->instance()->getRecord()->refresh();same($stops!==[],$page->instance()->restoreSalesAction()->isVisible(),'Visibility follows active stops');
}
$set([]);$page->instance()->getRecord()->refresh();
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);same(false,$page->instance()->restoreSalesAction()->isVisible(),'Viewer cannot restore');
$policyOnly=staffUser([Staff::VIEW,Staff::POLICY]);Auth::setUser($policyOnly);$set(['register']);$page->instance()->getRecord()->refresh();same(false,$page->instance()->restoreSalesAction()->isVisible(),'Policy alone cannot bypass settings permission');Auth::setUser($actor);
$refusal=static fn(string $message,array $data=[])=>SettingsActions::refusal(new DomainException($message),'tld_settings',$data,(int)$t->server_id,(int)$t->id);
same('This account is set up to sell .com for 1 or 3 years. Pick only those, or change What you sell. Nothing was saved.',$refusal('Explicit verified widening required.',['min_years'=>1,'max_years'=>10,'verified'=>true]),'Refusal names independent account terms');
same('Transfers are not set up for .com on this account. Change sales under What you sell. Turn off Transfers. Nothing was saved.',$refusal('Acquisition acceptance unavailable.',['transfer'=>true]),'Unsupported transfers are named');
same('To turn sales back on, tick the box to confirm this account is set up for them. Check What you sell. Nothing was saved.',$refusal('Explicit restoration required.'),'Confirmation guidance');
same('Customers still own .com domains here, so renewals must stay on. Nothing was saved.',$refusal('Retained holders require annual renewal support.'),'Holder renewal guidance');
same('Prices for .com are missing or out of date. Use Sync prices on the TLD list, then try again. Nothing was saved.',$refusal('Catalogue publication evidence changed.'),'Stale price guidance');
$set([]);$nativeSuccessful=true;
