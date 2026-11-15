<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/Support/staff-operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory;
$op=$held('walk3-activity-placeholder.com');$run($op,'refund');
$events=array_column(array_filter(DomainHistory::entries($op->domain->fresh()),'is_array'),'event');
expect(in_array('Cancelled',$events,true),'Activity retains the cancellation');
expect(in_array('Credit added',$events,true),'Activity retains the completed payment return');
$nativeSuccessful=true;
