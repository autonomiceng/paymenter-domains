<?php
// Native registrar checklist and activation summary; no activation or registrar call. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,LiveActivationAction,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
extract(commerceFixture());tldPanel();Auth::login(staffUser(array_keys(Staff::permissions())));
$transfers=new ReflectionMethod(AccountChecklist::class,'transfers');
foreach(['enom','namesilo'] as $registrar){foreach(['test','live'] as $environment){
    $account=new Account(['server_id'=>999,'registrar_key'=>$registrar,'environment'=>$environment]);
    $row=$transfers->invoke(null,$account,null,false,static fn()=> 'Off');
    same('Transfers (Experimental)',$row['label'],$registrar.' '.$environment.' uses one canonical staff label');
}}
$a=Account::where('server_id',$tlds[0]->server_id)->sole();$t=$tlds[0];
$intent=OfferState::decode($t->fresh()->catalogue_intent);unset($intent['transfers']);
$defaults=OfferState::decode($a->catalogue_defaults);DB::table('domains_accounts')->where('id',$a->id)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultTransfers'=>true])]);$a->refresh();
DB::table('domains_sales_policies')->where('server_id',$t->server_id)->where('tld',$t->tld)->update(['transfer_enabled'=>false]);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
$before=DB::table('domains_tlds')->where('id',$t->id)->value('catalogue_intent');
same(['.com'],LiveActivationAction::retainedOff($a),'The summary retains seller-policy Off with default transfers On and no intent override');
// Unsold or unassigned catalogue entries are not saved transfer-Off choices.
$notSelling=$t->replicate();$notSelling->tld='unsold.example';$notSelling->catalogue_intent=OfferState::json(['sell'=>false,'transfers'=>true]);$notSelling->save();
$unassigned=$t->replicate();$unassigned->tld='unassigned.example';$unassigned->catalogue_intent=OfferState::json(['sell'=>true,'transfers'=>true]);$unassigned->save();
same(['.com'],LiveActivationAction::retainedOff($a),'Unsold and missing-seller entries do not displace explicit transfer choices');
$summary=['text'=>'Activate selected actions.','help'=>'Readiness is reviewed.','retained_off'=>LiveActivationAction::retainedOff($a),'missing'=>[],'failures'=>[],'costs'=>[]];
$html=view('domains::admin.live-activation',compact('summary'))->render();
expect(str_contains($html,'.com')&&str_contains($html,'TLDs kept Off')&&str_contains($html,'Edit terms'),'Activation renders retained Off and its next step');
same($before,DB::table('domains_tlds')->where('id',$t->id)->value('catalogue_intent'),'Opening activation changes no sales intent');
$action=LiveActivationAction::make($a,'synthetic-stamp');same('Activate selected actions',$action->getModalSubmitActionLabel(),'Confirmation names only selected actions');
same('Activate this account?',$action->getModalHeading(),'Activation heading matches the approved confirmation');
expect(str_contains($html,'New eligible orders can be sent after activation.'),'Activation explains new eligible orders');
$summary['retained_off']=array_map(fn($n)=>'.synthetic'.$n,range(1,40));$bounded=view('domains::admin.live-activation',compact('summary'))->render();
expect(str_contains($bounded,'30 more TLDs kept Off')&&!str_contains($bounded,'.synthetic11 transfers'),'A large retained list is bounded with its next step');
Paymenter\Extensions\Servers\Domains\Models\Domain::create(['server_id'=>$a->server_id,'registrar_key'=>$a->registrar_key,'account_fingerprint'=>$a->account_fingerprint,'domain'=>'kept.example','sld'=>'kept','tld'=>'example','user_id'=>Auth::id(),'service_id'=>9999,'authority_generation'=>1,'status'=>'active','billing_managed'=>true]);
$paused=new ReflectionMethod(AccountChecklist::class,'paused');$cause='Turned off by staff. Review transfer readiness, then turn on when ready.';
$live=clone $a;$live->environment='live';
$row=$paused->invoke(null,$live,['label'=>AccountChecklist::TRANSFER_LABEL,'value'=>'Off','tone'=>null,'cause'=>$cause]);
same(null,$row['tone'],'A deliberate transfer Off stays neutral with managed domains');same($cause,$row['cause'],'No managed-domain attention warning is added');
$switch=new ReflectionMethod(AccountChecklist::class,'writeSwitch');$off=$switch->invoke(null,$a,AccountChecklist::TRANSFER_LABEL,'transfer',false,'Transfer help');same($cause,$off['cause'],'Actual switch Off cause matches the mock');same(null,$off['tone'],'The actual switch keeps Off neutral');$on=$switch->invoke(null,$a,AccountChecklist::TRANSFER_LABEL,'transfer',true,'Transfer help');same('Uses the normal transfer checkout and auth-code flow.',$on['cause'],'Actual switch On cause matches the mock');
$nativeSuccessful=true;echo "Demo C activation UI passed.\n";
