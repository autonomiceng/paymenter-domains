<?php
// The account-wide hold: one true sentence, one time for every request on the account, and the Registrars list says it too.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PaidUncertainty, Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\StaffTime;
staffPanel(); staffData(); Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
$setupPanel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($setupPanel->getPath())->middleware($setupPanel->getMiddleware())
    ->group(function()use($setupPanel){RegistrarSetup::registerRoutes($setupPanel);Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::registerRoutes($setupPanel);});
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
Auth::setUser(staffUser([Staff::VIEW]));
[$heldOp,,$longOp]=Operation::orderBy('id')->get()->all();
same($heldOp->account_fingerprint,$longOp->account_fingerprint,'Both requests sit on one registrar account');
// Two unconfirmed paid requests on one account, started 223 and 152 hours ago.
$oldest=now()->subHours(223)->startOfMinute();
DB::table('domains_operations')->where('id',$heldOp->id)->update(['state'=>'uncertain','safe_code'=>null,'funding_basis'=>'paid_invoice','started_at'=>$oldest]);
DB::table('domains_operations')->where('id',$longOp->id)->update(['state'=>'uncertain','safe_code'=>null,'funding_basis'=>'paid_invoice','started_at'=>now()->subHours(152)]);
$line=fn(Operation $op)=>(string)PaidUncertainty::holdSummary($op->fresh());
$expected='Until it is settled, only paid WHOIS privacy orders on this '.PaidUncertainty::registrar($heldOp).' account wait (on hold since '.StaffTime::at($oldest->getTimestamp()).').';
same($expected,$line($heldOp),'The hold names what it holds and when the account hold began');
same($line($heldOp),$line($longOp),'Every request on the account gives the same hold time');
expect(!str_contains($line($longOp),'Registrations and renewals'),'The line never says paid changes wait and registrations go out in one breath');
// The Registrars list shows the held account's step in amber, not a clear row.
$list=Livewire::test(RegistrarSetup::class)->instance();
$server=$list->getTableRecords()->firstWhere('id',$heldOp->server_id);
$note=$list->getTable()->getColumn('note')->record($server);
// This fixture account has no loadable settings, so the Note also says that; the held step must be there in its own words.
expect(str_contains((string)$note->getState(),'Check 2 requests. Paid WHOIS privacy orders wait.'),'The list Note names the held account step: '.$note->getState());
same('warn',$note->getExtraAttributes()['data-beco-tone']??null,'The held Note is amber');
echo "Account hold words passed.\n";
$nativeSuccessful=true;
