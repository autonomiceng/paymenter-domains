<?php
// Paid renewals for the staff refund browser check: two held before any send, one with an unknown registrar reply.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$all[]=Staff::COSTS;$staff->role->update(['permissions'=>$all]);$staff->update(['first_name'=>'Alex','last_name'=>'Admin']);
$held=static function(string $name)use($makeRenewal,$run):array{
    [$op,,,$invoice]=$makeRenewal(placeholderComFixtureName($name));
    same('paid',$invoice->status,'The renewal invoice is paid');$run($op,'hold');$op=$op->fresh();
    same(['held','paid_invoice','renew',[]],[$op->state,$op->funding_basis,$op->action,$op->attempt??[]],'A paid renewal is held before any send');
    return ['id'=>(int)$op->id,'invoice'=>(int)$invoice->id,'amount'=>(string)$op->amount];
};
$refund=$held('refund-browser-placeholder.com');
$failing=$held('refund-browser-failing-placeholder.com');
[$sent,,$binding,$sentInvoice]=$makeRenewal(placeholderComFixtureName('refund-browser-unknown-placeholder.com'));
[$engine,$writer]=$compose($binding);$state->lost=true;
same('uncertain',$engine->run($sent->id,$writer)->state,'A lost registrar reply leaves the renewal uncertain');$state->lost=false;
same(null,App\Models\Credit::where('user_id',$user->id)->value('id'),'No account credit exists before the browser check');
$target=(string)getenv('REFUND_BROWSER_ROOT');
file_put_contents($target.'/seed.json',json_encode(['refund'=>$refund,'failing'=>$failing,
    'unknown'=>['id'=>(int)$sent->id,'invoice'=>(int)$sentInvoice->id,'amount'=>(string)$sent->amount],'user'=>(int)$user->id,
    'email'=>$staff->email,'clock'=>$clock->now()->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
DB::statement("VACUUM INTO '".str_replace("'","''",$target.'/seed.sqlite')."'");
echo "Seeded held paid renewals #{$refund['id']} and #{$failing['id']}, uncertain renewal #{$sent->id}.\n";$nativeSuccessful=true;
