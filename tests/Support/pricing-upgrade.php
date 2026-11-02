<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule,PricingRules};
// Native published price rows and an issued invoice prove that the upgrade never republishes or rewrites money.
$legacyServer=(int)$tlds[1]->server_id;
App\Models\Server::findOrFail($legacyServer)->settings()->where('key','domains_pricing_default_rule')->delete();
$pricingRules=new PricingRules;
$oldDefault=PricingRule::DEFAULT; // The effective rule before this upgrade, including its implicit fallback.
$customDefault=$pricingRules->defaultRule((int)$t->server_id);
$issued=new App\Models\Invoice(['user_id'=>$u->id,'currency_code'=>'USD','status'=>'pending','due_at'=>'2026-11-10']);
$issued->send_create_email=false;$issued->save();
$issued->items()->create(['description'=>'Existing domain renewal','quantity'=>1,'price'=>'10.40']);
$snapshot=static function():array {
    $result=[];
    foreach(['domains_prices','domains_tlds','products','plans','prices','invoices','invoice_items','invoice_transactions','credits','jobs'] as $table){
        $result[$table]=Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
    }
    return $result;
};
$beforeUpgrade=$snapshot();
$upgradePaths=glob(dirname(__DIR__,2).'/Domains/database/migrations/2026_11_01_000048_preserve_pricing_defaults.php');
app(Illuminate\Database\Migrations\Migrator::class)->run($upgradePaths,['force'=>true]);
same($oldDefault,$pricingRules->defaultRule($legacyServer),'Upgrade pins the existing implicit rule');
same($customDefault,$pricingRules->defaultRule((int)$t->server_id),'Upgrade preserves a saved custom rule');
foreach(Tld::all() as $kept){$native->keepCurrent($kept,$now,true);} // The ordinary background publisher must see no changed rule after upgrade.
same($beforeUpgrade,$snapshot(),'Upgrade and background keepCurrent preserve TLD rules, published prices and issued invoices');
$newServer=App\Models\Server::create(['name'=>'New pricing account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$bindings->configure($newServer->id,'enom',Paymenter\Extensions\Servers\Domains\Data\Environment::Test,['token'=>'new-pricing-default']);
$newDefault=$pricingRules->defaultRule($newServer->id);
same(['markup_fee_percent','8.00','ending_99'],[$newDefault['mode'],$newDefault['markup'],$newDefault['round_up']],'New account includes card fees, 8 percent markup and rounds up to .99');
same(Paymenter\Extensions\Servers\Domains\Services\CardFee::DEFAULT,$newDefault['card_fee'],'New account uses the normal card fee estimate');
same('1.99',PricingRule::price('1.00',$newDefault,'register'),'One dollar cost covers the card fee estimate, markup and .99 rounding');
