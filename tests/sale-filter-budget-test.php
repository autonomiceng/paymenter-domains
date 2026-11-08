<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
extract(commerceFixture(true,registrars:['enom']));staffSalesFixture();tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
$b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());$source=StaffCatalogue::routed();
$base=$t->fresh()->getRawOriginal();unset($base['id']);$prices=DB::table('domains_prices')->where('server_id',$b->serverId)->get();$policy=(array)DB::table('domains_sales_policies')->where('tld','com')->sole();
for($i=1;$i<1000;$i++){
    $name='ending'.base_convert((string)($i+1000),10,36);DB::table('domains_tlds')->insert([...$base,'tld'=>$name]);
    foreach($prices as $price){$row=(array)$price;unset($row['id']);DB::table('domains_prices')->insert([...$row,'tld'=>$name]);}
    DB::table('domains_sales_policies')->insert([...$policy,'tld'=>$name]);
}
$measure=static function()use($b,$source):array{
    $table=new SellTable([$b->serverId=>$b],1,true,static fn()=>[],false,$source);
    DB::enableQueryLog();DB::flushQueryLog();$at=microtime(true);$ids=$table->forSale($table->rows(null))->pluck('id')->all();$elapsed=microtime(true)-$at;$q=DB::getQueryLog();DB::disableQueryLog();
    $batches=count(array_filter($q,static fn($v)=>str_contains($v['query'],'from "domains_sales_policies"')));
    echo json_encode(['rows'=>count($ids),'queries'=>count($q),'evaluation_batches'=>$batches,'seconds'=>$elapsed])."\n";
    return [$ids,$batches];
};
[$ids,$batches]=$measure();same(1000,count($ids),'Large fixture consists of sale-ready bound TLDs');
[$again,$repeated]=$measure();same($ids,$again,'A new request keeps the same evaluator result');
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>'{"sell":false}']);
[$changed]=$measure();expect(!in_array($t->id,$changed,true),'Fresh request immediately reflects stopped intent');
pinStaffClock($now->modify('+2 days'));[$expired]=$measure();same([],$expired,'Elapsed freshness cannot reuse sale membership');
DB::table('roles')->where('id',$actor->role_id)->update(['permissions'=>'[]']);
try{$measure();expect(false,'Fresh filter requires current grants');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Revoked staff view refuses');}
expect($batches<=10&&$repeated<=10,'A thousand TLDs use at most ten bounded evaluator batches');
$nativeSuccessful=true;echo "Bounded sale filter passed.\n";
