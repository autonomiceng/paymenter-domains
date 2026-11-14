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
config(['domains.time_zone'=>'America/Los_Angeles']);
$at=(new DateTimeImmutable($result->attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('M j, Y, g:i A T');
$words=Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::for($result);
same(['title'=>'Enom has not answered yet','body'=>"Ask Enom to check this attempt. You can record Enom’s answer after ".$at.'.'],$words,'Staff gets a real Pacific time and next step');
$page=Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$result->id]);
$page->assertSee('Ask Enom to check this attempt.')->assertSee('You can record Enom’s answer after '.$at.'.')->assertDontSee('has not confirmed this registration')->assertDontSee('Cannot check registrar')->assertDontSee('PRIVATE-REPLY');
$customerAt=(new DateTimeImmutable($result->attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('j'); // The deadline day, whatever the date format.
$hasDeadline=static fn(string $text)=>(bool)preg_match('/(?<!\d)'.$customerAt.'(?!\d)/',$text);
require __DIR__.'/Support/owner-pages.php';
$customer=App\Models\User::findOrFail($result->domain->user_id);
Illuminate\Support\Facades\Auth::setUser($customer);
$request=Illuminate\Http\Request::create('/domains/'.$result->domain_id,'GET');
$request->setUserResolver(fn()=>$customer);$request->setLaravelSession(app('request')->session());
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$result->domain_id)->render();
$notice=storefrontDom($html)->query('//*[@role="status" and contains(@class,"domains-notice")]')->item(0);
expect($notice!==null,'Customer sees the uncertain registration notice');
expect(!str_contains($html,'Ask Enom')&&!str_contains($html,'You can record'),'Customer view has no staff registrar instruction');
expect($hasDeadline($notice->textContent),'Customer sees the real PT deadline');
expect(str_contains($notice->getAttribute('class'),'domains-notice--info'),'Waiting for our registrar check asks no customer action');
// A renewal waiting on the registrar's answer is called a renewal, not a registration (renewal-uncertain R1).
$renewal=$result->replicate();$renewal->action='renew';$said=Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::forCustomer($renewal);
expect(str_contains($said['body'],'renewal')&&!str_contains($said['title'].$said['body'],'registration')&&$hasDeadline($said['body']),'A waiting renewal says renewal, with the same deadline');
same('uncertain',$result->state,'No fabricated completion');same(null,$result->attempt['execution'],'Attempt remains unchanged');
$nativeSuccessful=true;echo "Native local status stop explains next step and real PT time.\n";
