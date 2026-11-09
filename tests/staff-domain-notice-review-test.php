<?php
// Native reminder review and all fallback slots. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
staffPanel();[$held,$active,$long]=staffData();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
// One click on the domain page closes what a retry cannot: an open failed notice and an uncertain email, with no resend. Nothing open, no button.
$checked=$active->fresh();
Paymenter\Extensions\Servers\Domains\Services\SalesPolicySchema::install();Paymenter\Extensions\Servers\Domains\Services\StaffAuditSchema::install();
$rights=array_map([\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class,'permission'],['alert_review','delivery_review']);$review=staffUser([Staff::VIEW,Staff::POLICY,...$rights]);
Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionHidden('notice_review');
ObligationAlerts::record($checked->fresh(),'template_failed',time());
$uncertain=DB::table('domains_reminder_deliveries')->insertGetId(['domain_id'=>$checked->id,'delivery_key'=>hash('sha256','one-click'),'template'=>'domains_transfer_code_paid','state'=>'uncertain','created_at'=>time()]);
Livewire::actingAs(staffUser([Staff::VIEW,...$rights]))->test(ViewDomain::class,['record'=>$checked->id])->assertActionHidden('notice_review');
Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionVisible('notice_review')->assertSee('Mark reminder checked')->callAction('notice_review');
same(['reviewed',0],[DB::table('domains_reminder_deliveries')->find($uncertain)->state,DB::table('domains_obligation_alerts')->where('domain_id',$checked->id)->where('code','template_failed')->whereNull('resolved_at')->count()],'One click closes both');
Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionHidden('notice_review');
// A retained handoff needs its review button even when unreadable data removes both normal slots.
DB::table('domains_reminder_deliveries')->insert(['domain_id'=>$checked->id,'delivery_key'=>hash('sha256','fallback-review'),'template'=>'domains_transfer_code_paid','state'=>'handoff','created_at'=>time()]);
foreach (['auto-renew','alerts','neither'] as $slot) {
    if ($slot==='alerts') {
        DB::table('domains_domains')->where('id',$checked->id)->update(['renewal_policy'=>'not-encrypted']);
        ObligationAlerts::record($checked->fresh(),'template_failed',time());
    }
    if ($slot==='neither') { DB::table('domains_obligation_alerts')->where('domain_id',$checked->id)->update(['resolved_at'=>time()]); }
    $facts=DomainFacts::for($checked->fresh());
    same($slot==='auto-renew',isset($facts['Renewal invoices']),'Auto-renew slot: '.$slot);
    same($slot==='alerts',isset($facts['Open alerts']),'Open alerts slot: '.$slot);
    $page=Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionVisible('notice_review');
    same(1,substr_count(html_entity_decode(strip_tags($page->html()),ENT_QUOTES),'Mark reminder checked'),'Review button renders once with slot: '.$slot);
}
$page->callAction('notice_review');
same('reviewed',DB::table('domains_reminder_deliveries')->where('delivery_key',hash('sha256','fallback-review'))->value('state'),'Fallback review handles the retained handoff');
// A read problem leads the head, but late money and refunded-debt payments keep their words and invoice link beside it.
$money=staffUser([Staff::VIEW,'admin.invoices.update']);
$late=\App\Models\Invoice::withoutEvents(fn()=>\App\Models\Invoice::create(['user_id'=>$money->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]));
$order=$long->fresh()->latestAcquisition;DB::table('domains_operations')->where('id',$order->id)->update(['invoice_id'=>$late->id]);
$observed=$long->fresh()->observation;$observed['record_failure']=['identity'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($long->fresh()),'code'=>'domain_not_in_account','at'=>time()];
$long->update(['observation'=>$observed]);
$invoiceUrl=\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$late->id]);
$head=static function(string $payment,string $label)use($money,$long,$invoiceUrl):void {
    $html=Livewire::actingAs($money)->test(ViewDomain::class,['record'=>$long->id])->html();$text=html_entity_decode(strip_tags($html),ENT_QUOTES);
    expect(str_contains($text,'Domain is not in the registrar account.'),$label.': the read problem still leads');
    expect(str_contains($text,$payment),$label.': the payment notice stays beside the read problem');
    expect(str_contains($html,'href="'.e($invoiceUrl).'"'),$label.': the invoice link stays beside the read problem');
};
ObligationAlerts::record($long->fresh(),'acquisition_late_money',time(),$order->id);
$head('Payment activity after the order closed. Check the invoice and payments before contacting the customer.','Late money');
DB::table('domains_obligation_alerts')->where('domain_id',$long->id)->where('code','acquisition_late_money')->update(['resolved_at'=>time()]);
$replacement=$order->replicate();$replacement->fill(['operation_key'=>'notice-review-replacement','action'=>'renew','state'=>'rejected','safe_code'=>'refund_approved','invoice_id'=>$late->id,'policy'=>['renewal_replacement'=>['actor_id'=>null]]])->save();
$head('Payment received after credit. Check the invoice.','Replacement invoice');
echo "Staff domain reminder review and fallback slots passed.\n";$nativeSuccessful=true;
