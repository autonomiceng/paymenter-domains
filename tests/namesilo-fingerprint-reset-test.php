<?php
// Account reset service; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-fingerprint-reset.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
// The installed account page no longer offers the retained default-contact reset.
Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$server->id])->assertSuccessful()->assertActionDoesNotExist('accept_namesilo_account');
// The account reset service keeps its full refusal and audit matrix without a page action.
$reset=app(Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountReset::class);
same(0,$wire->calls,'Opening the account page sends no provider request');
$review=$reset->preview($server->id);$details=$reset->details($server->id,$review);
same(['binding'=>$binding->fingerprint,'contact_id'=>'900','email'=>'old@example.com'],$details['pinned'],'Fresh comparison keeps the saved account');
same(['binding'=>$binding->fingerprint,'contact_id'=>'901','email'=>'new@example.com'],$details['live'],'Fresh comparison reads the current account');
same(1,$wire->calls,'Comparison requires a fresh read through the runner transport');
// A typed reason is required before another read or a local change.
$ticket=$review;$reads=$wire->calls;
try{$reset->accept($server->id,$review,'');expect(false,'Missing reason must refuse');}catch(DomainException $e){same('Enter a one-line reason between 5 and 500 characters.',$e->getMessage(),'Missing reason refused');}
same($reads,$wire->calls,'Missing reason sends no fresh submit read');
// The fixture clock advances as staff types a reason, beyond the shared one-second read interval.
$clock->instant=$clock->now()->modify('+2 seconds');
$reset->accept($server->id,$review,'Default contact changed after staff review');
$pin=$server->settings()->where('key',Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountGuard::PIN)->sole();
same(['binding'=>$binding->fingerprint,'contact_id'=>'901','email'=>'new@example.com'],json_decode($pin->value,true,8,JSON_THROW_ON_ERROR),'Explicit staff acceptance replaces the encrypted fingerprint');
expect($pin->encrypted&&!str_contains($pin->getRawOriginal('value'),'new@example.com'),'New fingerprint stays encrypted at rest');
$history=static fn()=>array_values(array_filter(Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::historyRows($server),static fn($row)=>$row['action']===Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountReset::LABEL));
$rows=$history();
same(1,count($rows),'One audit row for one accepted reset');$audit=$rows[0];
same($actor->id,$audit['actor_id'],'Audit identifies the staff actor');same('Default contact changed after staff review',$audit['reason'],'Audit retains typed reason');
same($before,$audit['before']['fingerprint'],'Audit retains pinned values');same('901',$audit['after']['fingerprint']['contact_id'],'Audit retains accepted contact ID');
same('new@example.com',$audit['after']['fingerprint']['email'],'Audit retains accepted email');
same($heldBefore,$held->fresh()->getRawOriginal(),'Held request and all attempt/funding evidence stay unchanged');
Illuminate\Support\Facades\Queue::assertNothingPushed();
$guardKey=Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountGuard::PIN;

$reject=static function(Closure $run,string $message):void {
    try{$run();expect(false,'Expected refusal: '.$message);}catch(DomainException $e){same($message,$e->getMessage(),'Specific refusal');}
};
$advance=static function()use($clock):void {$clock->instant=$clock->now()->modify('+2 seconds');};
$preview=static function()use($advance,$reset,$server):string {$advance();return $reset->preview($server->id);};
$accept=static function(string $ticket)use($advance,$reset,$server):void {$advance();$reset->accept($server->id,$ticket,'Accept reviewed default contact change');};
// A reset pin must pass the unchanged paid-call guard on the exact same wire payload.
$checkGuard=static function()use($advance,$wire,$binding,$clock):void {
    $advance();$ctx=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Interactive,
        $clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock,app(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class));
    Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountGuard::check($binding,$ctx,fn()=>$wire->body());
};
$checkGuard();
$wire->id=901;
$reject(fn()=>$preview(),'Could not read the current NameSilo default contact. Try again.');
try{$checkGuard();expect(false,'Numeric wire ID must remain held');}catch(Paymenter\Extensions\Servers\Domains\Services\WriteHeld $e){same('namesilo_account_guard',$e->reason,'Reset and paid guard refuse the same numeric wire ID');}
$wire->id='901';$checkGuard();
// Mismatch is displayed, then refused without a second provider read or a write.
$wire->email='other@example.com';$advance();
$mismatch=$reset->preview($server->id);
same('other@example.com',$reset->details($server->id,$mismatch)['live']['email'],'Review shows the mismatched current email');
$reads=$wire->calls;$saved=$server->settings()->where('key',$guardKey)->sole()->getRawOriginal();
$reject(fn()=>$accept($mismatch),'Current email does not match Expected default contact email. Nothing was saved.');
same($reads,$wire->calls,'Mismatch makes no submit read');same($saved,$server->settings()->where('key',$guardKey)->sole()->getRawOriginal(),'Mismatch retains encrypted pin');same(1,count($history()),'Mismatch adds no success audit');
$wire->email='new@example.com';
// Old tickets and contact changes cannot overwrite a later acceptance.
$reject(fn()=>$accept($ticket),'Account settings changed. Open this review again.');
$reviewed=$preview();$wire->id='902';
$reject(fn()=>$accept($reviewed),'The current contact changed. Open this review again.');
same('901',json_decode($server->settings()->where('key',$guardKey)->sole()->value,true)['contact_id'],'Unreviewed contact is never saved');
// Revocation is checked on direct calls and after the fresh read.
$reviewed=$preview();$reads=$wire->calls;$actor->role->update(['permissions'=>['admin.domains.view']]);
try{$accept($reviewed);expect(false,'Manage permission is required');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Direct acceptance denies missing manage permission');}
same($reads,$wire->calls,'Denied actor sends no read');$actor->role->update(['permissions'=>['admin.domains.view','admin.domains.manage']]);
$reviewed=$preview();$wire->during=static function()use($actor):void {$actor->role->update(['permissions'=>['admin.domains.view']]);};
try{$accept($reviewed);expect(false,'Permission must survive fresh read');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Revocation during read refuses the write');}
$wire->during=null;$actor->role->update(['permissions'=>['admin.domains.view','admin.domains.manage']]);same(1,count($history()),'Revocations add no success audit');
// Settings edited while the read is in progress refuse the previously reviewed contact.
$reviewed=$preview();$wire->during=static function()use($setExpected):void {$setExpected('other@example.com');};
$reject(fn()=>$accept($reviewed),'Account settings changed. Open this review again.');$wire->during=null;$setExpected('new@example.com');
// Audit persistence failure rolls back pin replacement and single-use key consumption.
$reviewed=$preview();
Illuminate\Support\Facades\DB::statement("CREATE TRIGGER refuse_contact_audit BEFORE INSERT ON domains_audit_archives BEGIN SELECT RAISE(ABORT, 'synthetic audit refusal'); END");
try{$accept($reviewed);expect(false,'Audit persistence failure refused');}catch(Illuminate\Database\QueryException $e){expect(str_contains($e->getMessage(),'synthetic audit refusal'),'Expected audit storage failure');}
same('901',json_decode($server->settings()->where('key',$guardKey)->sole()->value,true)['contact_id'],'Audit failure rolls back the fingerprint');
Illuminate\Support\Facades\DB::statement('DROP TRIGGER refuse_contact_audit');
$accept($reviewed);same(2,count($history()),'Same review remains usable after an atomic rollback');
// Two overlapping reviews: the commit during the first read wins, and the stale writer refuses.
// Synthetic interleaving checks the locked comparison; SQLite cannot prove production row-lock scheduling.
require_once __DIR__.'/Support/WireLimit.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new WireLimit(100));
$reset=app(Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountReset::class);
$wire->id='903';$first=$preview();$second=$preview();
$wire->during=static function()use($wire,$reset,$server,$second):void {$wire->during=null;$reset->accept($server->id,$second,'Concurrent reviewed default contact change');};
$reject(fn()=>$accept($first),'Account settings changed. Open this review again.');
same(3,count($history()),'Overlapping reviews yield one audit and one pin replacement');
same('Concurrent reviewed default contact change',array_slice($history(),-1)[0]['reason'],'Winning review owns the audit');
same('903',json_decode($server->settings()->where('key',$guardKey)->sole()->value,true)['contact_id'],'Stale reset cannot overwrite winning values');
$expired=$preview();$clock->instant=$clock->now()->modify('+301 seconds');
$reject(fn()=>$accept($expired),'Open the account review again.');
$wire->failed=true;$advance();
$reject(fn()=>$reset->preview($server->id),'Could not read the current NameSilo default contact. Try again.');
same($heldBefore,$held->fresh()->getRawOriginal(),'Every refusal and acceptance keeps held operations unchanged');
Illuminate\Support\Facades\Queue::assertNothingPushed();
echo "Account reset, refusals, audit rollback and overlapping review checks passed.\n";$nativeSuccessful=true;
