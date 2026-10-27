<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Services\{CardFee, MarginPolicy, PricingRule, RetailLine};

$rule = PricingRule::DEFAULT;
same('13.00', PricingRule::price('10.0000', $rule, 'register'), 'Default percent');
foreach (['none' => '10.01', 'whole' => '11.00', 'half' => '10.50', 'ending_99' => '10.99'] as $round => $price) {
    same($price, PricingRule::price('10.0050', [...$rule, 'markup' => '0', 'round_up' => $round], 'register'), 'Rounding '.$round);
}
same('10.99', PricingRule::price('10.9900', [...$rule, 'markup' => '0', 'round_up' => 'ending_99'], 'register'), 'Exact .99 unchanged');
same('11.99', PricingRule::price('10.9901', [...$rule, 'markup' => '0', 'round_up' => 'ending_99'], 'register'), 'Above .99 rounds up');
same('12.50', PricingRule::price('10.0000', [...$rule, 'mode' => 'markup_amount', 'markup' => '2.50'], 'renew'), 'Fixed markup');
same('3.00', PricingRule::total('1.00', 3), 'Retail rounds per year before multiplication');
same('1.00', PricingRule::price('0.9960', [...$rule, 'markup' => '0'], 'register'), 'Per-year cent rounding');
$reject = static function (callable $call): void {
    try { $call(); expect(false, 'Invalid pricing escaped'); } catch (InvalidArgumentException|RuntimeException $e) {
        if ($e->getMessage() === 'Invalid pricing escaped') { throw $e; }
    }
};
foreach (['-1', '1e2', 'NaN', '1.00001', ' 1', '100000000.0000'] as $invalid) {
    $reject(static fn () => PricingRule::price($invalid, $rule, 'renew'));
}
$reject(static fn () => PricingRule::price('99999999.9999', [...$rule, 'markup' => '100000.00'], 'register'));
$reject(static fn () => PricingRule::total('9999999999.99', 10));
$reject(static fn () => PricingRule::minor(1.001));
$reject(static fn () => PricingRule::total('1.00', 0));
$reject(static fn () => PricingRule::validate([...$rule, 'mode' => 'fixed', 'fixed' => ['register' => '0.00']]));
same(-123, PricingRule::minor('-1.23'), 'Signed native adjustment');
same(123, PricingRule::minor(1.23), 'Exact native float compatibility');
same(null, PricingRule::wholesale('broken'), 'Malformed wholesale unknown');
$fixed = [...$rule, 'mode' => 'fixed', 'fixed' => ['register' => '5.00', 'renew' => '6.00', 'transfer' => '7.00']];
same('6.00', PricingRule::price('10.0000', $fixed, 'renew'), 'Fixed action price');
foreach (['register', 'transfer'] as $action) {
    $decision = MarginPolicy::assess($action, '10.0001', '10.00');
    same(false, $decision->publishable, 'Subcent below-cost acquisition held');
    same('below_cost', $decision->staffAlert, 'Staff acquisition alert');
}
$renew = MarginPolicy::assess('renew', '10.0001', '10.00');
same(true, $renew->publishable, 'Below-cost renewal continues');
same(null, $renew->hold, 'No renewal floor');
same('below_cost', $renew->staffAlert, 'Renewal staff alert');
same(false, MarginPolicy::assess('renew', null, '10.00')->publishable, 'Unknown cost never zero');
same(true, MarginPolicy::assess('register', '0.0000', '0.01')->publishable, 'Known free wholesale');
same(false, MarginPolicy::assess('register', '0.0000', '0.00')->publishable, 'Zero retail not complimentary authority');
same(false, MarginPolicy::assess('register', '10.0000', '9.00')->publishable, 'Tax cannot cover below-cost published list price');
same(true, RetailLine::positiveNet('12.00', '2.00', '1.00'), 'Positive discounted line may be below cost');
same(true, MarginPolicy::assess('register', '10.0000', '10.00')->publishable, 'Discounts never change published margin');
same(false, RetailLine::positiveNet('12.00', '2.00', '10.00'), 'Zero net line refused separately from margin');
$unknownRenewal = MarginPolicy::assess('renew', null, '10.00');
same(false, $unknownRenewal->publishable, 'Unknown renewal retains prior published price');
same(null, $unknownRenewal->hold, 'Unknown cost never creates renewal hold');
same('unknown_price', $unknownRenewal->staffAlert, 'Staff must resolve unknown renewal');
// Fee-inclusive markup covers the fee on the final rounded price.
$feeRule = [...$rule, 'mode' => 'markup_fee_percent', 'markup' => '5.00', 'round_up' => 'ending_99',
    'card_fee' => ['percent' => '2.90', 'fixed' => '0.30']];
foreach (['2' => '2.99', '14' => '15.99', '40' => '43.99', '80' => '86.99'] as $cost => $expected) {
    same($expected, PricingRule::price((string)$cost, $feeRule, 'register'), 'Fee-inclusive table '.$cost);
}
same('260.97', PricingRule::total(PricingRule::price('80', $feeRule, 'register'), 3), 'Fee retail rounds before multiplying years');
same('1.00', PricingRule::price('1', [...$feeRule, 'markup'=>'0', 'round_up'=>'none', 'card_fee'=>['percent'=>'0','fixed'=>'0']], 'renew'), 'Exact fee boundary');
same('1.01', PricingRule::price('1.0001', [...$feeRule, 'markup'=>'0', 'round_up'=>'none', 'card_fee'=>['percent'=>'0','fixed'=>'0']], 'renew'), 'Fee mode never rounds below required price');
same('0.99', PricingRule::price('0.9900', [...$feeRule, 'markup'=>'0', 'card_fee'=>['percent'=>'0','fixed'=>'0']], 'renew'), 'Exact .99 fee boundary');
same('1.99', PricingRule::price('0.9901', [...$feeRule, 'markup'=>'0', 'card_fee'=>['percent'=>'0','fixed'=>'0']], 'renew'), 'Above .99 fee boundary');
$reject(static fn()=>PricingRule::price('1', [...$feeRule, 'markup'=>'5', 'card_fee'=>['percent'=>'99','fixed'=>'0']], 'renew'));
$reject(static fn()=>PricingRule::price('99999999.9999', [...$feeRule, 'markup'=>'100000', 'card_fee'=>['percent'=>'0','fixed'=>'0']], 'renew'));
$reject(static fn()=>PricingRule::validate([...$feeRule, 'card_fee'=>['percent'=>'100','fixed'=>'0']]));
foreach (['validate', 'describe', 'price'] as $method) {
    $missingFee=$feeRule;unset($missingFee['card_fee']);
    $reject(static fn()=>$method==='price'?PricingRule::price('6',$missingFee,'register'):PricingRule::$method($missingFee));
}
$reject(static fn()=>PricingRule::validate([...$feeRule,'card_fee'=>'invalid']));
same('0.6032', \Paymenter\Extensions\Servers\Domains\Services\CardFee::profit('2.99','2.0000',$feeRule['card_fee']), 'Rough profit includes fee on final price');
same(true, \Paymenter\Extensions\Servers\Domains\Services\CardFee::losesMoney('2.00','2.0000',$feeRule['card_fee']), 'Separate fee loss above registrar cost');
same('100000000.00', PricingRule::price('99999999.9999', [...$feeRule,'markup'=>'0','round_up'=>'none','card_fee'=>['percent'=>'0','fixed'=>'0']], 'register'), 'Large supported cost rounds safely');
same('2.9% + $0.30',CardFee::describe(['percent'=>'2.9','fixed'=>'0.3']),'Shared fee description validates and canonicalizes input');
same('0% + $0.00',CardFee::describe(['percent'=>'0','fixed'=>'0']),'Zero fee description');
same('10% + $1.00',CardFee::describe(['percent'=>'10','fixed'=>'1']),'Whole percent description keeps significant zero');
$reject(static fn()=>CardFee::describe(['percent'=>'100','fixed'=>'0']));
echo "Exact pricing and margin checks passed.\n";
