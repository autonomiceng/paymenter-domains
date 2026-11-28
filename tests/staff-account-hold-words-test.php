<?php
// The account-wide hold: no line for a product we don't sell, and the Registrars list names the step without it.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PaidUncertainty, Staff};
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionWindow, OperationWriter, SealTerms, StepKey};
(new Domains([]))->installed();
staffPanel(); staffData();
$setupPanel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($setupPanel->getPath())->middleware($setupPanel->getMiddleware())
    ->group(function()use($setupPanel){RegistrarSetup::registerRoutes($setupPanel);Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::registerRoutes($setupPanel);});
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
Auth::setUser(staffUser([Staff::VIEW]));
[$heldOp,,$longOp]=Operation::orderBy('id')->get()->all();
same($heldOp->account_fingerprint,$longOp->account_fingerprint,'Both requests sit on one registrar account');
// Two unconfirmed paid requests with sealed transfer deadlines.
foreach ([[$heldOp, 223], [$longOp, 152]] as [$order, $hours]) {
    DB::transaction(static function () use ($order, $hours): void {
        $request = Operation::whereKey($order->id)->lockForUpdate()->firstOrFail();
        DB::table('domains_operations')->where('id', $order->id)->update([
            'state' => 'uncertain', 'safe_code' => null, 'funding_basis' => 'paid_invoice', 'attempt' => null,
        ]);
        $start = now()->subHours($hours)->startOfMinute()->toDateTimeImmutable();
        $end = $start->modify('+5 minutes');
        $writer = OperationWriter::at($start);
        $digest = hash('sha256', $order->operation_key);
        $token = $writer->claim((int) $order->id, new StepKey('transfer', 1), $end, ['digest' => $digest]);
        $window = new AcquisitionWindow(1, 'claim', null, $token->attemptId, $start, $end, null, 'write_rate_limited');
        $writer->seal($token, new SealTerms($request->transition_revision, $digest, new Money('8.00', 'USD'), 'transfer', $end),
            ['window' => $window->seal(true)]);
    });
}
same(\Paymenter\Extensions\Servers\Domains\Presentation\State::Delayed, PaidUncertainty::waitState($heldOp->fresh()), 'An overdue transfer asks staff to check it');
same(\Paymenter\Extensions\Servers\Domains\Presentation\State::Checking, PaidUncertainty::waitState($longOp->fresh()), 'A transfer inside its normal window waits on the registrar');
$line=fn(Operation $op)=>(string)PaidUncertainty::holdSummary($op->fresh());
same(['',''],[$line($heldOp),$line($longOp)],'While WHOIS privacy is not sold the request has no hold line');
// The Registrars list shows the held account's step in amber, not a clear row.
$list=Livewire::test(RegistrarSetup::class)->instance();
$server=$list->getTableRecords()->firstWhere('id',$heldOp->server_id);
$note=$list->getTable()->getColumn('note')->record($server);
// This fixture account has no loadable settings, so the Note also says that; the held step must be there in its own words.
expect(str_contains((string)$note->getState(),'Check 2 requests. No registrar result yet.'),'The list Note names the held account step: '.$note->getState());
expect(!str_contains((string)$note->getState(),'WHOIS privacy'),'The list Note never names WHOIS privacy');
same('warn',$note->getExtraAttributes()['data-domains-tone']??null,'The held Note is amber');
// One request reads the full hold snapshot once, including empty account results.
$attributes=request()->attributes;
$attributes->remove('domains.paid_uncertainty.holds');
DB::flushQueryLog();DB::enableQueryLog();
$step=PaidUncertainty::accountStep((int)$heldOp->server_id);
$holdReads=static fn()=>array_values(array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'domains_')));
$reads=$holdReads();
same($step,PaidUncertainty::accountStep((int)$heldOp->server_id),'Sibling rows reuse the same hold step');
same(null,PaidUncertainty::accountStep(999999),'An account outside the holds stays clear');
same($reads,$holdReads(),'Additional registrar rows do not repeat hold queries');
DB::disableQueryLog();
// A fresh request must see an updated result rather than an earlier snapshot.
$attributes->remove('domains.paid_uncertainty.holds');
DB::table('domains_operations')->whereIn('id',[$heldOp->id,$longOp->id])->update(['state'=>'succeeded']);
same(null,PaidUncertainty::accountStep((int)$heldOp->server_id),'The next request sees settled holds');
echo "Account hold words passed.\n";
$nativeSuccessful=true;
