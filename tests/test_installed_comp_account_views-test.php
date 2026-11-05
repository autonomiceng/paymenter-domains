<?php
// Native Filament, invoice Blade/PDF and revenue queries cannot be proved with a service-only fixture.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
expect(is_file(__DIR__.'/../Domains/Services/ComplimentaryAccounts.php'),'Complimentary account views must be implemented');
// Native checkout, invoice observers and retained financial locks require the real Paymenter fixture.
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,DB,View};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{ComplimentaryAccountSetting,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains,ViewDomain};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryCosts,OwnedDomains,OwnerPorts};
require dirname(__DIR__).'/Domains/routes/owned.php';
staffPanel();app()->instance(Clock::class,$clock);ComplimentaryAccountSetting::boot();
$staff=staffUser([Staff::VIEW,Staff::COSTS,'admin.domains.manage','admin.domains.fund','admin.users.viewAny','admin.users.view','admin.users.update']);
$report=new class extends App\Admin\Widgets\Revenue {public function chart():array{return $this->getData();}};
$before=null;
[$op,$d,$binding,$invoice]=$makeRenewal(placeholderComFixtureName('account-invoice-placeholder.com'),false,1,false,function()use(&$before,$report,$user,$staff,$clock){$before=$report->chart()['datasets'];ComplimentaryAccounts::set($user->id,$staff,true,'Gift account',$clock);});
// Cost approval copy must name a usable next step for each real staff permission set.
$op->update(['state'=>'held','safe_code'=>'registrar_price_increased','policy'=>[...$op->policy,'complimentary_cost_review'=>['amount'=>'8.01','usual'=>'8.00']]]);
$managed=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]);
$managed->assertSee('Use Approve registrar cost to continue. Nothing is charged until approval.')->assertActionVisible('approveComplimentaryCost');
$costManagedHtml=$managed->html();
$fundOnly=staffUser([Staff::VIEW,Staff::COSTS,'admin.domains.fund']);
$limited=Livewire::actingAs($fundOnly)->test(ViewDomain::class,['record'=>$d->id]);
$limited->assertSee('Ask staff with domain management and funding permissions to approve this cost. Nothing is charged until approval.')->assertActionHidden('approveComplimentaryCost');
$costFundOnlyHtml=$limited->html();
$policy=$op->fresh()->policy;unset($policy['complimentary_cost_review']);$op->refresh()->update(['state'=>'queued','safe_code'=>null,'policy'=>$policy]);
$runRenewal($op,$binding);[$engine,$writer,$completion]=$compose($binding);$state->expiry='2028-10-18';$state->after='2028-10-18';$state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Real confirmed renewal charge supplies the customer receipt');$invoice=$invoice->fresh();
same($before,$report->chart()['datasets'],'Complimentary positive and negative lines do not create reported revenue');
$editor=Livewire::actingAs($staff)->test(App\Admin\Resources\UserResource\Pages\EditUser::class,['record'=>$user->id]);$editor->assertSee('Domains are complimentary')->assertSee('Reason');
$staffHtml=$editor->html();
$plain=staffUser(['admin.users.viewAny','admin.users.view','admin.users.update']);Livewire::actingAs($plain)->test(App\Admin\Resources\UserResource\Pages\EditUser::class,['record'=>$user->id])->assertDontSee('Domains are complimentary');
Auth::login($staff);$domainPage=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]);$domainPage->assertSee(ComplimentaryAccounts::LABEL)->assertSee('Standard renewal price USD 10.40 / year, complimentary')->assertSee('Beco covers registrar costs.');$domainHtml=$domainPage->html();
$list=Livewire::actingAs($staff)->test(ListDomains::class)->filterTable('price_mode','complimentary');$list->assertCanSeeTableRecords([$d]);
Auth::login($user);$owner=(new OwnedDomains($clock,new OwnerPorts))->present($d->fresh());same(ComplimentaryAccounts::LABEL,$owner->renewalPrice,'Customer receives account complimentary label');
$ownerHtml=view('domains::partials.owner-renew',['domain'=>$owner,'support'=>null,'keys'=>array_fill_keys(['auto-renew','renew'],'synthetic-review-key')])->render();expect(str_contains($ownerHtml,ComplimentaryAccounts::LABEL),'Customer domain renders the label');expect(!str_contains($ownerHtml,'We send you an invoice and you pay it'),'Complimentary customer is never asked to pay');
$show=Livewire::actingAs($user)->test(App\Livewire\Invoices\Show::class,['invoice'=>$invoice]);
$show->assertSee('Complimentary')->assertSee('$-8.00')->assertSee('$0.00')->assertSee('Paid');$invoiceHtml=$show->html();
$beforePayments=App\Models\InvoiceTransaction::count();$show->set('selectedMethod','credit')->call('processPayment');same($beforePayments,App\Models\InvoiceTransaction::count(),'A paid zero receipt cannot start native payment');
$pdfHtml=view('pdf.invoice',['invoice'=>$invoice->fresh()])->render();expect(str_contains($pdfHtml,'$-8.00')&&str_contains($pdfHtml,'$0.00')&&str_contains($pdfHtml,'Complimentary'),'Paymenter PDF view preserves the negative line and zero total');
$out=getenv('COMP_ACCOUNT_RENDER_DIR');
if($out){
    expect(is_dir($out),'Existing review artifact directory required');
    writeRenderArtifact($out.'/staff-switch.html',$staffHtml);writeRenderArtifact($out.'/staff-domain.html',$domainHtml);
    writeRenderArtifact($out.'/customer-domain.html',$ownerHtml);writeRenderArtifact($out.'/customer-invoice.html',$invoiceHtml);writeRenderArtifact($out.'/invoice-pdf.html',$pdfHtml);
    $pdf=App\Classes\PDF::generateInvoice($invoice->fresh());writeRenderArtifact($out.'/customer-invoice.pdf',$pdf->output());
    writeRenderArtifact($out.'/reports.json',json_encode(['before'=>$before,'after'=>$report->chart()['datasets'],'invoice_total'=>(float)$invoice->total,'transactions'=>$invoice->transactions()->count()],JSON_PRETTY_PRINT));
}
echo "Native staff switch, domain filter, customer invoice, negative PDF line and revenue reporting passed\n";$nativeSuccessful=true;
