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
// Restore uses the routed settings path and keeps row choice distinct from account defaults.
$restore=['enabled'=>true,'register'=>true,'renew'=>true,'transfer'=>true,'min_years'=>1,'max_years'=>3,'verified'=>true];
$run('acceptance',['sell'=>['choices'=>['com'=>'skip']]]);$writes->run($review('tld_settings',$id),$restore,$now);
same(true,OfferState::decode(Tld::find($id)->catalogue_intent)['sell'],'Restore clears an explicit skip under closed defaults');expect($storefront->offer('com','register',1)!==null,'Restored registration is publicly available');
$run('acceptance',['sell'=>['choices'=>['com'=>null]]]);commerceReject(fn()=>$storefront->offer('com','register',1));
$writes->run($review('tld_settings',$id),$restore,$now);same(true,OfferState::decode(Tld::find($id)->catalogue_intent)['sell'],'Restore opts in when the inherited account default is closed');
same(false,OfferState::decode(Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults)['defaultSell'],'Restore does not open the account default');
$run('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>null,'years'=>3,'transfers'=>true]]]);
expect($source->progress($binding->serverId)['state']!=='idle','Staff progress comes from the shared offer queue');
$routed->publish($id,$now);
$run('acceptance',['sell'=>['choices'=>['com'=>'skip']]]);$writes->run($review('tld_settings',$id),$restore,$now);
same(null,OfferState::decode(Tld::find($id)->catalogue_intent)['sell'],'Restore follows open defaults instead of inventing an explicit choice');expect($storefront->offer('com','register',1)!==null,'Open-default restore reaches the public decision');
$run('acceptance',['sell'=>['rule'=>['mode'=>'only','max_cost'=>null,'years'=>1,'transfers'=>false]]]);$routed->publish($id,$now);$writes->run($review('tld_settings',$id),$restore,$now);$wake=0;
// A stopped registration must not hide a currently purchasable transfer.
$writes->run($review('tld_settings',$id),[...$restore,'register'=>false],$now);
commerceReject(fn()=>$storefront->offer('com','register',1));expect($storefront->offer('com','transfer',1)!==null,'Public transfer remains available when registration is stopped');
$transferSnapshot=\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::snapshot(Tld::find($id),null,$source);
same('For sale',$transferSnapshot['sales']['label'],'TLD acquisition status includes available transfers');same(null,$transferSnapshot['sales']['reason'],'Transfer-only TLD has no stopped registration explanation');
$transferPage=[];$transferTable=new \Paymenter\Extensions\Servers\Domains\Admin\Support\SellTable([$binding->serverId=>$binding],1,true,static function()use(&$transferPage){return $transferPage;},false,$source);$transferPage=$transferTable->rows('com')->get()->all();
same('Renewals and transfers only',$transferTable->status($transferPage[0])['label'],'What you sell preserves its transfer-only wording');
$writes->run($review('tld_settings',$id),$restore,$now);
$nativeIds=Product::orderBy('id')->pluck('id')->all();$calls=$reads->calls;
$before=count(Review::historyRows(Server::find($binding->serverId)));
$global=new Review($actor->id,Review::ALL_ACCOUNTS,null,'default_pricing',Review::ticket($actor->id,Review::ALL_ACCOUNTS,null,'default_pricing',StaffCommands::stamp(Review::ALL_ACCOUNTS)),'Synthetic global review');
$writes->run($global,['mode'=>'fixed','fixed_register'=>'20.00','fixed_renew'=>'21.00','fixed_transfer'=>'22.00','fee_percent'=>'3.00','fee_fixed'=>'0.30'],$now);
same(['completed','publication_completed'],array_column(array_values(array_filter(Review::historyRows(Server::find($binding->serverId)),static fn($r)=>$r['action']===Review::ACTIONS['default_pricing'])),'phase'),'Global save and publication outcome use main audit phases');same(0,$wake,'Global pricing publishes directly without scheduling account defaults');
same('$20.00',$storefront->offer('com','register',1)['price'],'Global form delegates to the converted Writer');
same($nativeIds,Product::orderBy('id')->pluck('id')->all(),'Repeated native publication preserves product identities');same($calls,$reads->calls,'Global save never resolves provider');
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
$org=Tld::where('server_id',$binding->serverId)->where('tld','org')->sole();
// Existing rows: fresh .com can widen while expired .org is returned as a business refusal.
$run('seller',['seller'=>(string)$binding->serverId,'register'=>true,'transfer'=>true],$id);
$f=OfferState::facts($org->catalogue_facts);$expired=new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts($f->terms,$f->registryFields,$f->renewalSafe,$f->requiresNameservers,$f->autoRenewOnly,$f->source,$now->modify('-2 days'),$now->modify('-1 day'));
DB::table('domains_tlds')->where('id',$org->id)->update(['catalogue_facts'=>OfferState::encodeFacts($expired)]);
$audits=count(Review::historyRows(Server::find($binding->serverId)));$batch=$run('acceptance',['sell'=>['choices'=>['com'=>'sell','org'=>'sell']]]);
same(['com'],array_keys($batch['rows']),'Valid existing sibling still applies');same(['.org'],$batch['failed'],'Refused existing ending is returned');same('Explicit verified widening required.',$batch['failures']['org'],'Business refusal includes its reason');
same(false,OfferState::decode($org->fresh()->catalogue_intent)['sell'],'Refused existing row keeps its saved choice');expect($storefront->offer('com','register',1)!==null,'Fresh sibling reaches the public offer');same($audits+1,count(Review::historyRows(Server::find($binding->serverId))),'Partial business result consumes one reviewed audit');
// A corrupt later row is an integrity failure: even the earlier valid projection must roll back.
$bad=\Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$binding->serverId)->where('tld','com')->first()->getRawOriginal();unset($bad['id']);$badId=DB::table('domains_prices')->insertGetId([...$bad,'tld'=>'org','account_fingerprint'=>str_repeat('0',64)]);
$state=static fn()=>[StaffCommands::stamp($binding->serverId),count(Review::historyRows(Server::find($binding->serverId))),DB::table(Review::KEYS)->count(),json_encode($native->display(Tld::find($id)),JSON_THROW_ON_ERROR)];$before=$state();
try{$run('acceptance',['sell'=>['choices'=>['com'=>'skip','org'=>'skip']]]);expect(false,'Corrupt projection batch refused');}catch(DomainException $e){same('Price binding changed.',$e->getMessage(),'Later row fails the publication integrity check');}
same($before,$state(),'Later integrity failure rolls back both intents, native projection, audit and consumed ticket');DB::table('domains_prices')->where('id',$badId)->delete();
$d=OfferState::decode(Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
$entries=[];foreach([Tld::find($id),$org] as $t){$entries[]=[\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,$t->tld,new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:false)),OfferState::revision($t,$d,DB::table('domains_sales_policies')->where('tld',$t->tld)->first())];}
DB::table('domains_tlds')->where('id',$org->id)->increment('catalogue_revision');$r=$review('acceptance');$before=StaffCommands::stamp($binding->serverId);$audits=count(Review::historyRows(Server::find($binding->serverId)));
commerceReject(fn()=>$writer->reviewed($r,$entries,static fn()=>StaffCommands::stamp($binding->serverId)));same($before,StaffCommands::stamp($binding->serverId),'Second stale expected revision refuses before either batch write');same($audits,count(Review::historyRows(Server::find($binding->serverId))),'Stale batch has no audit');
// A non-catalogue setting can compose a trusted current-mode guard without being forced through legacy().
$r=new Review($actor->id,$binding->serverId,null,'unpaid_hold',Review::ticket($actor->id,$binding->serverId,null,'unpaid_hold',Review::stamp($binding->serverId,null)),'Synthetic non-catalogue review',static function(){same(1,DB::transactionLevel(),'Non-catalogue guard under review locks');});
$writes->run($r,['confirmed'=>true,'minutes'=>90],$now);same(90,\Paymenter\Extensions\Servers\Domains\Services\UnpaidHoldSetting::minutes($binding->serverId),'Normal settings retain reviewed flow');
$gate=new UpgradeGate;$gate->pause();$before=StaffCommands::stamp($binding->serverId);commerceReject(fn()=>$run('publish',[],$id));same($before,StaffCommands::stamp($binding->serverId),'Paused reviewed publication writes nothing');$gate->resume();
$nativeSuccessful=true;echo "Offer staff reverse policy: ok\n";
