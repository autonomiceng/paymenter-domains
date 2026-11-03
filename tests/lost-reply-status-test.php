<?php
// Native Check status must explain its local history stop. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/staff-panel.php';staffPanel();
$binding=$bindingFor(0);
$makeEngine($binding,static function():never{throw new RuntimeException('PRIVATE-REPLY');});
$op=$new(placeholderComFixtureName('status-lost-placeholder.com'));$result=$runRegistration($op);
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.invoices.update']);Illuminate\Support\Facades\Auth::setUser($staff);
$service=new Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation($claims,$freshBindings,$clock);
$result=$service->run($result->id)->fresh();
same('awaiting_answer',$result->policy['status_check']['outcome'],'A local rule is not a failed registrar read');
$at=(new DateTimeImmutable($result->attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('M j, g:i A').' PT';
$words=Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::for($result);
same(['title'=>'Enom has not answered yet','body'=>"Ask Enom to check this attempt. You can record Enom’s answer after ".$at.'.'],$words,'Staff gets a real Pacific time and next step');
$page=Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$result->id]);
$page->assertSee($words['title'])->assertSee($words['body'])->assertDontSee('Cannot check registrar')->assertDontSee('PRIVATE-REPLY');
same('uncertain',$result->state,'No fabricated completion');same(null,$result->attempt['execution'],'Attempt remains unchanged');
$nativeSuccessful=true;echo "Native local status stop explains next step and real PT time.\n";
