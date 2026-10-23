<?php
// Real native Filament pages and safe synthetic local evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/staff-watch-test.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource\Pages\{ListConfiguration as ListMappings,ViewConfiguration as ViewMapping};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListProfiles,ViewConfiguration as ViewProfile};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource\Pages\{ListPending,ViewPending};
$manager->role->update(['permissions'=>[\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::ADOPT,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::MANAGE]]);$adopter->role->update(['permissions'=>[\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::ADOPT,\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW]]);
$out=$argv[1];$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString(''))->brandName('Paymenter');$strings=[];
$save=function(string$name,$test)use($out,$css,&$strings):void{
 app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
 $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html().implode('',array_values($test->effects['partials']??[]))]);
 // Freeze native wire:model form values from the mounted component, without running browser requests.
 $state=['mountedActions'=>$test->instance()->mountedActions];
 $html=preg_replace_callback('~<input\b[^>]*>~s',function($m)use($state){$tag=$m[0];if(preg_match('/wire:model(?:\.[\w.-]+)?="([^"]+)"/',$tag,$key)){$value=data_get($state,$key[1]);if(is_scalar($value)){$tag=preg_replace('/\svalue="[^"]*"/','',$tag);$tag=preg_replace('~\s*/?>$~',' value="'.e((string)$value).'">',$tag);}}return $tag;},$html);
 $html=preg_replace_callback('~(<textarea\b[^>]*>).*?(</textarea>)~s',function($m)use($state){if(preg_match('/wire:model(?:\.[\w.-]+)?="([^"]+)"/',$m[1],$key)){$value=data_get($state,$key[1]);if(is_scalar($value)){return $m[1].e((string)$value).$m[2];}}return $m[0];},$html);
 $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);$html=str_replace('x-cloak="-lg"','',$html);$html=preg_replace('/x-cloak\s+x-show="isOpen"/','',$html);$html=str_replace('class="fi-modal fi-absolute-positioning-context"','class="fi-modal fi-modal-open fi-absolute-positioning-context"',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
 $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
 $html=str_replace('</head>','<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><style>'.$css.'*{animation:none!important;transition:none!important}</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
 foreach(['dark','light']as$mode){$doc=preg_replace('~<html\b[^>]*>~','<html lang="en" class="'.$mode.'">',$html,1);writeRenderArtifact($out.'/staff-'.$mode.'-'.$name.'.html',$doc);}
 $strings[$name]=trim(preg_replace('/\s+/u',' ',strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html))));
};
// Restore the synthetic source retired by the lifecycle test; no production enrollment is involved.
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);$map->refresh();
DB::table('domains_watch_sources')->where('id',$source->id)->update(['state'=>'watch','service_id'=>$service->id,'watch_id'=>$id,'map_revision'=>$map->revision]);
DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'active','expected_dns'=>'platform','registration'=>'external']);
$base=time()-15;
foreach(['both'=>['platform',true,true,false],'dns-only'=>['platform',false,false,false],'external-empty'=>['external',false,false,false],'website-only'=>['platform',true,false,false],'email-only'=>['platform',false,true,false],'partial'=>['platform',true,null,true],'unknown'=>[null,null,null,true],'mixed'=>['external',true,false,false]] as $situation=>$axes){
 DB::table('domains_watch_observations')->where('watch_id',$id)->delete();DB::table('domains_watch_registry')->where('watch_id',$id)->delete();
 DB::table('domains_watch_work')->where('watch_id',$id)->update(['due_at'=>$base,'backoff_until'=>null,'claim'=>null,'lease_until'=>null,'safe_code'=>null,'source_cursor'=>0]);
 foreach(['delegation','points']as$kind){$t=$dns->begin($id,$uid,$kind,$base);expect($t!==null,'Staff visual DNS ticket');$reading=$kind==='delegation'?new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading($kind,$axes[0]):new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading($kind,website:$axes[1],email:$axes[2],partial:$axes[3],mixed:$situation==='mixed');expect($dns->finish($t,$reading,$base+1),'Staff visual local reading');}
 noticeRegistry($f,$base,45);$save('watch-'.$situation,Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]));
}
foreach(['expired','redemption','no-coverage','not-registered','stale-history']as$situation){
 DB::table('domains_watch_registry')->where('watch_id',$id)->delete();DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$base,'backoff_until'=>null,'claim'=>null,'lease_until'=>null,'safe_code'=>null]);$t=$registry->begin($id,$uid,$base);expect($t!==null,'Staff visual registry ticket');
 $semantic=in_array($situation,['no-coverage','not-registered'],true);$reason=$situation==='no-coverage'?'no_coverage':($situation==='not-registered'?'not_registered':'facts');
 $registry->finish($t,['result'=>$semantic?($reason==='not_registered'?'not_registered':'unknown'):'facts','reason'=>$reason,'safe_code'=>$semantic?$reason:null,'expiry'=>$semantic?null:gmdate('Y-m-d',$base+($situation==='stale-history'?45:-2)*86400),'precision'=>$semantic?null:'date','source'=>null,'status'=>$situation==='redemption'?['redemptionPeriod']:[]],str_repeat('c',64),$base+1);
 if($situation==='stale-history'){DB::table('domains_watch_registry')->where('watch_id',$id)->update(['valid_until'=>$base-1]);}
 $save('watch-'.$situation,Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]));
}
DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'active']);
$save('watch-list',Livewire::actingAs($manager)->test(ListWatches::class));$save('watch-empty',Livewire::actingAs($manager)->test(ListWatches::class)->searchTable('none.invalid'));
foreach(['active','paused','retired']as$state){DB::table('domains_watch_names')->where('id',$id)->update(['state'=>$state]);$save('watch-'.$state,Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]));}
DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'active','canonical_name'=>str_repeat('long-domain-',5).'end.example.com']);$save('watch-long',Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]));DB::table('domains_watch_names')->where('id',$id)->update(['canonical_name'=>'example.com']);
$save('watch-pause-review',Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->mountAction('pause'));
$save('mapping-list',Livewire::actingAs($manager)->test(ListMappings::class));$save('mapping-detail',Livewire::actingAs($manager)->test(ViewMapping::class,['record'=>$map->id]));$save('mapping-create',Livewire::actingAs($manager)->test(ListMappings::class)->mountAction('save'));
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);$map->refresh();$save('mapping-enable',Livewire::actingAs($manager)->test(ViewMapping::class,['record'=>$map->id])->mountAction('enable'));
$save('profile-list',Livewire::actingAs($manager)->test(ListProfiles::class));$save('profile-detail',Livewire::actingAs($manager)->test(ViewProfile::class,['record'=>$profile->id]));$save('profile-edit',Livewire::actingAs($manager)->test(ViewProfile::class,['record'=>$profile->id])->mountAction('save'));
DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>false]);$profile->refresh();$save('profile-enable',Livewire::actingAs($manager)->test(ViewProfile::class,['record'=>$profile->id])->mountAction('enable'));
require_once __DIR__.'/Support/psl.php';$body=pslFixture(1200,200).'// '.str_repeat('synthetic padding ',7000)."\n";DB::table('domains_psl_snapshots')->delete();DB::table('domains_psl_snapshots')->insert(['state'=>'current','revision'=>hash('sha256',$body),'source_url'=>'https://example.test/list','byte_size'=>strlen($body),'icann_count'=>1200,'private_count'=>200,'fetched_at'=>time(),'validated_at'=>time(),'compressed_body'=>base64_encode(gzencode($body))]);$held=$service->replicate();$held->save();$save('mapping-preview',Livewire::actingAs($manager)->test(ViewMapping::class,['record'=>$map->id]));
$save('pending-list',Livewire::actingAs($adopter)->test(ListPending::class));$save('pending-detail',Livewire::actingAs($adopter)->test(ViewPending::class,['record'=>$domains[0]->id]));$save('pending-empty',Livewire::actingAs($adopter)->test(ListPending::class)->searchTable('none.invalid'));
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo "Real staff pages rendered.\n";
