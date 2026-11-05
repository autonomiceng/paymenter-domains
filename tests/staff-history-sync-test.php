<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\{Tld,Price};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();

$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$review=new Review($actor->id,$binding->serverId,$t->id,'override',Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id)),'Keep this staff price edit');
$commands->run($review,['mode'=>'fixed','fixed_register'=>'12.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00']);
$server=App\Models\Server::findOrFail($binding->serverId);
// A bulk sync crosses several encrypted archive batches, as hundreds of TLDs do in the demo.
DB::transaction(function()use($server,$now):void {
    for($i=0;$i<300;$i++){
        Review::append($server,['key'=>'sync-'.$i,'actor_id'=>null,'at'=>$now->modify('+1 minute')->format(DATE_ATOM),'action'=>'catalogue_feed','phase'=>'completed','tld'=>'sync'.$i,'before'=>[],'after'=>[],'input'=>['observations'=>str_repeat('synthetic cost ',30)]]);
    }
});
$history=TldFacts::history($server->id,$t->id);
expect(is_array($history)&&in_array('Keep this staff price edit',array_column($history,'reason'),true),'A staff price save stays in its TLD History after bulk sync archive rollover');
Livewire\Livewire::actingAs($actor)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class,['record'=>$t->id])->assertSee('Keep this staff price edit');
$nativeSuccessful=true;echo "Staff edit survives syncs in History and Activity.\n";
