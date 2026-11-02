<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PricingRule,PricingRules};
// Native published price rows and an issued invoice prove that the upgrade never republishes or rewrites money.
$pricingRules=new PricingRules;
$issued=new App\Models\Invoice(['user_id'=>$u->id,'currency_code'=>'USD','status'=>'pending','due_at'=>'2026-11-10']);
$issued->send_create_email=false;$issued->save();
$issued->items()->create(['description'=>'Existing domain renewal','quantity'=>1,'price'=>'10.40']);
$snapshot=static function():array {
    $result=[];
    foreach(['domains_prices','domains_tlds','products','plans','prices','invoices','invoice_items','invoice_transactions','credits','jobs'] as $table){
        $result[$table]=DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
    }
    return $result;
};
$beforeUpgrade=$snapshot();
$shared=dirname(__DIR__,2).'/Domains/database/migrations/2026_11_01_000049_share_default_pricing.php';
$first=Server::create(['name'=>'First pricing account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$second=Server::create(['name'=>'Second pricing account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$noFixed=['register'=>null,'renew'=>null,'transfer'=>null];
$pinned=['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none','fixed'=>$noFixed]; // What 000048 froze for every account.
$saved=['mode'=>'markup_amount','markup'=>'4.00','round_up'=>'half','fixed'=>$noFixed];$older=['mode'=>'markup_percent','markup'=>'12.00','round_up'=>'whole','fixed'=>$noFixed];
$upgrade=static function(array $rows)use($shared):void {
    Setting::whereNull('settingable_type')->whereIn('key',[PricingRules::KEY,CardFee::KEY])->delete();
    foreach($rows as [$server,$key,$value,$at]){
        DB::table('settings')->insert(['key'=>$key,'value'=>json_encode($value),'type'=>'array','encrypted'=>false,'settingable_type'=>$server->getMorphClass(),'settingable_id'=>$server->id,'created_at'=>$at,'updated_at'=>$at]);
    }
    (require $shared)->up();
    same(0,DB::table('settings')->whereNotNull('settingable_type')->whereIn('key',[PricingRules::KEY,CardFee::KEY])->count(),'No per-account pricing key remains');
};
$rule=static fn(PricingRules $rules)=>array_diff_key($rules->defaultRule(),['card_fee'=>true]);
foreach([
    'A saved rule beats a newer pinned one'=>[[[$first,$saved,'2026-10-01 00:00:00'],[$second,$pinned,'2026-11-01 00:00:00']],$saved],
    'The most recently saved rule wins'=>[[[$first,$older,'2026-10-01 00:00:00'],[$second,$saved,'2026-10-02 00:00:00'],[$tlds[0]->server,$pinned,'2026-11-01 00:00:00']],$saved],
] as $case=>[$rows,$expected]){
    $upgrade(array_map(static fn($row)=>[$row[0],PricingRules::KEY,$row[1],$row[2]],$rows));
    same($expected,$rule($pricingRules),$case);
    same(1,Setting::whereNull('settingable_type')->where('key',PricingRules::KEY)->count(),'One business rule');
}
// Nobody ever chose the pinned rule, so accounts that only have it take the new-account default.
$upgrade([[$first,PricingRules::KEY,$pinned,'2026-10-01 00:00:00'],[$second,PricingRules::KEY,$pinned,'2026-11-01 00:00:00']]);
same(false,Setting::whereNull('settingable_type')->where('key',PricingRules::KEY)->exists(),'A pinned rule never moves');
same([...PricingRule::DEFAULT,'mode'=>'markup_fee_percent','markup'=>'8.00','round_up'=>'ending_99'],$rule($pricingRules),'Only pinned rules leave the new-account default');
$upgrade([[$first,CardFee::KEY,['percent'=>'4.00','fixed'=>'0.10'],'2026-11-01 00:00:00'],[$second,CardFee::KEY,['percent'=>'3.00','fixed'=>'0.20'],'2026-10-01 00:00:00']]);
same(['percent'=>'4.00','fixed'=>'0.10'],CardFee::read(),'The most recently saved fee moves');
$upgrade([]);
same($beforeUpgrade,$snapshot(),'Upgrade preserves TLD rules, published prices and issued invoices');
$newDefault=$pricingRules->defaultRule();
same(['markup_fee_percent','8.00','ending_99'],[$newDefault['mode'],$newDefault['markup'],$newDefault['round_up']],'New account includes card fees, 8 percent markup and rounds up to .99');
same(CardFee::DEFAULT,$newDefault['card_fee'],'New account uses the normal card fee estimate');
same('1.99',PricingRule::price('1.00',$newDefault,'register'),'One dollar cost covers the card fee estimate, markup and .99 rounding');
