<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native rendering proves the notice reuses the page's time element, including its local fallback.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\SellTableSchema;
extract(commerceFixture());tldPanel();
$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
$zone=date_default_timezone_get();$appZone=config('app.timezone');
try{
    date_default_timezone_set('America/Los_Angeles');config(['app.timezone'=>'America/Los_Angeles']);
    foreach(['2026-07-01T12:00:00Z'=>'Jul 1, 2026, 5:00 AM PDT','2026-12-01T12:00:00Z'=>'Dec 1, 2026, 4:00 AM PST'] as $iso=>$local){
        $instant=new DateTimeImmutable($iso);pinStaffClock($instant->modify('+4 days'));
        DB::table(SellTableSchema::FILLS)->updateOrInsert(['server_id'=>$tlds[0]->server_id,'account_fingerprint'=>$tlds[0]->account_fingerprint],
            ['filled_at'=>$instant->getTimestamp(),'tried_at'=>$instant->getTimestamp(),'failed_at'=>$instant->modify('+1 day')->getTimestamp(),'failure'=>'read_exception']);
        $page=Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$tlds[0]->server_id])->assertSuccessful()->assertSee('Prices are out of date');
        $dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
        $notice='//*[contains(concat(" ",normalize-space(@class)," ")," fi-sc-callout ")][contains(.,"The last good read was")]';
        $time=$xpath->query($notice.'//time[@data-domains-time]');same(1,$time->length,'Notice uses the shared viewer-time formatter');
        same($instant->getTimestamp(),(new DateTimeImmutable($time->item(0)->getAttribute('datetime')))->getTimestamp(),'Local display preserves the exact saved instant');
        same($local,$time->item(0)->textContent,'Shared formatter supplies the local fallback');
    }
}finally{date_default_timezone_set($zone);config(['app.timezone'=>$appZone]);}
same(0,$reads->calls,'Rendering stale times makes no provider calls');
echo "Stale price notice uses the shared time formatter in PDT and PST.\n";$nativeSuccessful=true;
