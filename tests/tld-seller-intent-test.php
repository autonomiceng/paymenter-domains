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
extract(commerceFixture(true,registrars:['enom']));staffSalesFixture();tldPanel();pinStaffClock($now);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$errors=[];
$raw=$t->fresh()->catalogue_intent;$facts=$t->fresh()->catalogue_facts;
$submit=static function()use($actor,$t){return Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('seller')->fillForm(['seller'=>$t->server_id,'register'=>true,'transfer'=>false]);};
foreach(['terms','disabled','inheritance'] as $case){
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>$raw,'catalogue_facts'=>$facts]);
    DB::table('extensions')->where('id',$t->server_id)->update(['enabled'=>true]);
    if($case==='terms'){
        $f=OfferState::decode($facts);$f['terms']['register']=[1];
        DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($f),'catalogue_intent'=>OfferState::json([...OfferState::decode($raw),'sell'=>false])]);
    }
    if($case==='inheritance'){DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json([...OfferState::decode($raw),'sell'=>null])]);}
    $page=$submit();$before=$t->fresh()->getRawOriginal();
    if($case==='disabled'){DB::table('extensions')->where('id',$t->server_id)->update(['enabled'=>false]);}
    $page->callMountedAction()->assertSuccessful();
    if($case==='inheritance'){
        if((OfferState::decode($t->fresh()->catalogue_intent)['sell']??null)!==null){$errors[]='Registrar choice pins an inherited Sell default';}
    }else{
        if($t->fresh()->getRawOriginal()!==$before){$errors[]='Registrar dispatch changed invalid '.$case.' intent';}
        if($t->fresh()->getRawOriginal()===$before){$page->assertNotified('Change refused');}
    }
}
DB::table('extensions')->where('id',$t->server_id)->update(['enabled'=>true]);
same(0,$reads->calls,'Registrar intent never reads providers');
expect($errors===[],implode("\n",$errors));$nativeSuccessful=true;echo "Native registrar intent guards and inheritance passed.\n";
