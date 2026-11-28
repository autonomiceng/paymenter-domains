<?php
// Native reminder review and all fallback slots. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionResultSchema;
use Illuminate\Support\Facades\{DB,Route};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainDecisions;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingReadRetry, ObligationAlerts, StaffOperationReview};
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
staffPanel();[$held,$active,$long]=staffData();
// Register the account destination the read-problem notice links to, as the installed panel does.
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->pages([RegistrarAccount::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>RegistrarAccount::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
// One click on the domain page closes what a retry cannot: an open failed notice and an uncertain email, with no resend. Nothing open, no button.
$checked=$active->fresh();
\Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferSchema::install();Paymenter\Extensions\Servers\Domains\Services\StaffAuditSchema::install();
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
    same($slot==='auto-renew',isset($facts['Renewal']),'Auto-renew slot: '.$slot);
    same($slot==='alerts',isset($facts['Open alerts']),'Open alerts slot: '.$slot);
    $page=Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionVisible('notice_review');
    same(1,substr_count(html_entity_decode(strip_tags($page->html()),ENT_QUOTES),'Mark reminder checked'),'Review button renders once with slot: '.$slot);
}
$page->callAction('notice_review');
same('reviewed',DB::table('domains_reminder_deliveries')->where('delivery_key',hash('sha256','fallback-review'))->value('state'),'Fallback review handles the retained handoff');
// An established holding's read problem and its invoice stay actionable together.
$long->update(['status'=>'active']);
$money=staffUser([Staff::VIEW,'admin.invoices.update']);
$late=\App\Models\Invoice::withoutEvents(fn()=>\App\Models\Invoice::create(['user_id'=>$money->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]));
$order=$long->fresh()->latestAcquisition;DB::table('domains_operations')->where('id',$order->id)->update(['invoice_id'=>$late->id,'state'=>'succeeded','safe_code'=>null]);
$work=DB::table('domains_observation_work')->insertGetId(['server_id'=>$long->server_id,'account_fingerprint'=>$long->account_fingerprint,
    'domain_id'=>$long->id,'generation'=>$long->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>5]);
$invoiceUrl=\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$late->id]);
$accountUrl=RegistrarAccount::getUrl(['account'=>$long->server_id]);
$head=static function(string $label)use($money,$long,$invoiceUrl,$accountUrl):void {
    $read=HoldingReadRetry::status($long->fresh());
    same([true,'read_failed'],[$read['read_review'],$read['read_code']],$label.': the exhausted read needs review');
    $html=Livewire::actingAs($money)->test(ViewDomain::class,['record'=>$long->id])->html();
    expect(str_contains($html,'href="'.e($invoiceUrl).'"'),$label.': the invoice stays actionable');
    expect(in_array($accountUrl,panelNoticeLinks($html),true),$label.': the read problem keeps its account link');
};
ObligationAlerts::record($long->fresh(),'acquisition_late_money',time(),$order->id);
$head('Late money');
DB::table('domains_obligation_alerts')->where('domain_id',$long->id)->where('code','acquisition_late_money')->update(['resolved_at'=>time()]);
$replacement=$order->replicate();$replacement->fill(['operation_key'=>'notice-review-replacement','action'=>'renew','state'=>'rejected','safe_code'=>'refund_approved','invoice_id'=>$late->id,'policy'=>['renewal_replacement'=>['actor_id'=>null]]])->save();
$head('Replacement invoice');
// A stopped record read names its request beside the invoice link: staff need both.
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>'records_incomplete','tries'=>1]);
\Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords::hold($long->fresh(),'glue_unavailable',new DateTimeImmutable('@'.time()));
Illuminate\Support\Facades\Auth::setUser($money);$stopped=DomainFacts::notice($long->fresh());
same(true,$stopped['resume']??false,'The stopped read offers the resume path');
expect(!DomainDecisions::syncNow($long->fresh()),'This viewer cannot sync the held domain');
$step=(string)DomainFacts::head($long->fresh(),DomainFacts::status($long->fresh()),DomainFacts::for($long->fresh()),(int)$order->id)['step'];
expect(str_contains($step,'href="'.e($invoiceUrl).'"'),'The invoice link stays beside a stopped read that names a request');
$requestUrl=\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl('view',['record'=>$order->id]);
expect(str_contains($step,'href="'.e($requestUrl).'"'),'The stopped read keeps the request link');
$resume=staffUser([Staff::VIEW,StaffOperationReview::permission('resume_observation')]);
Livewire::actingAs($resume)->test(ViewDomain::class,['record'=>$long->id])->assertActionVisible('resume_observation');
// One page render reads the payment notice once. The replacement history loads its invoice items per read.
$items=0;DB::listen(function($q)use(&$items):void{if(preg_match('/from "invoice_items" where "invoice_id" in/',$q->sql)){$items++;}});
$page=Livewire::actingAs($money)->test(ViewDomain::class,['record'=>$long->id]);
same(1,$items,'The page reads the payment notice once per render');
// A refresh reads it again: the same page drops the notice once the payment is resolved.
expect(str_contains(panelNoticesText($page->html()),'Payment received after credit.'),'The page shows the payment notice before it is resolved');
DB::transaction(static function () use ($replacement): void {
    DB::table('domains_operations')->where('id',$replacement->id)->update(['action'=>'register']);
    AcquisitionResultSchema::insert((int) $replacement->id);
});
expect(str_contains(panelNoticesText($page->html()),'Payment received after credit.'),'Within one render the page reuses the notice it read');
$page->call('pollManagement');
expect(!str_contains(panelNoticesText($page->html()),'Payment received after credit.'),'A refresh reads the notice again');
echo "Staff domain reminder review and fallback slots passed.\n";$nativeSuccessful=true;
