<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff render of bounded retained diagnostics.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RequestFacts,Staff};
staffPanel();$staff=staffUser([Staff::VIEW,Staff::METADATA]);
$makeEngine($bindingFor(0),static fn()=>'<interface-response><Command>Purchase</Command><Done>true</Done><RRPCode>545</RRPCode><ErrCount>1</ErrCount><errors><Err1>Error 545 - Name server does not exist; PRIVATE-CONTACT</Err1></errors></interface-response>');
$op=$runRegistration($new('staff-diagnostic-placeholder.com'));
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee('Code 545: Error 545 - Name server does not exist')->assertDontSee('PRIVATE-CONTACT');
require __DIR__.'/Support/registration-refusal-reply.php';
$makeEngine($bindingFor(0),static fn()=>$purchaseRefusal);
$refused=$runRegistration($new('staff-refused-placeholder.com'));
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$refused->id])->assertSee('Code 541: Validation error. Failed to create order.')->assertDontSee('PRIVATE-CONTACT');
$original=$op->getRawOriginal('attempt');
foreach([['code'=>'545','text'=>'PRIVATE-SECRET'],['code'=>'<script>','text'=>null],['code'=>'545','text'=>null,'raw'=>'PRIVATE-CONTACT']] as $invalid){
    $attempt=$op->attempt;$attempt['evidence']['registrarReply']=$invalid;
    // Test-only retained corruption; production attempt immutability remains in force.
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($attempt))]);
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertDontSee('Code 545')->assertDontSee('PRIVATE-')->assertDontSee('<script>',false);
}
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>$original]);
$staff->role->update(['permissions'=>[Staff::VIEW]]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertDontSee('Code 545')->assertDontSee('Error 545');
$staff->role->update(['permissions'=>[]]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertForbidden();
echo "Native permission-gated registrar diagnostics passed\n";$nativeSuccessful=true;
