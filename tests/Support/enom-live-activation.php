<?php
// Installed staff action, encrypted settings and native billing must share the real transaction; no sockets.
// Every provider fact below is synthetic OFFLINE test input, never Live acceptance evidence.
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Data\Action;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{BalanceSchema,EnomLiveActivation,EnomTestRenewal,EnomTestManagement,EnomTestTransfer,HistoryAcceptances,RenewalBillingSettings,StaffSettingsReview as Review};
$activation=['registration'=>true,'renewal'=>true,'management'=>true,'transfer'=>false,'confirmed'=>true];
$beforeWire=count($GLOBALS['enomWire']['forms']);
$unchanged=static fn()=>[Account::where('server_id',$server->id)->sole()->processing_enabled,EnomTestRenewal::accepted($binding),EnomTestManagement::accepted($binding),RenewalBillingSettings::read($server->id),count(Review::historyRows($server->fresh()))];
$beforeActivation=$unchanged();
try{$write('activate',$activation);throw new LogicException('Missing Live evidence must refuse');}catch(DomainException $e){expect(str_contains($e->getMessage(),'Live evidence Missing'),'P35 refusal names the missing provider answer');}
same($beforeActivation,$unchanged(),'Missing evidence grants no authority or billing');
// These are deliberately labeled synthetic and installed only in this socket-free fixture.
$syntheticEvidence=['fingerprint'=>$binding->fingerprint,'site'=>'https://billing.example.com','confirmed_on'=>'2026-10-20','reference'=>'OFFLINE synthetic Enom answer',
    'sha256'=>hash('sha256','OFFLINE synthetic Enom answer'),'currency'=>'USD','complete_history'=>true,
    'history'=>['registration'=>['timezone'=>'America/Los_Angeles','lag'=>60,'retention'=>86400],'renewal'=>['timezone'=>'America/Los_Angeles','lag'=>60,'retention'=>86400]]];
$accountConfig['evidence']=$syntheticEvidence;config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig],'settings.tickets_disabled'=>true,'domains.support_url'=>null]);
DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'finished_at'=>$clock->now()->getTimestamp()]);
$deny(fn()=>$write('activate',$activation));same($beforeActivation,$unchanged(),'Missing support rolls back everything');
config(['domains.support_url'=>'https://support.example.com']);
$review=static fn()=>new Review($staff->id,$server->id,null,'activate',Review::ticket($staff->id,$server->id,null,'activate',Review::stamp($server->id,null)),'Approve synthetic offline spending summary');
$stale=$review();config(['domains.support_url'=>null]);$deny(fn()=>$settings->run($stale,$activation));config(['domains.support_url'=>'https://support.example.com']);
$stale=$review();config(['domains.enom_live.accounts.live-uid.evidence.reference'=>'Changed OFFLINE answer']);$deny(fn()=>$settings->run($stale,$activation));config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);
$stale=$review();config(['app.url'=>'https://copied.example.com']);$deny(fn()=>$settings->run($stale,$activation));config(['app.url'=>'https://billing.example.com']);
$other=staffUser(array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));
$wrongActor=new Review($other->id,$server->id,null,'activate',Review::ticket($staff->id,$server->id,null,'activate',Review::stamp($server->id,null)),'Wrong actor must refuse');
$deny(fn()=>$settings->run($wrongActor,$activation));
config(['domains.enom_live.management'=>false]);$deny(fn()=>$write('activate',$activation));config(['domains.enom_live.management'=>true]);
$server->settings()->updateOrCreate(['key'=>RenewalBillingSettings::DUE],['value'=>'21','type'=>'integer']);
$badBefore=$unchanged();$deny(fn()=>$write('activate',$activation));same($badBefore,$unchanged(),'Bad invoice days leave sending, both billing flags and audit unchanged');
$server->settings()->where('key',RenewalBillingSettings::DUE)->delete();
// Force failure after the existing feature writers run: audit corruption must undo every local write.
$audit=$server->settings()->where('key',Review::KEY)->sole();$audit->encrypted=false;$audit->save();
$deny(fn()=>$write('activate',$activation));
expect(!EnomTestRenewal::accepted($binding)&&!RenewalBillingSettings::anyOn($server->id),'Audit refusal rolls back renewal send and both billing flags');
expect(!$server->settings()->where('key',EnomLiveActivation::KEY)->exists(),'Audit refusal rolls back activation receipt');
$audit->encrypted=true;$audit->save();
$historyBefore=count(Review::historyRows($server->fresh()));
// Exercise the actual one-click + confirm native staff action, not an acceptance-row seed.
$activateAction=Filament\Actions\Testing\TestAction::make('activate_live')->schemaComponent('live_sales','content');
$page=Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->mountAction($activateAction)->assertActionMounted($activateAction);
$page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Live features activated');
expect(EnomLiveActivation::current($binding)&&Account::where('server_id',$server->id)->sole()->processing_enabled,'Supported staff action enables processing');
same([true,true,true],[EnomTestRenewal::accepted($binding),RenewalBillingSettings::read($server->id)['scheduled'],RenewalBillingSettings::read($server->id)['manual']],'One save enables renewal send and both billing flags');
expect(EnomTestManagement::accepted($binding)&&!EnomTestTransfer::accepted($binding),'Management independent; unproved transfers stay Off');
same($historyBefore+1,count(Review::historyRows($server->fresh())),'Activate records one audited transaction');
$late=Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::class;
expect(!$late::available($binding),'Normal Live activation cannot replace late-renewal evidence');
$lateBefore=count(Review::historyRows($server->fresh()));$deny(fn()=>$write('late_renewal',['accept'=>true]));
same($lateBefore,count(Review::historyRows($server->fresh())),'Missing late evidence grants no switch or audit success');
$syntheticLate=['contract'=>$late::CONTRACT,'reference'=>'OFFLINE synthetic late answer','sha256'=>hash('sha256','OFFLINE synthetic late answer'),
    'verified_at'=>'2026-10-20T12:00:00+00:00','expiry_timezone'=>'America/Los_Angeles','deadline'=>'expiry_plus_delete_day','price'=>'getextendinfo_check_renewal'];
config(['domains.enom_live.accounts.live-uid.late_renewal_evidence'=>$syntheticLate]);
expect($late::available($binding)&&!$late::accepted($binding),'Supported synthetic evidence still requires an account switch');
$write('late_renewal',['accept'=>true]);expect($late::accepted($binding),'Live switch binds reviewed synthetic evidence locally');
config(['domains.enom_live.accounts.live-uid.late_renewal_evidence.reference'=>'Different OFFLINE answer']);expect(!$late::accepted($binding),'Changed late evidence revokes the switch');
config(['domains.enom_live.accounts.live-uid.late_renewal_evidence'=>null]);expect(!$late::available($binding),'Removed evidence refuses Live late renewal');
$write('late_renewal',['accept'=>false]);

same($historyBefore+3,count(Review::historyRows($server->fresh())),'Activate and two late switch changes each record one audited transaction');
// A worker stopping after the page opened must not stale an emergency Orders pause.
$pause=new Review($staff->id,$server->id,null,'processing',Review::ticket($staff->id,$server->id,null,'processing',Review::stamp($server->id,null)),'Pause after the worker stops');
DB::table('domains_runtime_turns')->update(['finished_at'=>$clock->now()->getTimestamp()-901]);
$settings->run($pause,['enabled'=>false,'confirmed'=>true]);
expect(!Account::where('server_id',$server->id)->sole()->processing_enabled,'An already mounted Orders pause survives worker liveness changing');
DB::table('domains_runtime_turns')->update(['finished_at'=>$clock->now()->getTimestamp()]);$write('processing',['enabled'=>true,'confirmed'=>true]);
$used=$review();$settings->run($used,$activation);$deny(fn()=>$settings->run($used,$activation));
$settings->run($review(),[...$activation,'management'=>false]);expect(EnomTestRenewal::accepted($binding)&&!EnomTestManagement::accepted($binding),'Activate saves the selected features exactly');
$write('live_management',['accept'=>true]);
$write('live_renewal',['accept'=>false]);expect(!EnomTestRenewal::accepted($binding)&&!RenewalBillingSettings::anyOn($server->id),'Independent Off clears all renewal halves');
expect(EnomTestManagement::accepted($binding),'Renewal Off preserves management');
$write('live_renewal',['accept'=>true]);expect(EnomTestRenewal::accepted($binding),'Independent On uses verified server setup, no checkbox proof');
$write('live_management',['accept'=>false]);expect(EnomTestRenewal::accepted($binding)&&!EnomTestManagement::accepted($binding),'Management Off preserves renewal');
$write('live_management',['accept'=>true]);
config(['domains.enom_live.accounts.live-uid.evidence'=>null]);
expect(!EnomTestRenewal::accepted($binding)&&!EnomTestManagement::accepted($binding)&&!EnomLiveActivation::registrationAccepted($binding),'Removed Live evidence removes authority');
$deny(fn()=>$write('live_renewal',['accept'=>true,'verified'=>true]));
config(['domains.enom_live.management'=>false]);$write('live_management',['accept'=>false]);expect(!EnomTestManagement::stored($binding),'Off works after provider evidence and deployment approval are removed');
config(['domains.enom_live.management'=>true,'domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$write('live_management',['accept'=>true]);
// Updated synthetic evidence needs a fresh review of every selected On feature.
$activationEvidence=array_replace_recursive($syntheticEvidence,['reference'=>'OFFLINE updated Enom answer','sha256'=>hash('sha256','OFFLINE updated Enom answer'),
    'history'=>['registration'=>['lag'=>90],'renewal'=>['lag'=>120]]]);
// Adding management must not revive retained registrations with missing registration readiness.
$write('live_management',['accept'=>false]);
$activationRegister=DB::table('domains_tlds')->where('id',$tld->id)->value('register_enabled');
DB::table('domains_tlds')->where('id',$tld->id)->update(['register_enabled'=>false]);
config(['domains.enom_live.accounts.live-uid.evidence'=>$activationEvidence]);
$activationBefore=[$unchanged(),$server->settings()->where('key',EnomLiveActivation::KEY)->sole()->value];
try{$write('activate',$activation);throw new LogicException('New management must not revive registrations without registration readiness');}
catch(DomainException $e){expect(str_contains($e->getMessage(),'Costs and prices Missing.'),'Retained registration readiness refuses the new feature');}
same($activationBefore,[$unchanged(),$server->settings()->where('key',EnomLiveActivation::KEY)->sole()->value],'Refusal preserves the receipt, saved features, Orders and audit');
DB::table('domains_tlds')->where('id',$tld->id)->update(['register_enabled'=>$activationRegister]);
config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$write('activate',$activation);
// Re-confirming unchanged choices must review updated evidence and both histories.
config(['domains.enom_live.accounts.live-uid.evidence'=>$activationEvidence]);
expect(!EnomLiveActivation::current($binding),'Updated evidence makes the saved receipt stale');
$write('activate',$activation);
expect(EnomLiveActivation::current($binding),'Re-confirming the same choices makes the activation receipt current');
same([90,120],[HistoryAcceptances::load($binding,Action::Register)->lagSeconds,HistoryAcceptances::load($binding,Action::Renew)->lagSeconds],'Re-confirmation saves registration and renewal history from the new evidence');
config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$write('activate',$activation);
// Withdrawal keeps retained features untouched and Orders paused despite missing readiness/evidence.
$write('processing',['enabled'=>false,'confirmed'=>true]);
$activationReceipt=json_decode($server->settings()->where('key',EnomLiveActivation::KEY)->sole()->value,true,8,JSON_THROW_ON_ERROR);
$activationBalance=DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->sole();
DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->update(['read_at'=>$clock->now()->getTimestamp()-86401]);
config(['domains.support_url'=>null,'domains.enom_live.accounts.live-uid.evidence'=>null]);$withdraw=$review();
DB::table('domains_runtime_turns')->update(['finished_at'=>$clock->now()->getTimestamp()-901]);$historyBefore=count(Review::historyRows($server->fresh()));
$settings->run($withdraw,[...$activation,'registration'=>false]);
$withdrawn=json_decode($server->settings()->where('key',EnomLiveActivation::KEY)->sole()->value,true,8,JSON_THROW_ON_ERROR);
same([false,$activationReceipt['evidence'],false,true,true],[$withdrawn['registration'],$withdrawn['evidence'],Account::where('server_id',$server->id)->sole()->processing_enabled,EnomTestRenewal::stored($binding),EnomTestManagement::stored($binding)],'Withdrawal needs no readiness and leaves Orders and other features alone');
same($historyBefore+1,count(Review::historyRows($server->fresh())),'Withdrawal records one audit entry');
$deny(fn()=>$write('activate',[...$activation,'confirmed'=>false]));
$write('activate',['registration'=>false,'renewal'=>false,'management'=>false,'transfer'=>false,'confirmed'=>true]);
expect(!EnomTestRenewal::on($binding)&&!EnomTestManagement::stored($binding)&&!Account::where('server_id',$server->id)->sole()->processing_enabled,'All Off clears retained permissions and billing without resuming Orders');
$deny(fn()=>$write('activate',$activation));
config(['domains.support_url'=>'https://support.example.com','domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);
DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->update(['read_at'=>$activationBalance->read_at]);
$deny(fn()=>$write('activate',$activation)); // Worker readiness is still checked under the review mutex.
DB::table('domains_runtime_turns')->update(['finished_at'=>$clock->now()->getTimestamp()]);$write('activate',$activation);
// An unreadable identity must still permit an audited emergency pause.
$savedIdentity=Account::where('server_id',$server->id)->sole()->getRawOriginal('stable_identity');
DB::table('domains_accounts')->where('server_id',$server->id)->update(['stable_identity'=>'broken ciphertext']);
$write('processing',['enabled'=>false,'confirmed'=>true]);expect(!Account::where('server_id',$server->id)->sole()->processing_enabled,'Unreadable identity still permits audited Off');
DB::table('domains_accounts')->where('server_id',$server->id)->update(['stable_identity'=>$savedIdentity]);$write('processing',['enabled'=>true,'confirmed'=>true]);
same($beforeWire,count($GLOBALS['enomWire']['forms']),'Page, confirmation, refusals and local activation send no provider requests');
