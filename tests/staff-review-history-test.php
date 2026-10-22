<?php
// Native staff-only history rendering; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RequestFacts,Staff,StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
$op=$new(placeholderComFixtureName('staff-history-placeholder.com'));$domain=$op->domain;
$activity=static fn()=>Livewire::actingAs($staff)->test(RequestsRelationManager::class,['ownerRecord'=>$domain,'pageClass'=>ViewDomain::class]);
$reason='<script>alert("review")</script> Hold for evidence';
$review=static function($action,$reason)use($service,$staff,$op,$ticket){$service->run($staff,$op->domain_id,$op->id,$action,$ticket($op->fresh(),$action),$reason);};
$review('hold',$reason);
$cell=$activity()->assertSuccessful()->assertSee('Held · 2026-10-18 12:00:00 UTC');
expect(str_contains($cell->html(),'&lt;script&gt;alert(&quot;review&quot;)&lt;/script&gt;'),'Activity escapes script reason');
expect(!str_contains($cell->html(),$reason),'Activity never emits raw script reason');
$review('release','Release after evidence review');
$cell=$activity()->assertSee($reason)->assertSee('Release after evidence review');
expect(strpos($cell->html(),'Held ·')<strpos($cell->html(),'Released ·'),'Hold precedes release in recorded order');
// Exercise durable recheck phases without invoking any provider or changing the review writer.
$read=new StaffOperationReview($staff->id,$domain->id,$op->id,'recheck',$ticket($op->fresh(),'recheck'),'Read current evidence',$clock);
$read->read(static function()use($activity,$reason){
    $cell=$activity()->assertSee($reason)->assertSee('Release after evidence review')->assertSee('Recheck requested');
    expect(strpos($cell->html(),'Released ·')<strpos($cell->html(),'Recheck requested ·'),'Release precedes recheck request');
});
$cell=$activity()->assertSee('Released ·')->assertSee('Recheck requested ·')->assertSee('Recheck finished ·')->assertSee('Read current evidence');
expect(strpos($cell->html(),'Recheck requested ·')<strpos($cell->html(),'Recheck finished ·'),'Recheck phases retain recorded order');
$cell->assertDontSee($reason)->assertSee('1 older staff review entry not shown — open request for details');
$detail=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee('Held ·');
expect(str_contains($detail->html(),'&lt;script&gt;alert(&quot;review&quot;)&lt;/script&gt;'),'Detail escapes script reason');
expect(!str_contains($detail->html(),$reason),'Detail never emits raw script reason');
// History is carried in loaded policy; one and four rows must perform exactly the same reads.
$measure=static function($page){DB::enableQueryLog();DB::flushQueryLog();$page->call('$refresh')->assertSuccessful();$q=DB::getQueryLog();DB::disableQueryLog();return count($q);};
$one=$measure($cell);
for($i=1;$i<=3;$i++){$copy=$op->fresh()->replicate();$copy->operation_key='history-copy-'.$i;$copy->invoice_item_id=null;$copy->save();}
$cell->call('$refresh')->assertCountTableRecords(4);$many=$measure($cell);
same($one,$many,'All Activity query counts remain constant at one versus four history rows');
echo 'History Activity queries: '.$one.' at 1 row; '.$many." at 4 rows.\n";
// Additional bounded, synthetic retained entries include a stored display name and failed phase.
$history=$op->fresh()->policy['staff_actions'];
for($i=4;$i<25;$i++){$history[]=['action'=>'recheck','phase'=>'read_failed','at'=>'2026-10-18T12:00:00+00:00','reason'=>'History reason '.$i];}
$history[24]['reason']=str_repeat('長',170).'CELL-TAIL';$history[24]['actor_name']='<b>Stored staff</b>';
$op->refresh()->update(['policy'=>[...$op->policy,'staff_actions'=>$history]]);
$cell=$activity()->assertSee('Recheck failed ·')->assertSee('History reason 22')->assertSee('History reason 23')
    ->assertSee('22 older staff review entries not shown — open request for details')->assertDontSee('History reason 21')->assertDontSee('CELL-TAIL');
expect(str_contains($cell->html(),'…'),'Long cell reason visibly truncates');
expect(str_contains($cell->html(),'&lt;b&gt;Stored staff&lt;/b&gt;'),'Stored actor display name is escaped');
$detail=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee(str_repeat('長',170).'CELL-TAIL')
    ->assertSee('History reason 5')->assertDontSee($reason)->assertSee('5 older staff review entries not shown');
// Redacted/missing fields are never recovered by joining the retained actor ID or other evidence.
$redacted=clone $op;$redacted->policy=['staff_actions'=>[['action'=>'hold','phase'=>'completed','actor_id'=>$staff->id,'reason'=>'[redacted]','at'=>'2026-10-18T12:00:00+00:00']]];
same(['Held · 2026-10-18 12:00:00 UTC · Reason: [redacted]'],StaffReviewHistory::for($redacted),'Redacted reason stays redacted; absent name stays absent');
$redacted->policy=['staff_actions'=>[['action'=>'release','actor_id'=>$staff->id,'at'=>'invalid']]];
same(['Released · Date unavailable · Reason unavailable'],StaffReviewHistory::for($redacted),'Missing private fields stay absent');
$redacted->policy=['staff_actions'=>[['action'=>'hold','reason'=>'[redacted]','at'=>"2026-10-18T12:00:00+00:00\0"]]];
same(['Held · Date unavailable · Reason: [redacted]'],StaffReviewHistory::for($redacted),'NUL timestamp cannot break history display');
$redacted->policy=['staff_actions'=>array_fill(0,101,[])];same(['Staff review history unavailable'],StaffReviewHistory::for($redacted),'Oversized retained history refuses display');
$redacted->policy=['staff_actions'=>'unavailable'];same(['Staff review history unavailable'],StaffReviewHistory::for($redacted),'Malformed retained history refuses display');
// The newest acquisition used by the customer presenter carries the same complete history.
$copy->update(['policy'=>$op->fresh()->policy]);
// Render real customer controllers and Blade using the same native owner/request with staff history.
require __DIR__.'/Support/owner-pages.php';
Auth::setUser($user);$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession(app('request')->session());
$controller=new OwnedDomainController($owners);
foreach(['show','index'] as $page){$html=($page==='show'?$controller->show($r,$domain->id):$controller->index($r))->render();
    expect(str_contains($html,$domain->domain),'Customer page renders the actual history-bearing domain');
    foreach([$reason,'Hold for evidence','Release after evidence review','History reason','CELL-TAIL','Stored staff','Read current evidence','Staff review history'] as $private){expect(!str_contains($html,$private),'Customer portal excludes staff reason/actor history');}
}
DB::table('roles')->where('id',$staff->role_id)->update(['permissions'=>'[]']);Auth::setUser($staff);
$cell->call('$refresh')->assertForbidden();$detail->call('$refresh')->assertForbidden();
foreach([fn()=>StaffReviewHistory::for($op),fn()=>RequestFacts::review($op)] as $read){
    try{$read();expect(false,'Revoked history read must refuse');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Fresh staff view permission required');}
}
$nativeSuccessful=true;echo "Staff history order, bounds, escaping, customer exclusion and permission refusal passed.\n";
