<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,Context,Edit,Intent,OfferState,Projection,Query,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();

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
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$r=new Review($actor->id,$binding->serverId,$t->id,'override',Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id)),'Save supported terms');
$commands->run($r,['mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up']]);
same('7.99',Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail(Price::where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',1)->value('retail')),'Save changes the fresh term just as the preview says');
$nativeSuccessful=true;echo "Pricing preview distinguishes stale cost and saving.\n";
