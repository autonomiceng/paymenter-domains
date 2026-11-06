<?php
// Native Filament page and encrypted legacy models are the failing boundary.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
extract(commerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$t=$tlds[0];
DB::table('domains_tlds')->where('id',$t->id)->update(['tld'=>'invalid','use_default_rule'=>false,
    'pricing_mode'=>'fixed','fixed_register'=>'0.00','fixed_renew'=>'0.00','fixed_transfer'=>'0.00',
    'catalogue_intent'=>json_encode(['pricing_rule'=>['mode'=>'fixed','markup'=>'0.00','round_up'=>'none','fixed'=>['register'=>'0.00','renew'=>'0.00','transfer'=>'0.00']]])]);
try {
    Livewire\Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])
        ->assertSee('.invalid')->assertSee('Prices by registrar account')->assertSee('Fixed price')->assertSee('Missing');
} catch (Throwable $error) { throw new RuntimeException('Invalid saved fixed prices must render their TLD detail, not HTTP 500.',0,$error); }
// r16: with no registrar for new orders either, the head says the invalid price rule and the Missing row says only what to do.
DB::table('domains_sales_policies')->where('tld','invalid')->update(['server_id'=>null]);
$seen=preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',Livewire\Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->html())),ENT_QUOTES|ENT_HTML5));
same([1,1],[substr_count($seen,'Invalid fixed price rule'),substr_count($seen,'Registrar for new orders Missing Use More → Change registrar.')],'The invalid price cause shows once; the seller row says what to do');
$nativeSuccessful=true;echo "Invalid saved pricing detail renders a repairable page.\n";
