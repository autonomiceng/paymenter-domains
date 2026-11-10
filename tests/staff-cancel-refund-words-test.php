<?php
// Cancel and refund: one verb, the amount in the dialog and the result, a red confirm, and the notice carries the button.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,RequestFacts};
$op=$held('staff-cancel-refund-placeholder.com');
$amount='USD '.number_format((float)$op->amount,2,'.','');
// The registrar refused the order for good (request #71).
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'order_not_supported']);
\Illuminate\Support\Facades\Auth::setUser($staff);
same('Not sent: registrar does not support this order. Cancel and refund.',Labels::reason('order_not_supported'),'The cause names the same action as the button');
same('refund',RequestFacts::notice($op->fresh())['action'],'The notice carries the Cancel and refund button');
$page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);
$html=$page->html();
expect(!str_contains($html,'add credit'),'No page text says add credit while the action says refund');
expect((bool)preg_match('/<(?:button|a)\b[^>]*class="[^"]*\bfi-color-danger\b[^"]*"[^>]*>(?:(?!<\/(?:button|a)>).)*Cancel and refund/s',$html),'The notice button is red, not primary');
$action=$page->instance()->getAction('refund');
expect(str_contains((string)$action->getModalDescription(),'refunds '.$amount.' to the original payment'),'The dialog names the amount and where it goes: '.$action->getModalDescription());
same('danger',$action->getModalSubmitAction()?->getColor(),'The destructive confirm is red');
// The result names the amount and why it went to credit.
$page->callAction('refund',['reason'=>'Registrar does not support this order'])->assertHasNoActionErrors()
    ->assertSee('Credit added: '.$amount)->assertSee('The original payment could not be refunded.');
// The cancelled domain (domain 43) is over: no request check and no Missing registrar records.
$domain=$op->domain()->firstOrFail();
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'cancelled','provider_domain_id'=>null,'provider_order_id'=>null,'provider_expires_at'=>null]);
\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($domain->fresh(),'operation_review',time(),(int)$op->id);
$families=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($domain->fresh(),new DateTimeImmutable());
same('registration',$families['cancelled_without_records'],'A paid order cancelled before it was registered has no registrar records to check');
Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$domain->id])
    ->assertSee('Registration cancelled; no registrar records to check')->assertDontSee('Registrar request needs a check');
echo "Cancel and refund words passed.\n";
$nativeSuccessful=true;
