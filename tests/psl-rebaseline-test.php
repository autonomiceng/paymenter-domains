<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php'; require __DIR__.'/Support/psl.php'; require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{PslSchema,PslReviewSchema,PslValidator,PslRebaseline,PslTransport,PublicSuffixList as Lists,PslFailure};
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
PslSchema::install(); PslReviewSchema::install(); $clock=new PslClock; $v=new PslValidator(128,5,1); $body=pslFixture();
$headersSeen=[];$status=200;$nested=null;
$lists=new Lists($clock,new PslTransport(function($url,$headers)use(&$body,&$headersSeen,&$status,&$nested){if($nested){$nested();}$headersSeen=$headers;return ['status'=>$status,'body'=>$body];}),$v);
$reviews=new PslRebaseline($clock,$v);same('updated',$lists->update(),'Initial validated list');$original=$lists->revision();
$body=pslFixture(30,30);$clock->at+=86400;same('rules_lost_icann',$lists->update(),'Removal rejected');
$c=$reviews->pending();$hash=hash('sha256',$body);same($hash,$c->revision,'Exact rejected bytes retained');same($original,$lists->revision(),'Never auto applies');
$preview=$reviews->preview();same(10,$preview['removed']['icann']['count'],'ICANN missing count');same(10,$preview['removed']['private']['count'],'Private missing count');
$staff=staffUser([Staff::VIEW,'admin.domains.watch.view']);
$refused=false;try{$reviews->accept($staff,$hash,substr($hash,0,12),'Reviewed');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$refused=$e->getStatusCode()===403;}expect($refused,'Default manage denial');
$staff->role()->first()->update(['permissions'=>[Staff::VIEW,'admin.domains.watch.view','admin.domains.watch.manage']]);
foreach ([[$hash,'wrong','Reviewed'],[$hash,substr($hash,0,12),''],[$hash,substr($hash,0,12),str_repeat('é',1001)],[$hash,substr($hash,0,12),"valid\xff"],[str_repeat('f',64),str_repeat('f',12),'Reviewed']] as [$h,$confirmation,$reason]) {
    $refused=false;try{$reviews->accept($staff,$h,$confirmation,$reason);}catch(PslFailure){$refused=true;}expect($refused,'Hash, confirmation and reason required');same($original,$lists->revision(),'Refusal retains good copy');
}
// Even hash-consistent privileged replacement cannot bypass current sentinel validation.
$bad=str_replace("\ncom\n","\n",$body);DB::table('domains_psl_candidates')->update(['revision'=>hash('sha256',$bad),'compressed_body'=>base64_encode(gzencode($bad))]);
$refused=false;try{$reviews->accept($staff,hash('sha256',$bad),substr(hash('sha256',$bad),0,12),'Reviewed');}catch(PslFailure $e){$refused=$e->safeCode==='sentinel_missing';}expect($refused,'Sentinels never bypassable');
DB::table('domains_psl_candidates')->update(['revision'=>$hash,'compressed_body'=>base64_encode(gzencode($body))]);
$reviews->accept($staff,$hash,substr($hash,0,12),str_repeat('🦦',1000));same($hash,$lists->revision(),'Accepted exact candidate becomes last good');same(null,$reviews->pending(),'Consumed candidate');same(0,(int)$lists->status()->consecutive_failures,'Acceptance clears unsuccessful-attempt count');
$audit=WatchAudit::firstOrFail();same('psl_rebaseline',$audit->event,'Audit event');same($staff->id,$audit->actor_id,'Audit actor');same($original,$audit->before_revision,'Audit baseline');same($hash,$audit->after_revision,'Audit exact hash');same(10,$audit->data['removed']['icann']['count'],'Encrypted review evidence');
foreach ([fn()=>$audit->update(['reason'=>'rewrite']),fn()=>$audit->delete()] as $mutation) { $refused=false;try{$mutation();}catch(DomainException){$refused=true;}expect($refused,'Audit append-only'); }
// Simulate a previously validated rule now refused by ICU; no invalid new rule is accepted.
$historical=str_replace('i29.example','a_b.example',$body);DB::table('domains_psl_snapshots')->where('state','current')->update(['revision'=>hash('sha256',$historical),'compressed_body'=>base64_encode(gzencode($historical))]);
DB::table('domains_psl_snapshots')->where('state','current')->update(['etag'=>'"icu-old"']);
same(false,$lists->discoveryAllowed(),'Unparseable copy holds discovery immediately');
same('Saved list needs review',\Paymenter\Extensions\Servers\Domains\Admin\Support\PslFacts::state($lists),'Parser recovery state');
$status=304;$clock->at+=86400;same('unexpected_304',$lists->update(),'Unconditional response cannot claim not modified');same([],$headersSeen,'ICU-incompatible baseline forces full download');same(null,$reviews->pending(),'304 has no review bytes');
$status=200;$clock->at+=86400;same('stored_rules_rejected',$lists->update(),'ICU-incompatible saved rule held');same([],$headersSeen,'Recovery still unconditional');expect($reviews->preview()['icu_incompatible'],'Explicit historical parser review');same(['a_b.example'],$reviews->preview()['rejected']['icann']['samples'],'Actual parser-rejected rules shown');
$c=$reviews->pending();$validBody=$body;$body=str_replace("\ncom\n","\n",$body);$clock->at+=86400;
same('sentinel_missing',$lists->update(),'Invalid new download reports its own error with older ICU candidate');same($c->revision,$reviews->pending()->revision,'Invalid response never relabels an old candidate');
$body=$validBody;$reviews->accept($staff,$c->revision,substr($c->revision,0,12),'Reviewed parser compatibility');same(hash('sha256',$body),$lists->revision(),'Valid replacement restores last good');
// An accepted newer baseline invalidates an older pending review, even if its bytes remain stored.
$body=pslFixture(10,10);$clock->at+=86400;same('rules_lost_icann',$lists->update(),'Second removal pending');$stale=$reviews->pending();
$body=pslFixture(31,31);$clock->at+=86400;same('updated',$lists->update(),'New normal validation supersedes baseline');same(null,$reviews->preview(),'Stale review hidden');
DB::table('domains_psl_candidates')->insert((array)$stale);same(null,$reviews->preview(),'Retained candidate with mismatched baseline hidden');
$refused=false;try{$reviews->accept($staff,$stale->revision,substr($stale->revision,0,12),'Old review');}catch(PslFailure $e){$refused=$e->safeCode==='candidate_changed';}expect($refused,'Stale baseline cannot be accepted');
// A delayed review cannot make downloaded bytes younger than they are.
$body=pslFixture(10,10);$clock->at+=86400;same('rules_lost_icann',$lists->update(),'Delayed candidate');$aged=$reviews->pending();$clock->at+=91*86400;
$reviews->accept($staff,$aged->revision,substr($aged->revision,0,12),'Reviewed old download');same(false,$lists->discoveryAllowed(),'Acceptance never refreshes old download age');
// Exact compressed bytes are independently digest-checked before any acceptance.
$body=pslFixture(6,6);$clock->at+=86400;same('rules_lost_icann',$lists->update(),'In-flight candidate prepared');$c=$reviews->pending();
DB::table('domains_psl_candidates')->update(['compressed_body'=>base64_encode(gzencode($body."\n"))]);
$refused=false;try{$reviews->accept($staff,$c->revision,substr($c->revision,0,12),'Tampered bytes');}catch(PslFailure $e){$refused=$e->safeCode==='stored_copy_invalid';}expect($refused,'Candidate stored-body hash independently checked');
DB::table('domains_psl_candidates')->update(['compressed_body'=>$c->compressed_body]);
// Acceptance invalidates the durable token of an already downloading update.
$nested=function()use($reviews,$staff,$c){$reviews->accept($staff,$c->revision,substr($c->revision,0,12),'Reviewed while update downloads');};
$body=pslFixture(40,40);$clock->at+=86400;same('superseded',$lists->update(),'In-flight download cannot overwrite acceptance');$nested=null;
same($c->revision,$lists->revision(),'Accepted candidate wins concurrent update');
// Invalid new data and damaged historical bytes never become a reviewable candidate.
$body=str_replace("\ncom\n","\n",pslFixture(6,6));$clock->at+=86400;same('sentinel_missing',$lists->update(),'Sentinel failure rejected at download');same(null,$reviews->pending(),'Invalid download not retained for acceptance');
DB::table('domains_psl_snapshots')->where('state','current')->update(['compressed_body'=>'damaged']);$body=pslFixture(40,40);$clock->at+=86400;
same('Saved list damaged',\Paymenter\Extensions\Servers\Domains\Admin\Support\PslFacts::state($lists),'Damaged bytes have distinct recovery state');
same('stored_copy_invalid',$lists->update(),'Damaged saved copy is not parser recovery');same(null,$reviews->pending(),'Damaged saved copy yields no candidate');
$m=require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000008_create_psl_reviews.php';$m->down();PslReviewSchema::verify();same(4,WatchAudit::count(),'Retain acceptance history');
echo "PSL pending bytes, exact hash, permission, sentinel refusal, rebaseline and retained audit passed.\n";$nativeSuccessful=true;
