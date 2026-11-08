<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture covers the real saved-price matrix, Blade warning and strict publication boundary.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Services\PriceList;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);
$list=new class extends PriceList {public StaffCatalogue $source;protected function catalogue():?StaffCatalogue{return $this->source;}};$list->source=$source;
$broken=$tlds[0]->fresh()->replicate();$broken->tld='invalid';$broken->save();
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
foreach(['{"sell":','"invalid"',OfferState::json(['sell'=>[]]),OfferState::json(['longest'=>11])] as $bad){
    DB::table('domains_tlds')->where('id',$broken->id)->update(['catalogue_intent'=>$bad]);
    $before=StaffCommands::stamp($binding->serverId);
    $matrix=$list->read();
    same(array_column($tlds,'id'),array_column($matrix['rows'],'id'),'Healthy TLDs remain in the price matrix');
    same('7.80',$matrix['rows'][0]['prices']['register']['price'],'Healthy published retail stays exact');
    same('6.0000',$matrix['rows'][0]['prices']['register']['cost'],'Healthy normalized cost stays exact');
    same([$broken->id],array_column($matrix['skipped'],'id'),'Only unreadable intent is left out');
    $html=view('domains::admin.sell-note',['warning'=>$matrix['warnings'][0]])->render();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    same(".invalid on Synthetic has no price: its custom pricing can't be read. Open .invalid and use Edit prices.",
        $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Existing named warning and repair wording are preserved');
    expect(str_contains($list->csv(),"Left out: custom pricing can't be read"),'CSV discloses the omitted price');
    same($before,StaffCommands::stamp($binding->serverId),'Reading leaves intent and publication unchanged');
    $refused=false;try{$commands->publish($broken->id);}catch(Throwable){$refused=true;}
    expect($refused,'Unreadable intent still refuses publication');
    same($before,StaffCommands::stamp($binding->serverId),'Refused publication preserves all saved state');
}
same(0,$reads->calls,'Price matrix and refused writes make no provider calls');
echo "Price matrix isolates unreadable intent and retains warnings without weakening publication.\n";$nativeSuccessful=true;
