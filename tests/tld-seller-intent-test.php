<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites;
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$other=$tlds[1];$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$errors=[];
$raw=$t->fresh()->catalogue_intent;$facts=$t->fresh()->catalogue_facts;
$defaults=OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);
DB::table('domains_accounts')->where('server_id',$t->server_id)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultSell'=>true])]);
$submit=static function()use($actor,$t){return Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('seller')->setActionData(['seller'=>$t->server_id,'register'=>true,'transfer'=>false,'reason'=>'Choose this registrar for new orders']);};
foreach(['terms','disabled','inheritance'] as $case){
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>$raw,'catalogue_facts'=>$facts]);
    DB::table('extensions')->where('id',$t->server_id)->update(['enabled'=>true]);
    if($case==='terms'){
        $f=OfferState::decode($facts);$f['terms']['register']=[1];
        DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($f),'catalogue_intent'=>OfferState::json([...OfferState::decode($raw),'sell'=>false])]);
    }
    if($case==='inheritance'){DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json([...OfferState::decode($raw),'sell'=>null])]);}
    $page=$submit();if($case==='disabled'){$page->setActionData(['seller'=>$other->server_id]);}
    $before=$t->fresh()->getRawOriginal();
    if($case==='disabled'){DB::table('extensions')->where('id',$other->server_id)->update(['enabled'=>false]);}
    echo 'Registrar scenario: '.$case."\n";
    $page->callMountedAction()->assertSuccessful();if($case!=='disabled'){$page->assertHasNoActionErrors();}
    if($case==='disabled'){
        $review=Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;$commands=Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::class;
        $r=new $review($actor->id,$t->server_id,$t->id,'seller',$review::ticket($actor->id,$t->server_id,$t->id,'seller',$commands::stamp($t->server_id,$t->id)),'Choose this registrar for new orders');
        try{(new $commands(new Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null))->run($r,['seller'=>$other->server_id,'register'=>true,'transfer'=>false]);$errors[]='Fresh disabled-account dispatch accepted';}
        catch(DomainException $e){same('Seller account off.',$e->getMessage(),'Dispatch refuses the disabled account under writer locks');}
    }
    if($case==='inheritance'){
        $page->assertNotified('Change recorded');
        if((OfferState::decode($t->fresh()->catalogue_intent)['sell']??null)!==null){$errors[]='Registrar choice pins an inherited Sell default';}
        DB::table('domains_accounts')->where('server_id',$t->server_id)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultSell'=>false])]);
        if(Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()->setting($t->fresh(),'enabled')){$errors[]='Inherited TLD ignores a later account Off default';}
    }else{
        if($t->fresh()->getRawOriginal()!==$before){$errors[]='Registrar dispatch changed invalid '.$case.' intent';}
        if($case==='terms'&&$t->fresh()->getRawOriginal()===$before){$page->assertNotified('Change refused');}
    }
}
DB::table('extensions')->where('id',$other->server_id)->update(['enabled'=>true]);
same(0,$reads->calls,'Registrar intent never reads providers');
expect($errors===[],implode("\n",$errors));$nativeSuccessful=true;echo "Native registrar intent guards and inheritance passed.\n";
