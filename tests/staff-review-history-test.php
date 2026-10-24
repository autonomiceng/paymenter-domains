<?php
// Native staff-only history rendering; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RequestFacts,Staff,StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
$op=$new(placeholderComFixtureName('staff-history-placeholder.com'));$domain=$op->domain;
// Staff actions show in the request page History table; the Registrar requests list and the domain Requests tab never show them.
$activity=static fn()=>Livewire::actingAs($staff)->test(ListOperations::class);
$page=static fn()=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);
$history=static fn()=>Livewire::actingAs($staff)->test(RequestsRelationManager::class,['ownerRecord'=>$domain,'pageClass'=>ViewDomain::class]);
$reason='<script>alert("review")</script> Hold for evidence';
$review=static function($action,$reason)use($service,$staff,$op,$ticket){$service->run($staff,$op->domain_id,$op->id,$action,$ticket($op->fresh(),$action),$reason);};
$review('hold',$reason);
$detail=$page()->assertSuccessful()->assertSee('Put on hold')->assertSee('Oct 18, 2026 12:00 UTC');
expect(str_contains($detail->html(),'&lt;script&gt;alert(&quot;review&quot;)&lt;/script&gt;'),'History escapes script reason');
expect(!str_contains($detail->html(),$reason),'History never emits raw script reason');
$review('release','Release after evidence review');
$detail=$page()->assertSee('Hold for evidence')->assertSee('Release after evidence review');
expect(strpos($detail->html(),'Put on hold')<strpos($detail->html(),'Hold released'),'Hold precedes release in recorded order');
// Exercise durable recheck phases without invoking any provider or changing the review writer.
$read=new StaffOperationReview($staff->id,$domain->id,$op->id,'recheck',$ticket($op->fresh(),'recheck'),'Read current evidence',$clock);
$read->read(static function()use($page){
    $detail=$page()->assertSee('Hold released')->assertSee('Status checked')->assertSee('Read current evidence');
    expect(strpos($detail->html(),'Hold released')<strpos($detail->html(),'Status checked'),'Release precedes the status check');
});
$rows=StaffReviewHistory::rows($op->fresh());
same(['Put on hold','Hold released','Status checked'],array_column($rows,'event'),'A check shows as one row once it finishes');
same('Read current evidence',$rows[2]['sub'],'Check reason stays under its event');
$activity()->assertSuccessful()->assertDontSee('Hold for evidence')->assertDontSee('Read current evidence')->assertDontSee('Release after evidence review');
$history()->assertSuccessful()->assertSee('Request #'.$op->id)->assertDontSee('Release after evidence review')->assertDontSee('Hold for evidence');
// History is carried in loaded policy; one and four rows must perform exactly the same reads.
$measure=static function($page){DB::enableQueryLog();DB::flushQueryLog();$page->call('$refresh')->assertSuccessful();$q=DB::getQueryLog();DB::disableQueryLog();return count($q);};
$cell=$activity();$one=$measure($cell);$timeline=$history();$historyOne=$measure($timeline);
for($i=1;$i<=3;$i++){$copy=$op->fresh()->replicate();$copy->operation_key='history-copy-'.$i;$copy->invoice_item_id=null;$copy->save();}
$copies=Paymenter\Extensions\Servers\Domains\Models\Operation::where('operation_key','like','history-copy-%')->get();
$cell->call('$refresh')->assertCanSeeTableRecords([$op,...$copies]);$many=$measure($cell);
$timeline->call('$refresh')->assertCountTableRecords(4);same($historyOne,$measure($timeline),'Domain History query count stays constant at one versus four requests');
same($one,$many,'Registrar requests list query counts remain constant at one versus four requests');
echo 'Registrar requests list queries: '.$one.' at 1 row; '.$many." at 4 rows.\n";
// Additional bounded, synthetic retained entries include a stored display name and failed phase.
$history=$op->fresh()->policy['staff_actions'];
for($i=4;$i<25;$i++){$history[]=['action'=>'recheck','phase'=>'read_failed','at'=>'2026-10-18T12:00:00+00:00','reason'=>'History reason '.$i];}
$history[24]['reason']=str_repeat('長',170).'CELL-TAIL';$history[24]['actor_name']='<b>Stored staff</b>';
$op->refresh()->update(['policy'=>[...$op->policy,'staff_actions'=>$history]]);
$detail=$page()->assertSee('Status check failed')->assertSee(str_repeat('長',170).'CELL-TAIL')->assertDontSee('older staff review');
foreach(range(4,23) as $i){$detail->assertSee('History reason '.$i);}
expect(str_contains($detail->html(),'&lt;b&gt;Stored staff&lt;/b&gt;'),'Stored actor display name is escaped');
same(24,count(StaffReviewHistory::rows($op->fresh())),'Every retained entry is a History row');
// Redacted/missing fields are never recovered by joining the retained actor ID or other evidence.
$redacted=clone $op;$redacted->policy=['staff_actions'=>[['action'=>'hold','phase'=>'completed','actor_id'=>$staff->id,'reason'=>'[redacted]','at'=>'2026-10-18T12:00:00+00:00']]];
same([['time'=>'Oct 18, 2026 12:00 UTC','event'=>'Put on hold','sub'=>'[redacted]','by'=>'Staff']],StaffReviewHistory::rows($redacted),'Redacted reason stays redacted; absent name stays absent');
$redacted->policy=['staff_actions'=>[['action'=>'release','actor_id'=>$staff->id,'at'=>'invalid']]];
same([['time'=>null,'event'=>'Hold released','sub'=>null,'by'=>'Staff']],StaffReviewHistory::rows($redacted),'Missing private fields stay absent');
$redacted->policy=['staff_actions'=>[['action'=>'hold','reason'=>'[redacted]','at'=>"2026-10-18T12:00:00+00:00\0"]]];
same([['time'=>null,'event'=>'Put on hold','sub'=>'[redacted]','by'=>'Staff']],StaffReviewHistory::rows($redacted),'NUL timestamp cannot break history display');
$redacted->policy=['staff_actions'=>array_fill(0,101,[])];same(null,StaffReviewHistory::rows($redacted),'Oversized retained history refuses display');
$redacted->policy=['staff_actions'=>'unavailable'];same(null,StaffReviewHistory::rows($redacted),'Malformed retained history refuses display');
// The newest acquisition used by the customer presenter carries the same complete history.
$copy->update(['policy'=>$op->fresh()->policy]);
// Render real customer controllers and Blade using the same native owner/request with staff history.
require __DIR__.'/Support/owner-pages.php';
Auth::setUser($user);$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession(app('request')->session());
$controller=new OwnedDomainController($owners);
foreach(['show','index'] as $page){$html=($page==='show'?$controller->show($r,$domain->id):$controller->index($r))->render();
    expect(str_contains($html,$domain->domain),'Customer page renders the actual history-bearing domain');
    foreach([$reason,'Hold for evidence','Release after evidence review','History reason','CELL-TAIL','Stored staff','Read current evidence','Put on hold','Hold released'] as $private){expect(!str_contains($html,$private),'Customer portal excludes staff reason/actor history');}
}
DB::table('roles')->where('id',$staff->role_id)->update(['permissions'=>'[]']);Auth::setUser($staff);
$cell->call('$refresh')->assertForbidden();$timeline->call('$refresh')->assertForbidden();$detail->call('$refresh')->assertForbidden();
foreach([fn()=>StaffReviewHistory::rows($op),fn()=>RequestFacts::for($op)] as $read){
    try{$read();expect(false,'Revoked history read must refuse');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Fresh staff view permission required');}
}
$nativeSuccessful=true;echo "Staff history order, bounds, escaping, customer exclusion and permission refusal passed.\n";
