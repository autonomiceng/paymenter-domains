<?php
// Disposable native staff renderer. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/psl-staff-test.php'; $nativeSuccessful=false;
use Illuminate\Support\Facades\{Blade,DB,Auth};
use Filament\Facades\Filament;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as Page;
$out=$argv[1]??'';expect(is_dir($out),'Output directory required');$setRights($rights);Auth::setUser($staff);
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$base=1800000000;$response=['status'=>200,'body'=>pslFixture(),'etag'=>'"fixture"'];
$list=new \Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList($clock,new \Paymenter\Extensions\Servers\Domains\Services\PslTransport(function()use(&$response){if($response instanceof Throwable){throw $response;}return $response;}),new \Paymenter\Extensions\Servers\Domains\Services\PslValidator(128,5,1));
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList::class,$list);
$reset=function()use($clock,$base,&$response):void {DB::table('domains_psl_snapshots')->delete();DB::table('domains_psl_status')->where('id',1)->update(['last_network_fetch_at'=>null,'last_attempt_at'=>null,'checked_at'=>null,'last_result'=>null,'consecutive_failures'=>0,'attempt_token'=>null]);$clock->at=$base;$response=['status'=>200,'body'=>pslFixture(),'etag'=>'"fixture"'];};
$emit=static function(string $name)use($staff,$out,$css):void {
    session()->forget(['filament.notifications','filament.livewire.notifications']);
    $test=Livewire::actingAs($staff)->test(Page::class);
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $policy='<meta http-equiv="Content-Security-Policy" content="default-src \'self\' data:; style-src \'self\' \'unsafe-inline\'; font-src \'self\' data:; img-src \'self\' data:; script-src \'none\'; connect-src \'none\'; form-action \'none\'">';
    $html=str_replace('</head>',$policy.'<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    file_put_contents($out.'/staff-'.$name.'.html',$html);
};
$reset();$emit('not-downloaded');
same('updated',$list->update(),'Capture first download');$clock->at+=2*86400;$emit('current');
$reset();$list->update();$response=new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('timeout');
for($week=1;$week<=4;$week++){$clock->at=$base+$week*7*86400;same('timeout',$list->update(true),'Capture failed weekly attempt');}$clock->at=$base+31*86400+1;$emit('out-of-date');
$reset();$list->update();$response=new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('timeout');
for($week=1;$week<=12;$week++){$clock->at=$base+$week*7*86400;same('timeout',$list->update(true),'Capture paused history');}$clock->at=$base+91*86400+1;$emit('discovery-paused');
$reset();$list->update();$clock->at++;$emit('update-disabled');
$reset();$list->update();$clock->at+=2*86400;$response=['status'=>200,'body'=>pslFixture(40,37)];same('rules_lost_private',$list->update(),'Capture rejected download');$emit('last-attempt-rejected');
echo "Six actual installed native staff PSL states rendered.\n";$nativeSuccessful=true;
