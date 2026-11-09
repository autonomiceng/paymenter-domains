<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
require __DIR__.'/Support/site-dns-default.php';
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,WatchName,WatchProductMap,WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DnsTemplateUsage,SiteDnsDefault,StaffWatchWrites as Writes};
$make=fn(string $name)=>DB::transaction(fn()=>WatchProfile::create(['name'=>$name,'enabled'=>true,
    'nameservers'=>['ns1.spare.test','ns2.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],
    'edited_by'=>$manager->id,'reason'=>'Synthetic template setup']));
$write=function(WatchProfile $row,string $action)use($manager):void {
    (new Writes())->run($manager,'profile',(int)$row->id,$action,
        Writes::ticket((int)$manager->id,'profile',(int)$row->id,(int)$row->revision,$action),'Synthetic template change');
};
$errors=[];
$check=function(string $name,Closure $run)use(&$errors):void{try{$run();echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();}};
$check('Turn off and delete an unused On template',function()use($make,$write,$manager):void{
    $row=$make('Unused template');
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);
    $page->assertActionVisible('disable');
    expect($page->instance()->getAction('disable')->isConfirmationRequired(),'Turn off needs confirmation');
    expect($page->instance()->getAction('delete')->isConfirmationRequired(),'Delete needs confirmation');
    $page->callAction('disable')->assertNotified('DNS template turned off')->assertNoRedirect();
    same(false,$row->fresh()->enabled,'Turn off persists');
    $page->assertActionVisible('enable')->assertActionHidden('disable');
    $page->callAction('enable')->assertNotified('DNS template turned on')->assertNoRedirect();
    same(true,$row->fresh()->enabled,'Turn on persists in the same page');
    expect(str_contains($page->html(),'aria-checked="true"'),'The rendered switch updates without reload');
    $stale=$row->fresh();$write($stale,'disable');
    $before=WatchAudit::count();
    try{$write($stale,'delete');throw new RuntimeException('Stale deletion was accepted');}
    catch(DomainException $e){expect(str_contains($e->getMessage(),'changed'),'Stale deletion says to reload');}
    same($before,WatchAudit::count(),'Stale deletion has no audit');
    $write($row->fresh(),'enable');
    Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->callAction('delete')->assertNotified('DNS template deleted');
    same(null,WatchProfile::find($row->id),'Unused On template deleted');
    same(1,WatchAudit::where('subject_id',$row->id)->where('event','dns_profile_deleted')->count(),'Deletion retains one audit');
});
$check('Dependencies refuse turn off and delete with a reason',function()use($profile,$watch,$map,$make,$write,$manager):void{
    $row=$make('New domains template');siteDnsFixture($row->id);
    foreach(['disable','delete']as$action){
        $before=WatchAudit::count();$error=null;
        try{$write($row,$action);}catch(DomainException $e){$error=$e->getMessage();}
        expect($error!==null && str_contains($error,'new domains'),'Default refuses '.$action.' with the reason');
        same($before,WatchAudit::count(),'Default refusal rolls back audit');
        same(true,$row->fresh()->enabled,'Default stays on');
    }
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);
    foreach(['disable','delete']as$action){$page->assertActionVisible($action);same('true',$page->instance()->getAction($action)->getExtraAttributes()['aria-disabled']??null,'Blocked control stays visible');}
    $reason='Choose another template for new domains first.';
    $page->assertSee($reason);
    $dom=new DOMDocument();@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
    $buttons=$xpath->query('//button[normalize-space(.)="Delete"]');
    same(1,$buttons->length,'One blocked Delete stays visible');$button=$buttons->item(0);
    expect($button instanceof DOMElement,'Blocked Delete is a rendered button');
    expect(str_contains(' '.$button->getAttribute('class').' ',' domains-blocked '),'Blocked Delete is visibly dimmed');
    same('true',$button->getAttribute('aria-disabled'),'Blocked Delete says it cannot run');
    expect(str_contains($button->getAttribute('x-tooltip'),$reason),'Blocked Delete explains the reason in its tooltip');
    expect(str_contains($button->getAttribute('aria-label'),$reason),'Blocked Delete gives screen readers the reason');
    expect(!$button->hasAttribute('disabled')&&!$button->hasAttribute('wire:click'),'Blocked Delete stays focusable without a silent write handler');
    same($reason,$page->instance()->getAction('delete')->getTooltip(),'Delete uses the shared blocked-control tooltip');
    $switch=$xpath->query('//button[@role="switch"]')->item(0);
    expect($switch instanceof DOMElement && str_contains(' '.$switch->getAttribute('class').' ',' domains-blocked '),'The blocked monitoring switch is also dimmed');
    siteDnsFixture(null);
    // Existing watch, managed-domain and product assignments each block independently.
    $cases=[['domains_watch_names','ns_profile_id','id',$watch->id],['domains_managed_public_config','profile_id','domain_id',900001],['domains_watch_product_maps','expected_ns_profile_id','id',$map->id]];
    foreach($cases as[$table,$field,$key,$id]){
        if($table==='domains_managed_public_config'){DB::table($table)->insert(['domain_id'=>$id,'user_id'=>$manager->id,'profile_id'=>$row->id,'notice_preferences'=>'{}']);}
        else{DB::table($table)->where($key,$id)->update([$field=>$row->id]);}
        foreach(['disable','delete']as$action){
            $error=null;try{$write($row,$action);}catch(DomainException $e){$error=$e->getMessage();}
            expect($error!==null && str_contains($error,'assigned'),'Assignment refuses '.$action.' for '.$table);
            same(true,$row->fresh()->enabled,'Assigned template stays on');
            $error=null;try{DB::transaction(fn()=>$row->fresh()->update(['enabled'=>false,'revision'=>$row->revision+1]));}catch(DomainException $e){$error=$e->getMessage();}
            expect($error!==null && str_contains($error,'assigned'),'Model save keeps the same dependency guard');
        }
        if($table==='domains_managed_public_config'){DB::table($table)->where($key,$id)->delete();}
        else{DB::table($table)->where($key,$id)->update([$field=>null]);}
    }
    // The confirmation was opened before another domain selected the template.
    $mounted=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->mountAction('delete');
    DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$row->id]);
    $mounted->callMountedAction()->assertNotified('This template is assigned to domains. Choose another template for them first.')->assertNotNotified('DNS template deleted');
    expect(WatchProfile::find($row->id)!==null,'A new dependency prevents a mounted deletion');
    DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$profile->id]);
});
$check('One New domains use choice is shown',function()use($profile,$make,$manager):void{
    $other=$make('Other enabled template');siteDnsFixture($profile->id);
    $list=Livewire::actingAs($manager)->test(ListConfiguration::class)->assertSee('New domains use')->assertSee($profile->name);
    $column=$list->instance()->getTable()->getColumn('name');
    same('Used for new domains',$column->record($profile)->getDescriptionBelow(),'Chosen template marked');
    same(null,$column->record($other)->getDescriptionBelow(),'Other On template is not marked');
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$other->id])->assertSee('New domains use')->assertSee($profile->name)->assertDontSee('Site DNS default');
});
$check('Add and edit show done messages without losing page state',function()use($make,$manager):void{
    $list=Livewire::actingAs($manager)->test(ListConfiguration::class);
    $list->callAction('save',['name'=>'Added DNS template','nameservers'=>'ns1.added.test'."\n".'ns2.added.test'])
        ->assertNotified('DNS template added')->assertNoRedirect();
    $row=WatchProfile::where('name','Added DNS template')->sole();
    $list->assertCanSeeTableRecords([$row]);
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);
    $page->callAction('save',['name'=>'Edited DNS template','nameservers'=>'ns1.edited.test'."\n".'ns2.edited.test'])
        ->assertNotified('DNS template saved')->assertNoRedirect();
    same('Edited DNS template',$page->instance()->getTitle(),'Title updates after edit');
    $page->assertSee('ns1.edited.test');
});
$check('Read-only staff cannot change templates',function()use($make):void{
    $row=$make('Permission template');$viewer=staffUser([Writes::VIEW]);$error=null;
    try{(new Writes())->run($viewer,'profile',(int)$row->id,'delete',Writes::ticket((int)$viewer->id,'profile',(int)$row->id,(int)$row->revision,'delete'),'Synthetic permission refusal');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$error=$e->getStatusCode();}
    same(403,$error,'Deletion requires manage permission');expect($row->fresh()!==null,'Refused deletion preserves the template');
});
$check('Default label lookup is constant per table render',function()use($profile,$make,$manager):void{
 $count=0;DB::listen(static function($q)use(&$count):void{if(str_contains($q->sql,'"settings"')&&in_array(SiteDnsDefault::KEY,$q->bindings,true)){$count++;}});
 foreach([1,25,100]as$size){$page=Livewire::actingAs($manager)->test(ListConfiguration::class);$column=$page->instance()->getTable()->getColumn('name');$count=0;
  for($i=0;$i<$size;$i++){$column->record($profile)->getDescriptionBelow();}expect($count<=1,'Default lookup stays constant for '.$size.' rows');}
 siteDnsFixture(null);$page=Livewire::actingAs($manager)->test(ListConfiguration::class);$column=$page->instance()->getTable()->getColumn('name');$count=0;
 for($i=0;$i<100;$i++){same(null,$column->record($profile)->getDescriptionBelow(),'Missing default has no row marker');}expect($count<=1,'Missing default is cached within this render');
 siteDnsFixture($profile->id);$page=Livewire::actingAs($manager)->test(ListConfiguration::class);same('Used for new domains',$page->instance()->getTable()->getColumn('name')->record($profile)->getDescriptionBelow(),'Next request sees changed default');
});
$check('Paused and linked assignments refuse independently',function()use($watch,$make,$write):void{
    $row=$make('Watch state template');siteDnsFixture(null);$held=DB::table('domains_watch_names')->where('id',$watch->id)->first();
    try{
        foreach(['paused'=>'This template is assigned to domains. Choose another template for them first.','linked'=>'This template is assigned to retained domain records. Keep it for their history.','retired'=>null]as$state=>$reason){
            DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>$state,'ns_profile_id'=>$row->id]);
            same($reason,DnsTemplateUsage::refusal((int)$row->id),ucfirst($state).' watch has its own reason');
            if($reason===null){continue;}
            foreach(['disable','delete']as$action){
                $error=null;try{$write($row->fresh(),$action);}catch(DomainException $e){$error=$e->getMessage();}
                same($reason,$error,ucfirst($state).' watch refuses '.$action);
                expect(WatchProfile::find($row->id)?->enabled===true,ucfirst($state).' watch keeps the template on after '.$action);
            }
        }
    }finally{DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>$held->state,'ns_profile_id'=>$held->ns_profile_id]);}
});
$check('Turn off and delete read assignments committed while they wait for the template lock',function()use($watch,$map,$make,$write,$manager):void{
    siteDnsFixture(null);$setting=(int)\App\Models\Setting::where('key',SiteDnsDefault::KEY)->whereNull('settingable_type')->value('id');
    // SQLite has no repeatable-read views. Model the write's older view: the assignment commits when the template
    // row lock is taken, and from then on only locking reads see it.
    $view=(object)['arm'=>null,'table'=>null,'key'=>null,'late'=>null];
    WatchProfile::addGlobalScope('late_assignment',static function($q)use($view):void{
        if($view->arm && $q->getQuery()->lock){$arm=$view->arm;$view->arm=null;[$view->table,$view->key,$view->late]=$arm();}
    });
    $hide=static fn(string $table)=>static function($q)use($view,$table):void{
        if($view->late!==null && $view->table===$table && !$q->getQuery()->lock){$q->where($table.'.'.$view->key,'<>',$view->late);}
    };
    foreach([WatchName::class=>'domains_watch_names',ManagedPublicConfig::class=>'domains_managed_public_config',WatchProductMap::class=>'domains_watch_product_maps',\App\Models\Setting::class=>'settings']as$model=>$table){$model::addGlobalScope('late_assignment',$hide($table));}
    $held=DB::table('domains_watch_names')->where('id',$watch->id)->first();$heldMap=DB::table('domains_watch_product_maps')->where('id',$map->id)->value('expected_ns_profile_id');
    $cases=[
        'active watch'=>fn($id)=>[DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'active','ns_profile_id'=>$id]),['domains_watch_names','id',$watch->id]][1],
        'paused watch'=>fn($id)=>[DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused','ns_profile_id'=>$id]),['domains_watch_names','id',$watch->id]][1],
        'linked watch'=>fn($id)=>[DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'linked','ns_profile_id'=>$id]),['domains_watch_names','id',$watch->id]][1],
        'registered domain'=>fn($id)=>[DB::table('domains_managed_public_config')->insert(['domain_id'=>900002,'user_id'=>$manager->id,'profile_id'=>$id,'notice_preferences'=>'{}']),['domains_managed_public_config','domain_id',900002]][1],
        'hosting product'=>fn($id)=>[DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['expected_ns_profile_id'=>$id]),['domains_watch_product_maps','id',$map->id]][1],
        'site default'=>fn($id)=>[DB::table('settings')->where('id',$setting)->update(['value'=>(string)$id]),['settings','id',$setting]][1],
    ];
    try{
        foreach($cases as$case=>$assign){
            foreach(['disable','delete']as$action){
                $row=$make('Late '.$case.' '.$action);$before=WatchAudit::where('subject_type','dns_profile')->where('subject_id',$row->id)->count();
                $view->arm=fn()=>$assign((int)$row->id);$error=null;
                try{$write($row,$action);}catch(DomainException $e){$error=$e->getMessage();}
                finally{$view->arm=null;$view->late=null;}
                expect($view->table!==null,'The '.$case.' assignment committed during the '.$action.' lock wait');$view->table=null;
                expect($error!==null,'A '.$case.' assignment committed during the wait refuses '.$action);
                expect(WatchProfile::find($row->id)?->enabled===true,'The template stays on for the late '.$case.' after '.$action);
                same($before,WatchAudit::where('subject_type','dns_profile')->where('subject_id',$row->id)->count(),'The refused '.$action.' adds no audit for the late '.$case);
                DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>$held->state,'ns_profile_id'=>$held->ns_profile_id]);
                DB::table('domains_managed_public_config')->where('domain_id',900002)->delete();
                DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['expected_ns_profile_id'=>$heldMap]);siteDnsFixture(null);
            }
        }
    }finally{$view->arm=null;$view->late=null;}
});
$check('Unusable and duplicate saved defaults keep their template and name the recovery',function()use($make,$write,$manager):void{
    $row=$make('Retained default');siteDnsFixture($row->id);$stored=DB::table('domains_watch_profiles')->where('id',$row->id)->value('nameservers');
    $marker=fn()=>Livewire::actingAs($manager)->test(ListConfiguration::class)->instance()->getTable()->getColumn('name')->record($row)->getDescriptionBelow();
    $retained='This template is saved for new domains but cannot be used. Choose another template for new domains first.';
    try{
        DB::table('domains_watch_profiles')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);
        same($retained,DnsTemplateUsage::refusal((int)$row->id),'An unreadable saved default is not called the live default');
        same('Saved for new domains but cannot be used',$marker(),'The list marks the unusable saved default it protects');
        DB::table('domains_watch_profiles')->where('id',$row->id)->update(['nameservers'=>$stored]);
        same('Used for new domains',$marker(),'A healthy default keeps its marker');
        // Settings writes refuse a duplicate, so Change site DNS default cannot be the recovery.
        $duplicate=DB::table('settings')->insertGetId(['key'=>SiteDnsDefault::KEY,'value'=>'opaque','type'=>'string','encrypted'=>true,'settingable_type'=>null,'settingable_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
        $admin='The site DNS default is saved more than once. Ask your site admin to remove the extra setting.';
        foreach(['disable','delete']as$action){
            $error=null;try{$write($row->fresh(),$action);}catch(DomainException $e){$error=$e->getMessage();}
            same($admin,$error,'A duplicate saved default refuses '.$action.' with administrator recovery');
            expect(WatchProfile::find($row->id)?->enabled===true,'The duplicate reference keeps the template after '.$action);
        }
        same($admin,SiteDnsDefault::refusal(),'Site settings give the same administrator recovery');
        same('Saved for new domains more than once',$marker(),'The list marks a duplicated saved default');
        DB::table('settings')->where('id',$duplicate)->delete();
    }finally{DB::table('domains_watch_profiles')->where('id',$row->id)->update(['nameservers'=>$stored]);siteDnsFixture(null);}
});
$check('Dependency reads stay bounded to the template and use its indexes',function()use($make,$manager):void{
    $row=$make('Bounded dependency template');siteDnsFixture(null);
    $configs=array_map(fn($i)=>['domain_id'=>910000+$i,'user_id'=>$manager->id,'profile_id'=>$row->id,'notice_preferences'=>'{}'],range(1,150));
    DB::table('domains_managed_public_config')->insert($configs);
    $reads=null;
    DB::listen(static function($q)use(&$reads):void{
        if(is_array($reads) && preg_match('/^select (?!exists).* from "(domains_watch_names|domains_managed_public_config|domains_watch_product_maps)"/',$q->sql)){$reads[]=$q->sql;}
    });
    try{
        $reads=[];$reason=DB::transaction(fn()=>DnsTemplateUsage::refusal((int)$row->id,true));$seen=$reads;$reads=null;
        same('This template is assigned to retained domain records. Keep it for their history.',$reason,'Retained registered domain records keep the template');
        foreach($seen as$sql){expect(str_contains($sql,' limit '),'Each dependency read is one bounded row or page: '.$sql);}
        expect(count($seen)>=4,'Each dependency is read');
        same(2,count(array_filter($seen,fn($sql)=>str_contains($sql,'from "domains_managed_public_config"'))),'150 registered domain records are read in two pages');
        foreach(\Paymenter\Extensions\Servers\Domains\Services\TemplateAssignmentIndex::COLUMNS as$table=>$columns){
            expect(\Illuminate\Support\Facades\Schema::hasIndex($table,$columns),'Template assignments are indexed in '.$table);
        }
        $plan=json_encode(DB::select('EXPLAIN QUERY PLAN select "id" from "domains_watch_names" where "ns_profile_id" = ? and "state" in (?, ?) limit 1',[$row->id,'active','paused']));
        expect(str_contains($plan,'ns_profile_id_state_index'),'The watch assignment read uses its index: '.$plan);
    }finally{$reads=null;DB::table('domains_managed_public_config')->whereIn('domain_id',array_column($configs,'domain_id'))->delete();}
});
foreach($errors as$error){echo "FAIL $error\n";}
expect($errors===[],'DNS template control regressions: '.implode('; ',$errors));
$nativeSuccessful=true;echo "DNS template controls passed\n";
