<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native Staff snapshots are required to distinguish retained retail from the current sale decision.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,OfferState,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
Auth::setUser(staffUser(array_keys(Staff::permissions())));
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','renew')->delete();
foreach([['sell'=>false],['sell'=>false,'stops'=>['renew']],['defaultSell'=>false]] as $intent){
    if(isset($intent['defaultSell'])){
        $account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$binding->serverId)->sole();
        $account->update(['catalogue_defaults'=>OfferState::json([...OfferState::decode($account->catalogue_defaults),'defaultSell'=>false])]);$intent=[];
    }
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
    $holder=$ports->readOffers(new Query($binding,['com'],'renew'),Context::Holder)[0];
    same(true,$holder->available,'Retained holder renewal survives disabled sales and missing cost');
    $page=[];$table=new SellTable([$binding->serverId=>$binding],1,true,static function()use(&$page){return $page;},false,$source);
    $page=$table->rows('com')->get()->all();$cell=$table->cell($page[0],'renew','cost');
    same('Missing',$cell['text'],'Missing retained-holder renewal cost must stay visible');
    same('Use Sync prices.',$cell['hint'],'Retained renewal cost gives the repair step');
    same(true,$cell['mark'],'Missing retained renewal cost is marked for repair');
    same($holder->retail,$table->cell($page[0],'renew','price')['text'],'Retained annual retail remains visible');
}
$nativeSuccessful=true;echo "Disabled acquisition does not hide missing retained-holder renewal costs.\n";
