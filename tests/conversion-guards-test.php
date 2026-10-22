<?php
// Positive baseline + one changed prerequisite per refusal. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/conversion.php';
use DomainsConversion\{Inputs,Planner,Converter,SourceSchema,Host};
use Illuminate\Support\Facades\{DB,Schema};
Host::quiescent('/synthetic/app',$units,['fixture-http.service'=>'/synthetic/app'],[],['# empty']);
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,['omitted.service'=>'/synthetic/app'],[],[]),'All application units inventoried','Application unit omitted');
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,[],[['command'=>'php artisan queue:work','cwd'=>'/synthetic/app']],[]),'Working-directory worker','Application/web process');
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,[],[['command'=>'nginx: master','cwd'=>'/']],[]),'Shared web pools unsupported','Application/web process');
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,[],[],['* * * * * other-user php artisan schedule:run']),'All-user cron inventory','All-user cron must be empty');
$engines=array_fill_keys(['extensions','products','roles','notification_templates','settings','jobs','audits'],'InnoDB');SourceSchema::engines($engines);$engines['settings']='MyISAM';conversionRefuses(fn()=>SourceSchema::engines($engines),'Transactional native tables required','All native cutover tables must use InnoDB');
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,['periodic.service'=>'ExecStart=/usr/bin/run-parts /etc/cron.daily'],[],[]),'Periodic activator fenced','Application unit omitted');
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,[],[],["#!/bin/sh\ncd /synthetic/app && php artisan queue:work"]),'Periodic executable refused','All-user cron must be empty');
$converter=new Converter($evidence,$maintenance);$baseline=$converter->run();$marker=$backup();
$inspected=[];
$inspect=static function(array $args)use(&$inspected):string{$inspected[]=$args;return 'synthetic definition';};
same('synthetic definition',Host::definition('getty@.service',$inspect),'Bare template definition is readable');
same(['cat','--','getty@.service'],$inspected[0],'Bare templates never use show');
foreach(['masked','masked-runtime']as$state){same('UnitFileState='.$state,Host::definition('user@.service',$inspect,$state),'Masked templates have no readable definition or active state');}
same(1,count($inspected),'Masked templates do not call cat or show');
Host::definition('user@1000.service',$inspect);
same('show',$inspected[1][0],'Concrete instances retain effective properties');
Host::quiescent('/synthetic/app',$units,['user@.service'=>'ExecStart=/usr/lib/systemd/systemd --user'],[],[]);
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,['user@1000.service'=>'ExecStart=/usr/lib/systemd/systemd --user'],[],[]),'User manager instances must be masked','Application unit omitted');
$templates=$units;$templates['workers']=['worker@.service'];$inspections=0;
$stateInspect=static function()use(&$inspections):string{$inspections++;return "ActiveState=inactive\nUnitFileState=masked\n";};
conversionRefuses(fn()=>Inputs::maintenance($templates,$stateInspect),'Bare templates have no active state','Explicit systemd unit instance');
same(2,$inspections,'Bare template refused before inspecting that unit');
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,[],[['command'=>'/usr/lib/systemd/systemd --user','cwd'=>'/']],[]),'Active user manager refused','Application/web process');
$webUnits=$units;$webUnits['checkout'][]='php-fpm.service';
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$webUnits,['php-fpm.service'=>"TriggeredBy=generic.socket\n",'generic.socket'=>"Triggers=php-fpm.service\n"],[],[]),'PHP socket restart refused','Application unit omitted');
$rawReservation=DB::table('enom_domains')->where('id',21)->value('reservation_name');DB::table('enom_domains')->where('id',21)->update(['reservation_name'=>null]);conversionRefuses(fn()=>$converter->run(),'Purchased reservation required','Managed purchase has no retained reservation');DB::table('enom_domains')->where('id',21)->update(['reservation_name'=>$rawReservation]);
// Effective instance/drop-in content and timer edges cannot escape the stopped inventory.
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$units,['worker@one.service'=>"WorkingDirectory=/synthetic/app\nTriggeredBy=generic.timer\n",'generic.timer'=>"Triggers=worker@one.service\n"],[],[]),'Effective instance drop-in','Application unit omitted');
$completeUnits=$units;$completeUnits['workers'][]='worker@one.service';
conversionRefuses(fn()=>Host::quiescent('/synthetic/app',$completeUnits,['worker@one.service'=>"WorkingDirectory=/synthetic/app\nTriggeredBy=generic.timer\n",'generic.timer'=>"Triggers=worker@one.service\n"],[],[]),'Trigger timer inventoried','Application unit omitted');
Converter::stageSchema();$migration=DB::table('migrations')->where('migration','2026_10_18_000001_create_domains_foundation')->first();DB::table('migrations')->where('id',$migration->id)->delete();
conversionRefuses(fn()=>$converter->run(),'Target history required','Target migration history incomplete');conversionRefuses(fn()=>Converter::stageSchema(),'Schema staging cannot repair missing history','Target migration history incomplete');DB::table('migrations')->insert((array)$migration);
foreach([['driver'=>'redis','connection'=>DB::getDefaultConnection(),'table'=>'jobs'],['driver'=>'database','connection'=>'other','table'=>'jobs'],['driver'=>'database','connection'=>DB::getDefaultConnection(),'table'=>'other_jobs']]as$bad){config(['queue.connections.database'=>$bad]);conversionRefuses(fn()=>$converter->run(),'Configured queue supported','Only native default database jobs queue');}
config(['queue.connections.database'=>['driver'=>'database','connection'=>DB::getDefaultConnection(),'table'=>'jobs']]);
$markerSetting=$product->settings()->where('key','enom_tld_id')->sole();$markerSetting->value='12';$markerSetting->save();conversionRefuses(fn()=>$converter->run(),'Product ending matches holding','Holding product ending/action mismatch');$markerSetting->value='11';$markerSetting->save();
$action=$product->settings()->where('key','enom_action')->sole();$action->value='transfer';$action->save();expect(in_array(21,$converter->run()['adoption_pending'],true),'Transfer product cannot fabricate registration checkout');$action->value='register';$action->save();
$terminal=(array)DB::table('enom_operations')->where('id',121)->first();$terminal['id']=301;$terminal['operation_key']='management:fixture';$terminal['action']='nameservers';$terminal['invoice_id']=null;$terminal['invoice_item_id']=null;$terminal['confirmed_expires_at']=null;$terminal['policy']=Inputs::encrypt([]);DB::table('enom_operations')->insert($terminal);
expect(count($converter->run()['unsupported_history'])===1,'Unsupported management terminal inventoried');$historyMarker=$backup();conversionRefuses(fn()=>$converter->run(true,$historyMarker),'Unsupported history cannot silently block billing','Unsupported terminal history');DB::table('enom_operations')->where('id',301)->delete();$marker=$backup();
Schema::table('enom_domains',fn($t)=>$t->string('unexpected')->nullable());conversionRefuses(fn()=>$converter->run(),'Exact source schema','Retained Domains schema mismatch');Schema::table('enom_domains',fn($t)=>$t->dropColumn('unexpected'));
DB::table('domains_read_budgets')->insert(['server_id'=>$server->id,'account_fingerprint'=>str_repeat('0',64),'window_at'=>0,'used'=>1,'leases'=>'{}']);conversionRefuses(fn()=>$converter->run(),'Read budgets are target ledger','Target ledger must be empty');DB::table('domains_read_budgets')->delete();
DB::table('domains_runtime_operations')->insert(['operation_id'=>999]);conversionRefuses(fn()=>$converter->run(),'Nonempty target','Target ledger must be empty');DB::table('domains_runtime_operations')->delete();
require dirname(__DIR__).'/scripts/conversion/SourcePins.php';
same(array_column(Inputs::pins(),'revision'),\Paymenter\Extensions\Servers\Domains\Services\ConvertedHoldingProof::SOURCE_REVISIONS,'Source pins and runtime proof revisions agree');
$undeclared=Inputs::pins()[0];$undeclared['revision']=str_repeat('a',40);
conversionRefuses(fn()=>\DomainsConversion\SourcePins::admit($undeclared,Inputs::pins()),'Runtime proof support required','Declare matching runtime proof');
foreach(Inputs::pins()as$pin){
    Inputs::revision($pin['revision']);Inputs::hashes($pin['revision'],$pin['files']);
    $changed=$pin['files'];$changed[array_key_first($changed)]=str_repeat('0',64);
    conversionRefuses(fn()=>Inputs::hashes($pin['revision'],$changed),'Changed byte refuses','Installed source bytes differ');
    \DomainsConversion\SourcePins::admit($pin,Inputs::pins());
    $changed=$pin;foreach($changed['files']as$path=>$hash){if(str_starts_with($path,'database/migrations/')){$changed['files'][$path]=str_repeat('0',64);break;}}
    conversionRefuses(fn()=>\DomainsConversion\SourcePins::admit($changed,Inputs::pins()),'Migration needs explicit support','Changed source schema');
    $changed=$pin;$changed['files']['Enom.php']=str_repeat('0',64);
    conversionRefuses(fn()=>\DomainsConversion\SourcePins::admit($changed,Inputs::pins()),'Settings declarations need explicit support','Changed source schema');
    $changed=$pin;$changed['files']['NewShape/Unexpected.php']=str_repeat('0',64);
    conversionRefuses(fn()=>\DomainsConversion\SourcePins::admit($changed,Inputs::pins()),'New dependency folders refuse by default','Changed source schema');
    $changed=$pin;$changed['files']['Models/Unexpected.php']=str_repeat('0',64);
    conversionRefuses(fn()=>\DomainsConversion\SourcePins::admit($changed,Inputs::pins()),'Model inventory needs explicit support','Changed source schema');
}
Inputs::revision(Inputs::REVISION);conversionRefuses(fn()=>Inputs::revision('wrong'),'Wrong source revision','Accepted source revision required');
conversionRefuses(fn()=>Inputs::source($temporary,'wrong'),'CLI source revision guard','Accepted source revision required');
conversionRefuses(fn()=>Inputs::source($temporary,Inputs::REVISION),'Source bytes mismatch','Installed source bytes differ');
$backend=app(Illuminate\Contracts\Foundation\MaintenanceMode::class);$backend->active=false;conversionRefuses(fn()=>$converter->run(),'Maintenance required','Maintenance and all four');$backend->active=true;
foreach(array_keys($units)as$role){$bad=$units;$bad[$role]=[];conversionRefuses(fn()=>Inputs::maintenance($bad,static fn()=>"ActiveState=inactive\nUnitFileState=masked\n"),'Every role covered','Every stopped role');}
conversionRefuses(fn()=>Inputs::maintenance($units,static fn()=>"ActiveState=active\nUnitFileState=masked\n"),'Active units','inactive and masked');
conversionRefuses(fn()=>Inputs::maintenance($units,static fn()=>"ActiveState=inactive\nUnitFileState=enabled\n"),'Unmasked restart','inactive and masked');
$unrelated=\App\Models\Server::create(['name'=>'Unaccepted alternative','type'=>'server','extension'=>'NameSilo','enabled'=>false]);conversionRefuses(fn()=>$converter->run(),'NameSilo cannot be active or imported','Independent NameSilo server is unsupported');DB::table($unrelated->getTable())->where('id',$unrelated->id)->delete();
$server->settings()->where('key','operations_enabled')->update(['value'=>'1']);conversionRefuses(fn()=>$converter->run(),'Legacy dispatch','Registrar dispatch/billing');$server->settings()->where('key','operations_enabled')->update(['value'=>'0']);
$bad=$evidence;$bad['accounts'][(string)$server->id]['credential_digest']=str_repeat('0',64);conversionRefuses(fn()=>(new Converter($bad,$maintenance))->run(),'Account read must match credentials','Independent accepted provider account');
$bad=$evidence;$bad['domains']['21']['xml']=str_replace('purchased-placeholder.com','other-placeholder.com',$bad['domains']['21']['xml']);conversionRefuses(fn()=>(new Converter($bad,$maintenance))->run(),'Read ownership identity','Positive offline ownership');
$raw=(array)DB::table('enom_domains')->where('id',22)->first();DB::table('enom_domains')->where('id',22)->update(['domain'=>'purchased-placeholder.com','sld'=>'purchased-placeholder','reservation_name'=>null]);conversionRefuses(fn()=>$converter->run(),'Duplicate canonical name','Duplicate or invalid canonical');DB::table('enom_domains')->where('id',22)->update($raw);
$raw=(array)DB::table('enom_domains')->where('id',22)->first();DB::table('enom_domains')->where('id',22)->update(['user_id'=>99999]);conversionRefuses(fn()=>$converter->run(),'Native reference mismatch','Native holding reference');DB::table('enom_domains')->where('id',22)->update($raw);
DB::table('services')->where('id',$services[21]->id)->update(['user_id'=>$staff->id]);conversionRefuses(fn()=>$converter->run(),'Service owner independently matches ledger','Native holding reference');DB::table('services')->where('id',$services[21]->id)->update(['user_id'=>$user->id]);
$savedPolicy=DB::table('enom_domains')->where('id',21)->value('renewal_policy');DB::table('enom_domains')->where('id',21)->update(['renewal_policy'=>Inputs::encrypt(['issue_days'=>14])]);conversionRefuses(fn()=>$converter->run(),'Saved calendar cannot silently change','Saved per-domain billing policy');DB::table('enom_domains')->where('id',21)->update(['renewal_policy'=>$savedPolicy]);
$property=$services[21]->properties()->where('key','domain')->sole();DB::table($property->getTable())->where('id',$property->id)->update(['value'=>'other-placeholder.com']);conversionRefuses(fn()=>$converter->run(),'Native domain property','Native holding configuration/property');DB::table($property->getTable())->where('id',$property->id)->update(['value'=>'purchased-placeholder.com']);
$body=$template->in_app_body;$template->forceFill(['in_app_body'=>'{{ $invoice->id }}'])->save();conversionRefuses(fn()=>$converter->run(),'Unsupported customized template','Retained template requires unsupported');$template->forceFill(['in_app_body'=>"@if(\$invoice){{ \$invoice['id'] }}@endif"])->save();conversionRefuses(fn()=>$converter->run(),'Indexed invoice data unsupported','Retained template requires unsupported');$template->forceFill(['in_app_body'=>'{{ $invoice === true ? \'Pay now\' : \'No invoice\' }}'])->save();conversionRefuses(fn()=>$converter->run(),'Invoice object-only forms unsupported','Retained template requires unsupported');$template->forceFill(['in_app_body'=>$body])->save();
$template->forceFill(['in_app_body'=>'{{ $domain->name }}'])->save();conversionRefuses(fn()=>$converter->run(),'Non-invoice object data unsupported','Retained template requires unsupported');$template->forceFill(['in_app_body'=>"{{ \$domain['name'] }}"])->save();conversionRefuses(fn()=>$converter->run(),'Non-invoice indexed data unsupported','Retained template requires unsupported');$template->forceFill(['in_app_body'=>$body])->save();
$renewRaw=(array)DB::table('enom_operations')->where('id',221)->first();$badCost=Inputs::decrypt($renewRaw['cost']);$badCost['quote']['amount']='8.00';DB::table('enom_operations')->where('id',221)->update(['cost'=>Inputs::encrypt($badCost)]);expect(in_array(21,$converter->run()['adoption_pending'],true),'Renewal quote mismatch grants no billing anchor');DB::table('enom_operations')->where('id',221)->update($renewRaw);
$op=(array)DB::table('enom_operations')->where('id',121)->first();$policy=Inputs::decrypt($op['policy']);$policy['account_fingerprint']=str_repeat('1',64);DB::table('enom_operations')->where('id',121)->update(['policy'=>Inputs::encrypt($policy)]);conversionRefuses(fn()=>$converter->run(),'Contradictory embedded binding','Contradictory embedded source authority');DB::table('enom_operations')->where('id',121)->update($op);
$bad=$op;$bad['policy']=Inputs::encrypt(array_replace(Inputs::decrypt($op['policy']),['billing_managed'=>false]));DB::table('enom_operations')->where('id',121)->update($bad);expect(in_array(21,$converter->run()['adoption_pending'],true),'No purchase policy cannot grant management');DB::table('enom_operations')->where('id',121)->update($op);
$importOp=(array)DB::table('enom_operations')->where('id',122)->first();DB::table('enom_operations')->where('id',122)->delete();
DB::table('invoices')->where('id',$invoices[22]->id)->update(['status'=>'pending']);$payable=$converter->run();expect(count($payable['pending_payable_invoices'])===1,'Dry run inventories payable pending invoices');$payableMarker=$backup();conversionRefuses(fn()=>$converter->run(true,$payableMarker),'Payable pending invoice blocks apply','Legacy holdings have payable invoices');DB::table('invoices')->where('id',$invoices[22]->id)->update(['status'=>'paid']);DB::table('enom_operations')->insert($importOp);
$unpaidInvoice=\App\Models\Invoice::withoutEvents(fn()=>\App\Models\Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'pending','due_at'=>'2028-10-11']));
$unpaid=(array)DB::table('enom_operations')->where('id',221)->first();$unpaid['id']=302;$unpaid['operation_key']='renew:unpaid:fixture';$unpaid['state']='awaiting_payment';$unpaid['invoice_id']=$unpaidInvoice->id;$unpaid['invoice_item_id']=null;DB::table('enom_operations')->insert($unpaid);
$unpaidPlan=$converter->run();same([['domain_id'=>21,'invoice_id'=>$unpaidInvoice->id]],$unpaidPlan['pending_payable_invoices'],'Purchased unresolved renewal payable inventoried');expect(!in_array(21,$unpaidPlan['adoption_pending'],true),'Purchased authority remains distinct from unresolved payable');$unpaidMarker=$backup();conversionRefuses(fn()=>$converter->run(true,$unpaidMarker),'Purchased unpaid legacy renewal blocks apply','Legacy holdings have payable invoices');DB::table('enom_operations')->where('id',302)->delete();DB::table('invoices')->where('id',$unpaidInvoice->id)->delete();$marker=$backup();
same('Explicit safe refusal.',Inputs::refusal(new DomainException('Explicit safe refusal.')),'CLI explains guarded refusal');expect(!str_contains(Inputs::refusal(new RuntimeException('private opaque secret')),'private opaque secret'),'CLI hides unexpected exception values');
$stamp=json_decode(file_get_contents($marker),true);$bad=$stamp;$bad['key_sha256']=str_repeat('0',64);file_put_contents($temporary.'/wrong.json',json_encode($bad));conversionRefuses(fn()=>Inputs::backup($temporary.'/wrong.json',Planner::sourceDigest()),'Backup encryption key','Backup does not match this source/key');
file_put_contents($temporary.'/files.archive','changed archive');touch($temporary.'/files.archive',filemtime($marker));conversionRefuses(fn()=>Inputs::backup($marker,Planner::sourceDigest()),'Backup archive digest','Prior matching backup archives required');$marker=$backup();
$marker=$backup();
// Only exact known serialized jobs with a valid durable mapping may be removed.
$class='Paymenter\\Extensions\\Servers\\Enom\\Jobs\\ObserveDomain';$serialized='O:'.strlen($class).':"'.$class.'":1:{s:8:"domainId";i:21;}';
$payload=json_encode(['data'=>['commandName'=>$class,'command'=>$serialized]]);$job=DB::table('jobs')->insertGetId(['queue'=>'default','payload'=>$payload,'attempts'=>0,'reserved_at'=>null,'available_at'=>0,'created_at'=>0]);
same(1,$converter->run()['queue_removals'],'Known mapped job audited');
DB::table('jobs')->where('id',$job)->update(['payload'=>str_replace('i:21;','i:999999;',$payload)]);conversionRefuses(fn()=>$converter->run(),'Job must have mapped ID','Legacy job has no audited ID mapping');DB::table('jobs')->where('id',$job)->update(['payload'=>$payload]);
DB::table('jobs')->where('id',$job)->update(['reserved_at'=>1]);conversionRefuses(fn()=>$converter->run(),'Reserved legacy job','Unknown/reserved legacy queue job');DB::table('jobs')->where('id',$job)->update(['reserved_at'=>null]);
DB::table('jobs')->where('id',$job)->update(['payload'=>str_replace('ObserveDomain','UnknownJob',$payload)]);conversionRefuses(fn()=>$converter->run(),'Unknown legacy job','Unknown/reserved legacy queue job');DB::table('jobs')->where('id',$job)->update(['payload'=>$payload]);
$native=DB::table('jobs')->insertGetId(['queue'=>'default','payload'=>'{"data":{"commandName":"App\\\\Jobs\\\\NativeJob"}}','attempts'=>0,'reserved_at'=>null,'available_at'=>0,'created_at'=>0]);
$source=Planner::sourceDigest();$converter->run(true,$marker);same(false,DB::table('jobs')->where('id',$job)->exists(),'Only mapped legacy job removed');expect(DB::table('jobs')->where('id',$native)->exists(),'Native queue untouched');same($source,Planner::sourceDigest(),'Retained finance bytes unchanged');
$audit=Inputs::decrypt(DB::table('domains_conversion_runs')->value('audit'));same($payload,$audit['changes']['jobs'][$job]['original']['payload'],'Original job encrypted in audit');
// Partial journal DDL recovery and nonempty target guards use isolated schema transitions only.
expect($converter->run(true,$marker)['already_complete'],'Idempotent completed repeat');
DB::table('domains_domains')->where('id',21)->update(['status'=>'expired']);conversionRefuses(fn()=>$converter->run(true,$marker),'Post-complete target changed','Completed conversion changed');
echo "Conversion schema, maintenance, source/account/backup/reference/queue refusal checks passed.\n";$nativeSuccessful=true;
