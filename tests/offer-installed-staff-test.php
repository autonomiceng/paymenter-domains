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
DB::table('settings')->where('key','domains_credentials')->where('settingable_id',$t->server_id)->update(['value'=>'synthetic-unreadable-ciphertext']);
$before=retainedOfferObligations();$calls=$reads->calls;
Livewire\Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])
    ->assertSee('.com')->assertSee('Prices')->assertDontSee('Ask your site admin to check the encryption key.');
same($before,retainedOfferObligations(),'Installed staff page preserves every retained identity and financial obligation');
same($calls,$reads->calls,'Installed staff page never resolves a provider');
$nativeSuccessful=true;
