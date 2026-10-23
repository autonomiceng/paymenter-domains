<?php
// Installed retained holding views, synthetic evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
require __DIR__.'/Support/owner-pages.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema;
$out=$argv[1]??'';$shell=file_get_contents($argv[2]??'');expect(is_dir($out),'Output directory required');
HoldingProvenanceSchema::install();[$d]=$make('example.com',false);
DB::table('domains_domains')->where('id',$d->id)->update(['provenance'=>'adoption_pending','status'=>'adoption_pending','billing_managed'=>false,'provider_expires_at'=>null,'renewal_policy'=>encrypt(json_encode(['conversion_source_expiry'=>'2027-10-18']),false)]);$d->refresh();
$dto=$owners->present($d);storefrontViews();app('view')->getFinder()->flush();
$html=view('domains::show',['domain'=>$dto,'keys'=>[]])->render();preg_match('#<body>(.*)</body>#s',$html,$body);
writeRenderArtifact($out.'/customer-pending.html',str_replace('@@SLOT@@',$body[1]??$html,$shell));
staffPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$test=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSee('Registration setup pending');
$html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
$html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
$html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
$html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
$html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
writeRenderArtifact($out.'/staff-pending.html',$html);
$strings=[];foreach(glob($out.'/*.html')as$file){$strings[basename($file)]=trim(preg_replace('/\s+/u',' ',strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',file_get_contents($file)))));}
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Installed pending owner and staff views rendered.\n";$nativeSuccessful=true;
