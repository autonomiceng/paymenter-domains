<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// Lost Paid listener: native siblings continue; fair scheduler recovers only retained work.
Event::forget(App\Events\Invoice\Paid::class);expect(str_contains(NativePaidHooks::notices()['Start after payment'],'Paused'),'Missing exact Paid listener is visible');
$lost=$new(placeholderComFixtureName('paid-lost-event-placeholder.com'),paid:false);$queue=Queue::getFacadeRoot();Queue::fake();$paid($lost);same('awaiting_payment',$lost->fresh()->state,'Missing event leaves recoverable operation');
Queue::assertNotPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class);Queue::swap($queue);$work->tick();$clock->instant=$clock->now()->modify('+61 seconds');Illuminate\Support\Facades\Cache::flush();$work->operation($lost->id);same('uncertain',$lost->fresh()->state,'Scheduler recovers lost Paid wake');
Event::listen(App\Events\Invoice\Paid::class,[NativePaidHooks::class,'paid']);
// Multi-day transfer reconciliation cannot turn into a second acquisition send.
$transfer=$new(placeholderComFixtureName('paid-long-transfer-placeholder.com'),paid:false,action:'transfer');$paid($transfer);expect(in_array($transfer->fresh()->state,['pending','uncertain'],true),'Transfer entered processing on first sync job: '.$transfer->fresh()->state.' / '.$transfer->fresh()->safe_code);$sent=$writer->calls;
$clock->instant=$now->modify('+4 days');$work->operation($transfer->id);$manual($transfer);
same('Available',NativePaidHooks::notices()['Start after payment'],'Paid listener restored before direct dispatch');
$queue=Queue::getFacadeRoot();Queue::fake();
try{
    // A replayed Paid event respects transfer pacing, and the wake lands once the next read is due.
    event(new App\Events\Invoice\Paid(Invoice::find($transfer->invoice_id)));
    Queue::assertNotPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class);
    $clock->instant=(new DateTimeImmutable('@'.$transfer->fresh()->policy['reconciliation']['next_at']))->setTimezone($clock->now()->getTimezone());
    event(new App\Events\Invoice\Paid(Invoice::find($transfer->invoice_id)));
    Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===(int)$transfer->id);
}finally{Queue::swap($queue);}
same($sent,$writer->calls,'Days-long transfer reads retained attempt only');expect(in_array($transfer->fresh()->state,['pending','uncertain'],true),'Transfer remains processing until positive registry evidence');
// Remove only saving. Keep the real callbacks for restoration, including the expiry guard.
$clock->instant=$now;$missing=$new(placeholderComFixtureName('paid-no-service-hook-placeholder.com'),paid:false);
$queue=Queue::getFacadeRoot();Queue::fake();$before=$writer->calls;$paid($missing);Queue::swap($queue);
$savingEvent='eloquent.saving: '.Service::class;$saving=Event::getRawListeners()[$savingEvent];Event::forget($savingEvent);
same(['Service saving model event'],NativePaidHooks::serviceMissing(),'Only saving is missing');
$money=nativeFinancialSnapshot($missing);$operation=$missing->fresh()->getRawOriginal();
$manual($missing);$work->operation($missing->id);
same($before,$writer->calls,'Missing Service hooks pause acquisition send');
same('awaiting_payment',$missing->fresh()->state,'Missing hooks preserve unsent work without a permanent hold');
same('paid',Invoice::findOrFail($missing->invoice_id)->status,'Native payment remains paid');
same($money,nativeFinancialSnapshot($missing),'Deferred paid work preserves native and financial state');
same($operation,$missing->fresh()->getRawOriginal(),'Deferred work preserves operation bytes');
$notice='Required Service listeners are missing in this process: Service saving model event. New registrations and transfers that run here wait until a healthy process picks them up.';
same($notice,NativePaidHooks::notices()['Paymenter payment events'],'Payment events notice describes current process');
Illuminate\Support\Facades\Auth::login($staff);
expect(!isset(Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($missing->domain)['Paymenter payment events']),'Process notice lives with registrar setup, not on each domain');
$setup=Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$missing->server_id])->assertSeeInOrder(['System checks','Payment events',$check='Service listeners are missing. Restart the queue worker.',\Paymenter\Extensions\Servers\Domains\Presentation\State::Failed->label()]);
same(1,substr_count(html_entity_decode($setup->html(),ENT_QUOTES),$check),'Registrar account System checks show the fact once');
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$missing->domain_id])->assertDontSee($notice);
foreach($saving as$listener){Event::listen($savingEvent,$listener);}
same([],NativePaidHooks::serviceMissing(),'Real saving guards restored');
same('Available',NativePaidHooks::notices()['Paymenter payment events'],'Healthy payment events notice clears');
expect(!isset(Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($missing->domain)['Paymenter payment events']),'Healthy domain fact clears');
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$missing->server_id])->assertDontSee($check);
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$missing->domain_id])->assertDontSee($notice);
$manual($missing);$work->operation($missing->id);
same($before+1,$writer->calls,'Actual saving guards allow one recovered send: '.$missing->fresh()->state.' / '.$missing->fresh()->safe_code);

$nativeSuccessful=true;
