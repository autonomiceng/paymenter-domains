<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Invoice,Order,Server};
use Illuminate\Support\Facades\{Auth,Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Intent,OfferState,Query,StaffCommands,Writer,Projection};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/StaffCommands.php'), 'Reviewed staff commands need the Writer-owned atomic entry');
require __DIR__.'/Support/offer-commerce.php';
$fixture=offerCommerceFixture();extract($fixture);tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$writer=new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true);
$staff=new StaffCommands($writer,$bindings,static function($b):void {expect(DB::transactionLevel()===0,'Local wake happens after commit');});
$id=(int)$tlds[0]->id;
$review=static function(string $action,?int $tld=null,?string $ticket=null)use($actor,$binding):Review {
    return new Review((int)$actor->id,$binding->serverId,$tld,$action,$ticket??Review::ticket((int)$actor->id,$binding->serverId,$tld,$action,StaffCommands::stamp($binding->serverId,$tld)),'Synthetic staff review');
};
$rule=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'12.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00'];
$before=count(Review::historyRows(Server::find($binding->serverId)));$readsBefore=$reads->calls;
$r=$review('override',$id);$staff->run($r,$rule);
same($before+1,count(Review::historyRows(Server::find($binding->serverId))),'One reviewed edit records one audit');
same('$12.00',$storefront->offer('com','register',1)['price'],'Reviewed edit reaches the real public offer');
same($readsBefore,$reads->calls,'Reviewed pricing and projection resolve no provider');
$user=commerceUser();$cart=commerceCart($user);$offer=$storefront->offer('com','register',1);
commerceAdd($cart,$offer['product'],'staff-edited-placeholder.com');$admission->refresh();commerceCheckout($user,$cart);
same([1,1,1],[Order::count(),Invoice::count(),Operation::count()],'Reviewed price reaches refreshed cart and locked native checkout');
same('12.00',Operation::first()->policy['price']['retail'],'Order freezes the reviewed exact retail');
$state=static fn()=>[StaffCommands::stamp($binding->serverId,$id),count(Review::historyRows(Server::find($binding->serverId))),DB::table(Review::KEYS)->count(),json_encode($native->display(Tld::find($id)),JSON_THROW_ON_ERROR)];
$before=$state();
try{$staff->run($r,$rule);expect(false,'Consumed review refused');}catch(DomainException){}
same($before,$state(),'Consumed review has no intent, native, audit or key write');
foreach(['expired','stale','permission'] as $failure){
    $r=$review('override',$id);
    if($failure==='expired'){$ticket=Review::ticket($actor->id,$binding->serverId,$id,'override',StaffCommands::stamp($binding->serverId,$id));$raw=json_decode(Crypt::decryptString($ticket),true);$raw['until']=time()-1;$r=$review('override',$id,Crypt::encryptString(json_encode($raw)));}
    if($failure==='stale'){DB::table('domains_tlds')->where('id',$id)->increment('catalogue_revision');}
    $permissions=$actor->role()->first()->permissions;
    if($failure==='permission'){$actor->role()->first()->update(['permissions'=>[Staff::VIEW]]);}
    $before=$state();try{$staff->run($r,$rule);expect(false,$failure.' review refused');}catch(DomainException){}
    same($before,$state(),$failure.' has no intent/native/audit/key side effects');
    $actor->role()->first()->update(['permissions'=>$permissions]);
}
// Force a native projection write to fail, after intent mutation. The complete reviewed unit rolls back.
$r=$review('override',$id);$before=$state();$fail=true;
App\Models\Price::saving(static function()use(&$fail):void {if($fail){throw new RuntimeException('Synthetic projection failure');}});
try{$staff->run($r,[...$rule,'fixed_register'=>'15.00']);expect(false,'Projection failure refused');}catch(RuntimeException $e){same('Synthetic projection failure',$e->getMessage(),'Reached native projection');}$fail=false;
same($before,$state(),'Projection failure rolls back intent/native/audit/key together');
$staff->run($r,[...$rule,'fixed_register'=>'15.00']);
same('$15.00',$storefront->offer('com','register',1)['price'],'Rolled-back ticket remains usable');
echo "Offer staff: ok\n";
$nativeTestComplete=true;
