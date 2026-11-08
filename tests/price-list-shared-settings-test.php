<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Shared pricing recovery through real staff readers.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceList,PricingRule,PricingRules};
extract(commerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$custom=$tlds[0]->replicate();$intent=OfferState::decode($custom->catalogue_intent);
$custom->fill(['tld'=>'org','catalogue_intent'=>OfferState::json([...$intent,'pricing_rule'=>PricingRule::validate([...PricingRule::DEFAULT,'mode'=>'markup_amount','markup'=>'1.25'])])])->save();
$source=StaffCatalogue::routed();$list=new PriceList;
$snapshot=static function():array{$rows=[];foreach(['domains_tlds','domains_prices','products','plans','prices','services','orders','invoices','invoice_items','invoice_transactions','credits'] as $table){$rows[$table]=DB::table($table)->orderBy('id')->get()->toJson();}return $rows;};$before=$snapshot();
foreach([
    [PricingRules::KEY,[...PricingRule::DEFAULT,'markup'=>[]],'default_rule',false],
    [PricingRules::KEY,[...PricingRule::DEFAULT,'mode'=>'fixed','fixed'=>['register'=>[],'renew'=>'2','transfer'=>'3']],'default_rule',false],
    [CardFee::KEY,['percent'=>[],'fixed'=>'0'],'card_fee',false],
    [CardFee::KEY,[],'card_fee',true],
] as [$key,$value,$cause,$encrypted]){
    $query=DB::table('settings')->whereNull('settingable_type')->where('key',$key);$saved=(clone $query)->first();
    try{
        $setting=App\Models\Setting::updateOrCreate(['key'=>$key,'settingable_type'=>null,'settingable_id'=>null],['type'=>'array','encrypted'=>false,'value'=>$value]);
        if($encrypted){DB::table('settings')->where('id',$setting->id)->update(['encrypted'=>true,'value'=>'synthetic-invalid-ciphertext']);}
        $raw=DB::table('settings')->orderBy('id')->get()->toJson();$expected=array_map(static fn($t)=>$t->id,$tlds);if($cause==='card_fee'){$expected[]=$custom->id;}
        $matrix=$list->read();same($expected,array_column($matrix['skipped'],'id'),'Each affected TLD has an explicit shared-settings omission: '.$cause);
        same($cause==='card_fee'?[]:[$custom->id],array_column($matrix['rows'],'id'),'A custom rule does not depend on an unreadable default');
        same([$cause],array_column($matrix['warnings'],'cause'),'One grouped warning retains the existing repair cause');
        $omitted=$source->omissions(Tld::orderBy('id')->get());same(count($expected),count($omitted),'Off-page omissions use the same settings validation');
        Livewire::actingAs($actor)->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->assertSee('Prices left out')->assertSee('To fix, use Default pricing at the top of this page.');
        same($raw,DB::table('settings')->orderBy('id')->get()->toJson(),'Recovery reads retain corrupt settings bytes');same($before,$snapshot(),'Recovery reads change no prices, intent or finance');
    }finally{if($saved){DB::table('settings')->where('id',$saved->id)->update((array)$saved);}else{DB::table('settings')->whereNull('settingable_type')->where('key',$key)->delete();}}
}
same(0,$reads->calls,'Recovery makes no provider calls');
$nativeSuccessful=true;echo "Shared pricing recovery keeps staff pages open without inventing prices\n";
