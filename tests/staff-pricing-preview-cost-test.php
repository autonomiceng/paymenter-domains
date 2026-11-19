<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,Context,Edit,Intent,OfferState,Projection,Query,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());staffSalesFixture();tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock,readable:static fn()=>true);$t=$tlds[0]->fresh();

$rule=Paymenter\Extensions\Servers\Domains\Services\PricingRule::validate(['mode'=>'markup_percent','markup'=>'25','round_up'=>'ending_99']);
$query=new Query($binding,['com'],'register');
$revision=$ports->readOffers($query,Context::Staff)[0]->revision;
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:false)),$revision);
// Exact costs for non-bucket terms are retained but stale; supported bucket terms remain fresh.
$p=Price::where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',3)->sole();
// Encode through the real Cost type rather than relying on persistence field names.
$cost=new Paymenter\Extensions\Servers\Domains\Catalogue\Cost('register',3,true,'18.0000',Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$now->modify('-2 days'),$now->modify('-1 minute'));
DB::table('domains_prices')->where('id',$p->id)->update([...costColumns(OfferState::json(OfferState::encodeCost($cost)))]);
// Route the existing preview renderer to this same real snapshot fixture.
$probe=new ReflectionProperty(CallerRouting::class,'probe');$probe->setValue(null,[StaffCatalogue::class=>$source]);
try{$html=(new ReflectionMethod(SettingsActions::class,'preview'))->invoke(null,$t->id,$rule)->toHtml();}finally{$probe->setValue(null,null);}
$dom=new DOMDocument();$dom->loadHTML($html);
$xpath=new DOMXPath($dom);
$amount=static fn(string $row):string=>trim($xpath->evaluate('string(//tr[td[1]="'.$row.'"]/td[last()]/text()[1])'));
same('$8.99',$amount('Transfer, 1 year'),'Show the calculated price with transfers turned off');
same('$29.97',$amount('Register, 3 years'),'Show the calculated price when the exact term cost is stale');
same('$7.99',$amount('Register, 1 year'),'Show the price that saving will publish for the fresh term');

$fixed=Paymenter\Extensions\Servers\Domains\Services\PricingRule::validate([
    'mode'=>'fixed','round_up'=>'none','fixed'=>['register'=>'5','renew'=>'10','transfer'=>'5'],
]);
$held=collect($source->preview($t->fresh(),$fixed))->firstWhere('action','transfer');
same(['5.00','staff_term_stopped'],[$held['new'],$held['hold']],'Transfers remain off even if the calculated price is below cost');

// Price editing and publishing do not grant the account-wide price refresh.
$pricingOnly=staffUser([Staff::VIEW,Staff::POLICY,Review::permission('override'),Review::permission('publish')]);
$preview=new ReflectionMethod(SettingsActions::class,'preview');
$probe->setValue(null,[StaffCatalogue::class=>$source]);
try {
    expect(SettingsActions::syncAll($binding->serverId)->isVisible(),'Import grants expose Sync prices');
    expect(str_contains($preview->invoke(null,$t->id,$rule)->toHtml(),'Use Sync prices'),'The stale cost points to the available retry');
    Auth::setUser($pricingOnly);
    expect(!SettingsActions::syncAll($binding->serverId)->isVisible(),'Price editing and publishing do not expose Sync prices');
    $limited=$preview->invoke(null,$t->id,$rule)->toHtml();
    expect(str_contains($limited,'$29.97')&&!str_contains($limited,'Sync prices'),'With no Import grant the stale price stays visible without directing staff to Sync prices');
} finally {
    Auth::setUser($actor);
    $probe->setValue(null,null);
}
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$r=new Review($actor->id,$binding->serverId,$t->id,'override',Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id)),'Save supported terms');
$commands->run($r,['mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up']]);
same('7.99',Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail(Price::where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',1)->value('retail')),'Save changes the fresh term just as the preview says');

// Update the mounted fields individually, as the live pricing controls do.
$probe->setValue(null,[StaffCatalogue::class=>$source]);
try {
    $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])
        ->mountAction('override')->assertActionMounted('override');
    $draft='mountedActions.0.data.';
    $previewRows=static function($page): int {
        $modal=$page->call('$refresh')->effects['partials']['action-modals.0']??'';
        $dom=new DOMDocument();
        $dom->loadHTML('<div>'.$modal.'</div>',LIBXML_NOERROR|LIBXML_NOWARNING);
        return (int)(new DOMXPath($dom))->evaluate('count(//div[@class="domains-preview"]//tbody/tr)');
    };
    $page->assertActionDataSet(['pricing'=>'custom','mode'=>'markup_percent','markup'=>'25.00']);
    expect($previewRows($page)>0,'The saved percentage has a retail preview');

    $page->set($draft.'mode','markup_fee_percent')->assertActionDataSet(['markup'=>'25.00']);
    $page->set($draft.'mode','markup_percent')->assertActionDataSet(['markup'=>'25.00']);
    $page->set($draft.'mode','markup_amount');
    same(null,$page->get($draft.'markup'),'Changing from percent to dollars clears the old markup');
    same(0,$previewRows($page),'A blank dollar markup has no retail preview');
    $page->set($draft.'reason','Change markup units')->callMountedAction()->assertHasActionErrors(['markup'=>'required']);

    $page->set($draft.'markup','0')->assertActionDataSet(['markup'=>'0']);
    expect($previewRows($page)>0,'An explicitly entered zero has a retail preview');
    $page->set($draft.'pricing','default');
    expect($previewRows($page)>0,'Default pricing can still be previewed');
    $page->set($draft.'pricing','custom')->assertActionDataSet(['markup'=>'0']);

    $page->set($draft.'mode','fixed')->set($draft.'fixed_register','9')
        ->set($draft.'fixed_renew','10')->set($draft.'fixed_transfer','11');
    expect($previewRows($page)>0,'Fixed prices can still be previewed');
    $page->set($draft.'mode','markup_fee_percent')->assertActionDataSet(['markup'=>null]);
    same(0,$previewRows($page),'Switching from dollars through fixed to percent still requires a new markup');
    $page->set($draft.'mode','fixed')->assertActionDataSet(['fixed_register'=>'9','fixed_renew'=>'10','fixed_transfer'=>'11']);
} finally {
    $probe->setValue(null,null);
}
$nativeSuccessful=true;echo "Pricing preview distinguishes stale cost and saving.\n";
