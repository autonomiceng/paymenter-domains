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
$first=Server::create(['name'=>'First pricing account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$second=Server::create(['name'=>'Second pricing account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$noFixed=['register'=>null,'renew'=>null,'transfer'=>null];
$pinned=['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none','fixed'=>$noFixed]; // What 000048 writes for an account with no rule, and also what someone may have saved.
$saved=['mode'=>'markup_amount','markup'=>'4.00','round_up'=>'half','fixed'=>$noFixed];$older=['mode'=>'markup_percent','markup'=>'12.00','round_up'=>'whole','fixed'=>$noFixed];
$mark='000047_mark_saved_pricing_defaults';$pin='000048_preserve_pricing_defaults';$share='000049_share_default_pricing';
$upgrade=static function(array $rows,?array $steps=null)use($mark,$pin,$share):void {
    Setting::whereNull('settingable_type')->whereIn('key',[PricingRules::KEY,CardFee::KEY])->delete();
    foreach($rows as [$server,$key,$value,$at]){
        DB::table('settings')->insert(['key'=>$key,'value'=>json_encode($value),'type'=>'array','encrypted'=>false,'settingable_type'=>$server->getMorphClass(),'settingable_id'=>$server->id,'created_at'=>$at,'updated_at'=>$at]);
    }
    foreach($steps??[$mark,$pin,$share] as $step){(require dirname(__DIR__,2).'/Domains/database/migrations/2026_11_01_'.$step.'.php')->up();}
    same(0,DB::table('settings')->whereNotNull('settingable_type')->whereIn('key',[PricingRules::KEY,CardFee::KEY])->count(),'No per-account pricing key remains');
    same(0,DB::table('settings')->where('key','domains_pricing_saved_through')->count(),'The mark is gone once it is read');
};
$rule=static fn(PricingRules $rules)=>array_diff_key($rules->defaultRule(),['card_fee'=>true]);
foreach([
    'A saved rule beats the rows 000048 wrote'=>[[[$first,$saved,'2026-10-01 00:00:00']],$saved],
    'The most recently saved rule wins'=>[[[$first,$older,'2026-10-01 00:00:00'],[$second,$saved,'2026-10-02 00:00:00']],$saved],
    'A saved 30 percent stays'=>[[[$first,$pinned,'2026-10-01 00:00:00']],$pinned],
    'A saved 30 percent newer than another saved rule wins'=>[[[$first,$older,'2026-10-01 00:00:00'],[$second,$pinned,'2026-10-02 00:00:00']],$pinned],
] as $case=>[$rows,$expected]){
    $upgrade(array_map(static fn($row)=>[$row[0],PricingRules::KEY,$row[1],$row[2]],$rows));
    same($expected,$rule($pricingRules),$case);
    same(1,Setting::whereNull('settingable_type')->where('key',PricingRules::KEY)->count(),'One business rule');
}
// Rows 000048 provably wrote were nobody's choice, so accounts that only have those take the new-account default.
$upgrade([]);
same(false,Setting::whereNull('settingable_type')->where('key',PricingRules::KEY)->exists(),'A rule 000048 wrote never moves');
same([...PricingRule::DEFAULT,'mode'=>'markup_fee_percent','markup'=>'8.00','round_up'=>'ending_99'],$rule($pricingRules),'Only rows 000048 wrote leave the new-account default');
// Without the mark before 000048 nothing proves who wrote a row, so it stays at the price someone may have chosen.
foreach(['000048 ran first'=>[$pin,$mark,$share],'No mark'=>[$pin,$share]] as $case=>$steps){$upgrade([],$steps);same($pinned,$rule($pricingRules),$case);}
$upgrade([[$first,CardFee::KEY,['percent'=>'4.00','fixed'=>'0.10'],'2026-11-01 00:00:00'],[$second,CardFee::KEY,['percent'=>'3.00','fixed'=>'0.20'],'2026-10-01 00:00:00']]);
same(['percent'=>'4.00','fixed'=>'0.10'],CardFee::read(),'The most recently saved fee moves');
$upgrade([]);
same($beforeUpgrade,$snapshot(),'Upgrade preserves TLD rules, published prices and issued invoices');
$newDefault=$pricingRules->defaultRule();
same(['markup_fee_percent','8.00','ending_99'],[$newDefault['mode'],$newDefault['markup'],$newDefault['round_up']],'New account includes card fees, 8 percent markup and rounds up to .99');
same(CardFee::DEFAULT,$newDefault['card_fee'],'New account uses the normal card fee estimate');
same('1.99',PricingRule::price('1.00',$newDefault,'register'),'One dollar cost covers the card fee estimate, markup and .99 rounding');
