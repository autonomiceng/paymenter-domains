<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Exercise the real planner with native legacy rows, without running the retired cutover.
const CONVERSION_DEFER_STAGE=true;
require __DIR__.'/Support/conversion.php';
use DomainsConversion\Planner;
$planner=new Planner($evidence);$summary=$planner->plan();
$expected=gmdate('Y-m-d H:i:s',$evidence['accounts'][(string)$server->id]['accepted_at']);
foreach($planner->rows['domains_sales_policies'] as $row){
    same($expected,$row['created_at']??null,'Conversion stamps selection from accepted account review');
    same($expected,$row['updated_at']??null,'Conversion records selection update time');
}
same($summary['plan_digest'],(new Planner($evidence))->plan()['plan_digest'],'Repeated conversion plan retains exact timestamps');
$nativeSuccessful=true;echo "Conversion selection timestamps remain deterministic.\n";
