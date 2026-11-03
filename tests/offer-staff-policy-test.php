<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture verifies reviewed writes, native identity stability and the durable local queue together.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Product,Server};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection,OfferState,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCatalogue,StaffSettingsReview as Review,StaffSettingsWrites};
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/StaffCommands.php'),'Reverse staff edits need reviewed offer commands');
require __DIR__.'/Support/offer-commerce.php';extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$id=$tlds[0]->id;$wake=$sync=0;
$writer=new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true);
$work=new \Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork($writer,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock),static fn()=>throw new RuntimeException('Staff save must not resolve feed'),static fn()=>0);
$commands=new StaffCommands($writer,$bindings,static function($b)use(&$wake,$work){same(0,DB::transactionLevel(),'Wake outside locks');$wake++;$work->wake($b);},static function($b)use(&$sync,$work){same(0,DB::transactionLevel(),'Sync only schedules outside locks');$sync++;$work->sync($b);});
$source=new StaffCatalogue($ports,$clock,$work,static fn()=>true);
$review=static fn(string $action,?int $tld=null)=>new Review($actor->id,$binding->serverId,$tld,$action,Review::ticket($actor->id,$binding->serverId,$tld,$action,StaffCommands::stamp($binding->serverId,$tld)),'Synthetic reverse review');
$run=static fn(string $action,array $data=[],?int $tld=null)=>$commands->run($review($action,$tld),$data);
$routed=new class($prices) extends NativeCatalogue {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$routed->commands=$commands;
$writes=new class($native,$sellers,$bindings) extends StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$writes->commands=$commands;
$nativeIds=Product::orderBy('id')->pluck('id')->all();$calls=$reads->calls;
$before=count(Review::historyRows(Server::find($binding->serverId)));
$writes->run($review('default_rule'),['mode'=>'fixed','fixed_register'=>'20.00','fixed_renew'=>'21.00','fixed_transfer'=>'22.00'],$now);
same($before+1,count(Review::historyRows(Server::find($binding->serverId))),'Defaults use one reviewed audit');same(1,$wake,'One bounded local wake');expect($source->progress($binding->serverId)['state']!=='idle','Staff progress comes from the shared offer queue');
commerceReject(fn()=>$storefront->offer('com','register',1));
$routed->publish($id,$now);same('$20.00',$storefront->offer('com','register',1)['price'],'Native entry delegates to Writer before a transaction');
$run('card_fee',['fee_percent'=>'3.00','fee_fixed'=>'0.30']);same(2,$wake,'Fee wakes local publication');commerceReject(fn()=>$storefront->offer('com','register',1));
$routed->publish($id,$now);same($nativeIds,Product::orderBy('id')->pluck('id')->all(),'Repeated native publication preserves product identities');same($calls,$reads->calls,'Defaults, fees and republish never resolve provider');
$run('acceptance',['sell'=>['choices'=>['com'=>'skip','missing'=>'sell']]]);
commerceReject(fn()=>$storefront->offer('com','register',1));
same('20.00',$source->matrix(collect([Tld::find($id)]),1)['rows'][0]['prices']['register']['price'],'Stopped prices remain visible to staff');
$result=$run('acceptance',['sell'=>['choices'=>['com'=>null,'missing'=>'sell'],'terms'=>['com'=>['longest'=>3,'transfer'=>false]]]]);
same(['.missing'],$result['failed'],'Bulk keeps explicit missing-row failures');commerceReject(fn()=>$storefront->offer('com','register',1));
same(null,OfferState::decode(Tld::find($id)->catalogue_intent)['sell'],'Use default clears row intent and respects the closed account default');
$run('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>null,'years'=>3,'transfers'=>false]]]);commerceReject(fn()=>$storefront->offer('com','register',1));
$routed->publish($id,$now);same('$20.00',$storefront->offer('com','register',1)['price'],'Republish applies the widened default to independently observed facts');
same(1,count($result['rows']),'Only valid batch row applies');
$before=StaffCommands::stamp($binding->serverId);commerceReject(fn()=>$run('acceptance',['sell'=>['choices'=>array_fill_keys(array_map(static fn($i)=>'ending'.$i,range(1,26)),'skip')]]));same($before,StaffCommands::stamp($binding->serverId),'Oversized batch writes nothing');
config(['domains.hidden_endings'=>['com']]);commerceReject(fn()=>$run('acceptance',['sell'=>['choices'=>['com'=>'sell']]]));config(['domains.hidden_endings'=>[]]);
// A checkbox cannot manufacture missing terms/registry facts when restoring a staff stop.
$run('tld_settings',['enabled'=>true,'max_years'=>3,'register'=>false,'renew'=>true,'transfer'=>false],$id);
$facts=Tld::find($id)->catalogue_facts;DB::table('domains_tlds')->where('id',$id)->update(['catalogue_facts'=>null]);$before=StaffCommands::stamp($binding->serverId);
commerceReject(fn()=>$run('tld_settings',['enabled'=>true,'max_years'=>3,'register'=>true,'renew'=>true,'transfer'=>false,'verified'=>true],$id));same($before,StaffCommands::stamp($binding->serverId),'Verified checkbox cannot invent independently dated facts');
DB::table('domains_tlds')->where('id',$id)->update(['catalogue_facts'=>$facts]);
expect($source->restorable(Tld::find($id))['register'],'TLD restore presenter reads independent facts');
$run('tld_settings',['enabled'=>true,'max_years'=>3,'register'=>true,'renew'=>true,'transfer'=>false,'verified'=>true],$id);
$holding=Domain::create(['server_id'=>$binding->serverId,'registrar_key'=>$binding->registrarKey,'account_fingerprint'=>$binding->fingerprint,'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$retained=Tld::find($id)->catalogue_retained_annual_retail;
commerceReject(fn()=>$run('tld_settings',['enabled'=>false,'max_years'=>1],$id));same($retained,Tld::find($id)->catalogue_retained_annual_retail,'Holder stop refusal preserves annual retail');
$run('tld_settings',['retained_annual_retail'=>'23.00'],$id);same('23.00',\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeEvidence::money(Tld::find($id)->catalogue_retained_annual_retail,2),'Typed retained annual retail edit');
$run('seller',['seller'=>'','register'=>false,'transfer'=>false],$id);commerceReject(fn()=>$storefront->offer('com','register',1));same($binding->serverId,$holding->fresh()->server_id,'Seller edits preserve holder account');same('23.00',\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeEvidence::money(Tld::find($id)->catalogue_retained_annual_retail,2),'Seller edit preserves explicitly retained annual retail');
$run('import');same(1,$sync,'Reviewed import only schedules the future worker');same($calls,$reads->calls,'Import authorization never reads provider');
// Two converted identities share one reviewed batch and one audit, without nested apply().
$copy=Tld::find($id)->getRawOriginal();unset($copy['id']);DB::table('domains_tlds')->insert([...$copy,'tld'=>'org']);
$audits=count(Review::historyRows(Server::find($binding->serverId)));$batch=$run('acceptance',['sell'=>['choices'=>['com'=>'skip','org'=>'skip']]]);
same(2,count($batch['rows']),'One batch applies both valid identities');same($audits+1,count(Review::historyRows(Server::find($binding->serverId))),'Batch consumes one review/audit');
$org=Tld::where('server_id',$binding->serverId)->where('tld','org')->sole();$d=OfferState::decode(Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
$entries=[];foreach([Tld::find($id),$org] as $t){$entries[]=[\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,$t->tld,new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:false)),OfferState::revision($t,$d,DB::table('domains_sales_policies')->where('tld',$t->tld)->first())];}
DB::table('domains_tlds')->where('id',$org->id)->increment('catalogue_revision');$r=$review('acceptance');$before=StaffCommands::stamp($binding->serverId);$audits=count(Review::historyRows(Server::find($binding->serverId)));
commerceReject(fn()=>$writer->reviewed($r,$entries,static fn()=>StaffCommands::stamp($binding->serverId)));same($before,StaffCommands::stamp($binding->serverId),'Second stale expected revision refuses before either batch write');same($audits,count(Review::historyRows(Server::find($binding->serverId))),'Stale batch has no audit');
// A non-catalogue setting can compose a trusted current-mode guard without being forced through legacy().
$r=new Review($actor->id,$binding->serverId,null,'unpaid_hold',Review::ticket($actor->id,$binding->serverId,null,'unpaid_hold',Review::stamp($binding->serverId,null)),'Synthetic non-catalogue review',static function(){same(1,DB::transactionLevel(),'Non-catalogue guard under review locks');});
$writes->run($r,['confirmed'=>true,'minutes'=>90],$now);same(90,\Paymenter\Extensions\Servers\Domains\Services\UnpaidHoldSetting::minutes($binding->serverId),'Normal settings retain reviewed flow');
$gate=new UpgradeGate;$gate->pause();$before=StaffCommands::stamp($binding->serverId);commerceReject(fn()=>$run('publish',[],$id));same($before,StaffCommands::stamp($binding->serverId),'Paused reviewed publication writes nothing');$gate->resume();
$nativeSuccessful=true;echo "Offer staff reverse policy: ok\n";
