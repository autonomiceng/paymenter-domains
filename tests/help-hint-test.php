<?php
// Staff help remains keyboard accessible and does not mount a nested action. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HelpHint,RegistrarFacts,Staff};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);
$help=static function(string $html,string $tip,string $where,bool $each=false):void{
    libxml_use_internal_errors(true);$dom=new DOMDocument;$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$x=new DOMXPath($dom);
    $found=0;
    foreach($x->query('//button') as $button){
        $wanted=str_contains($button->getAttribute('x-tooltip')?:$button->getAttribute('x-tooltip.raw'),$tip);if(!$wanted){continue;}$found++;
        expect(str_contains($button->getAttribute('x-on:focus.capture'),".fi-modal-window"),$where.': a dialog opening does not open this help');
    }
    $each?expect($found>=1,$where.': a ? with this help on each row'):same(1,$found,$where.': one ? with this help');
};
// Help on an entry on a real staff page.
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSuccessful();
$help($page->html(),RegistrarFacts::DUE_HELP,'Entry hint');
// Help on the prices table, the one place that drew its own grey "?".
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSuccessful();
$help($page->html(),'Registrations and transfers below cost are held.','TLD prices table',true);
// One shared way: nothing else in Admin draws a question mark.
$root=dirname(__DIR__).'/Domains';$drawn=[];
foreach(array_filter(['Admin','resources/views/admin','resources/views/staff'],fn(string $dir)=>is_dir($root.'/'.$dir)) as $dir){
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $file){
        $code=file_get_contents($file->getPathname());
        if($file->getFilename()!=='HelpHint.php'&&(str_contains($code,'question-mark-circle')||str_contains($code,'>?</'))){$drawn[]=substr($file->getPathname(),strlen($root)+1);}
    }
}
same([],$drawn,'Only HelpHint draws a staff question mark');
echo "Staff help is available without opening a nested action.\n";$nativeSuccessful=true;
