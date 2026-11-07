<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;

// Actual persisted checked activation, normal container routing and real native Filament rendering.
tldPanel();pinStaffClock($now);$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$t=$tlds[0];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
same('',\Paymenter\Extensions\Servers\Domains\Admin\Support\PricingEditorNotice::render($t->fresh()),'The TLD page head carries sales, so the notice is for native products only');
$product=$native->product($t->fresh(),'register');
expect($product!==null,'The retained sold TLD has its native registration product');
$nativeNotice=\Paymenter\Extensions\Servers\Domains\Admin\Support\PricingEditorNotice::render($product);
$dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$nativeNotice);$xpath=new DOMXPath($dom);
same('Check account setup on Registrar account.',$xpath->evaluate('normalize-space(//*[contains(concat(" ",normalize-space(@class)," ")," fi-prose ")]/p[last()])'),
    'The native product edit notice gives the actual account step instead of re-saving a sold TLD');
expect(str_contains($nativeNotice,'Changing a price here can stop new orders for this TLD until its prices match again.'),'The native price-edit help remains intact');
DB::table('settings')->where('key','domains_credentials')->where('settingable_id',$t->server_id)->update(['value'=>'synthetic-unreadable-ciphertext']);
$before=retainedOfferObligations();$calls=$reads->calls;
Livewire\Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])
    ->assertSee('.com')->assertSee('Prices')->assertDontSee('Ask your site admin to check the encryption key.');
same($before,retainedOfferObligations(),'Installed staff page preserves every retained identity and financial obligation');
same($calls,$reads->calls,'Installed staff page never resolves a provider');
$nativeSuccessful=true;
