<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$other=$tlds[1];$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$errors=[];
$raw=$t->fresh()->catalogue_intent;$facts=$t->fresh()->catalogue_facts;
$defaults=OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);
DB::table('domains_accounts')->where('server_id',$t->server_id)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultSell'=>true])]);
$submit=static function()use($actor,$t){return Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('seller')->setActionData(['seller'=>$t->server_id,'register'=>true,'transfer'=>false,'reason'=>'Choose this registrar for new orders']);};
$guard=static function(int $seller,string $reason)use($actor,$t,$native,$clock,$bindings):void {
    $commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
    $ticket=Review::ticket($actor->id,$t->server_id,$t->id,'seller',StaffCommands::stamp($t->server_id,$t->id));
    $review=new Review($actor->id,$t->server_id,$t->id,'seller',$ticket,'Choose this registrar for new orders');
    tldRefusal(fn()=>$commands->run($review,['seller'=>$seller,'register'=>true,'transfer'=>false]),$reason);
};
foreach(['terms','disabled','inheritance'] as $case){
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>$raw,'catalogue_facts'=>$facts]);
    DB::table('extensions')->where('id',$t->server_id)->update(['enabled'=>true]);
    if($case==='terms'){
        $f=OfferState::decode($facts);$f['terms']['register']=[4];
        DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($f),'catalogue_intent'=>OfferState::json([...OfferState::decode($raw),'sell'=>false])]);
    }
    if($case==='inheritance'){DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json([...OfferState::decode($raw),'sell'=>null])]);}
    $page=$submit();if($case==='disabled'){$page->setActionData(['seller'=>$other->server_id]);}
    $before=$t->fresh()->getRawOriginal();
    if($case==='disabled'){DB::table('extensions')->where('id',$other->server_id)->update(['enabled'=>false]);}
    echo 'Registrar scenario: '.$case."\n";
    $page->callMountedAction()->assertSuccessful();
    if($case==='terms'){
        $page->assertHasActionErrors(['seller'=>'in']);
        $guard($t->server_id,'Explicit verified widening required.');
    }
    if($case==='disabled'){
        $guard($other->server_id,'Seller account off.');
    }
    if($case==='inheritance'){
        $page->assertHasNoActionErrors()->assertNotified('Change recorded');
        if((OfferState::decode($t->fresh()->catalogue_intent)['sell']??null)!==null){$errors[]='Registrar choice pins an inherited Sell default';}
        DB::table('domains_accounts')->where('server_id',$t->server_id)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultSell'=>false])]);
        if(Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()->setting($t->fresh(),'enabled')){$errors[]='Inherited TLD ignores a later account Off default';}
    }else{
        if($t->fresh()->getRawOriginal()!==$before){$errors[]='Registrar dispatch changed invalid '.$case.' intent';}
    }
}
DB::table('extensions')->where('id',$other->server_id)->update(['enabled'=>true]);
same(0,$reads->calls,'Registrar intent never reads providers');
expect($errors===[],implode("\n",$errors));$nativeSuccessful=true;echo "Native registrar intent guards and inheritance passed.\n";
