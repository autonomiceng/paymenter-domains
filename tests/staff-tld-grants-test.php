<?php
// Native render-local TLD grants regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\Price;
extract(commerceFixture());tldPanel();Carbon\Carbon::setTestNow($now);
$t=$tlds[0];$viewer=staffUser([Staff::VIEW,Staff::COSTS])->load('role');Auth::setUser($viewer);
$page=Livewire::actingAs($viewer)->test(ListTlds::class)->filterTable('server_id',$t->server_id)->assertCountTableRecords(1);
function tldGrantQueries($page): array
{
    DB::enableQueryLog();DB::flushQueryLog();$page->call('$refresh')->assertSuccessful();
    $queries=DB::getQueryLog();DB::disableQueryLog();
    $counts=array_map(static fn($table)=>count(array_filter($queries,static fn($q)=>preg_match('/\bfrom\s+["`]?'.$table.'["`]?\b/i',$q['query']))),['users','roles']);
    expect(min($counts)>0,'TLD render actually reloads current grants');return $counts;
}
$one=tldGrantQueries($page);
foreach(['example','test','invalid'] as $ending){
    $copy=$t->replicate();$copy->tld=$ending;$copy->save();
    foreach(Price::where('server_id',$t->server_id)->where('tld',$t->tld)->get() as $price){
        $row=$price->replicate();$row->tld=$ending;$row->save();
    }
}
$page->call('$refresh')->assertCountTableRecords(4);$many=tldGrantQueries($page);
same($one,$many,'TLD permission queries do not grow with ending or price count');
echo 'TLD 1 vs 4 rows: '.json_encode($one).' / '.json_encode($many)." user/role queries\n";
$page->assertSee('Cost <span',false)->assertSee('8.00 USD');
expect(!str_contains(json_encode($page->snapshot),'StaffGrants'),'TLD grants never enter public component data');
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>json_encode([Staff::VIEW])]);
$page->call('$refresh')->assertSuccessful()->assertDontSee('Cost <span',false);
expect(!isset(TldFacts::prices($t)[0]['cost']),'Default TLD reader checks fresh cost grants');
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>'[]']);$page->call('$refresh')->assertForbidden();
same([Staff::VIEW,Staff::COSTS],$viewer->role->permissions,'TLD acting user retains a deliberately stale loaded role');
try{TldFacts::prices($t);expect(false,'Default TLD reader requires fresh view');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Revoked default TLD read denied');}
echo "Native row-independent TLD permission queries and next-request revocation passed.\n";
$nativeSuccessful=true;
