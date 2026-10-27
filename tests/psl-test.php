<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/psl.php';
use App\Models\Setting;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList as PSL, PslSchema, PslTransport, PslValidator, PslFailure};
PslSchema::install(); PslSchema::install();
$v = new PslValidator(128, 5, 1); $body = pslFixture(); $clock = new PslClock;
$calls = []; $nested = null; $response = ['status'=>200, 'body'=>$body, 'etag'=>'"v1"', 'last-modified'=>'Wed, 01 Oct 2025 00:00:00 GMT'];
$psl = new PSL($clock, new PslTransport(function($url, $headers) use (&$calls, &$response, &$nested) {
    same(0, DB::transactionLevel(), 'HTTP outside locks'); if ($nested) { $nested(); } $calls[] = [$url, $headers];
    if ($response instanceof Throwable) { throw $response; } return $response;
}), $v);
// DNS admission is serialized without consuming the daily network allowance.
$dns = new PSL($clock, new PslTransport(null, function()use($psl):array {
    same('rate_limited',$psl->update(),'Manual admission blocked while DNS pending');
    same('rate_limited',$psl->update(true),'Scheduled admission blocked while DNS pending');
    return [];
}), $v);
$lastNetwork=$psl->status()->last_network_fetch_at;$result=$dns->update();
same($lastNetwork,$psl->status()->last_network_fetch_at,'Failed DNS is not a network fetch');
same('dns_failed',$result,'DNS failure retains its cause');same('dns_failed',$psl->status()->last_result,'DNS cause persisted');
same(null,$psl->nextAllowedAt(),'DNS failure does not lock out manual retry');
$unsafe = new PSL($clock,new PslTransport(null,fn()=>[['ip'=>'8.8.8.8'],['ip'=>'127.0.0.1']]),$v);
same('endpoint_unsafe',$unsafe->update(),'Non-global answer still refused');
same($lastNetwork,$psl->status()->last_network_fetch_at,'Unsafe DNS is not a network fetch');
same(false, $psl->discoveryAllowed(), 'No initial discovery'); same(false, $psl->staleWarning(), 'No warning without copy'); same(null, $psl->revision(), 'No revision');
$nested = function()use($psl):void { same('rate_limited',$psl->update(),'Concurrent/reentrant manual admission blocked before HTTP'); $psl->tick(); };
same('updated', $psl->update(), 'First current'); $nested=null; same(hash('sha256', $body), $psl->revision(), 'Exact hash');
$first = $psl->current(); same($body, PSL::body($first), 'Compressed round trip'); same(40, $first->icann_count, 'ICANN count'); same(40, $first->private_count, 'PRIVATE count');
same(PSL::SOURCE, $first->source_url, 'Provenance'); same(strlen($body), $first->byte_size, 'Bytes');
same('rate_limited', $psl->update(), 'Manual cap'); $psl->tick(); same(1, count($calls), 'Schedule shares cap');
$clock->at += 86399; same('rate_limited', $psl->update(), 'Rolling day minus second');
$clock->at++; $response = ['status'=>304, 'body'=>''];
same('not_modified', $psl->update(), '304 at exact day'); same(json_encode($first), json_encode($psl->current()), '304 does not replace or redate data');
same($clock->at, $psl->status()->checked_at, '304 checked time');
same(['If-None-Match: "v1"', 'If-Modified-Since: Wed, 01 Oct 2025 00:00:00 GMT'], $calls[1][1], 'Both conditional headers');
$clock->at += 86400; same('rate_limited',$psl->update(true),'Scheduled admission still waits a week'); $response = ['status'=>200, 'body'=>$body];
same('identical', $psl->update(), 'Identical body'); same(1, DB::table('domains_psl_snapshots')->count(), 'No identical revision');
same($clock->at, $psl->current()->fetched_at, '200 revalidates data');
$clock->at += 86400; $response['body'] = pslFixture(41, 41); $response['etag']='"v2"'; same('updated', $psl->update(), 'Rotate');
same('previous', DB::table('domains_psl_snapshots')->where('revision', $first->revision)->value('state'), 'Retained previous');
$clock->at += 86400; $response['body'] = pslFixture(42, 42); same('updated', $psl->update(), 'Rotate again');
same(2, DB::table('domains_psl_snapshots')->count(), 'Only two copies');
$good = $psl->current(); $copies = DB::table('domains_psl_snapshots')->orderBy('id')->get()->toJson();
$invalid = [
    'markers_missing'=>str_replace('// ===END PRIVATE DOMAINS===', '// absent', pslFixture(42,42)),
    'markers_order'=>str_replace('// ===BEGIN PRIVATE DOMAINS===', '// ===BEGIN ICANN DOMAINS===', pslFixture(42,42)),
    'rule_invalid:contextj'=>str_replace('i41.example',"a\u{200d}b.example",pslFixture(42,42)),
    'rule_invalid:bidi'=>str_replace('i41.example',"a\u{05d0}.example",pslFixture(42,42)),
    'rule_invalid:duplicate'=>str_replace('i41.example','com',pslFixture(42,42)),
    'rule_invalid:outside'=>"com\n".pslFixture(42,42),
    'rule_invalid'=>str_replace('co.uk', 'bad rule', pslFixture(42,42)),
    'too_small'=>'com', 'too_large'=>str_repeat('a', PslValidator::MAX_BYTES + 1),
    'invalid_utf8'=>pslFixture(42,42)."\xff",
    'rules_lost_icann'=>pslFixture(39,42), 'rules_lost_private'=>pslFixture(42,39),
];
foreach (['com', 'co.uk', 'github.io'] as $sentinel) {
    $invalid['sentinel_missing:'.$sentinel] = str_replace("\n".$sentinel."\n", "\n", pslFixture(42,42));
}
$failures = 0;
foreach ($invalid as $expected=>$bad) {
    try { $v->parse($bad, $v->parse(pslFixture(42,42))); expect(false,'Pure validator refusal '.$expected); } catch (PslFailure $e) { same(explode(':',$expected)[0],$e->safeCode,'Pure refusal code'); }
    $clock->at += 86400; $response = ['status'=>200, 'body'=>$bad];
    same(explode(':', $expected)[0], $psl->update(), 'Refusal '.$expected); $failures++;
    same($copies, DB::table('domains_psl_snapshots')->orderBy('id')->get()->toJson(), 'Rejected body never stored '.$expected);
    same(explode(':',$expected)[0],$psl->status()->last_result,'Persisted safe refusal');
    $before=count($calls); same('rate_limited',$psl->update(),'Failure consumes manual allowance'); same('rate_limited',$psl->update(true),'Failure consumes scheduled allowance'); same($before,count($calls),'Failed attempt cannot retry HTTP');
    same($failures, $psl->status()->consecutive_failures, 'Failure count'); same($good->validated_at, $psl->status()->checked_at, 'Failure does not refresh');
}
foreach ([['status'=>302,'body'=>''], new PslFailure('timeout'), new RuntimeException('PRIVATE internal detail'), ['status'=>500,'body'=>'']] as $i=>$bad) {
    $clock->at += 86400; $response = $bad;
    same(['redirect_refused','timeout','internal_error','http_failed'][$i], $psl->update(), 'Transport refusal');
    same($clock->at,$psl->status()->last_network_fetch_at,'Dispatched failure consumes network allowance');
    same('rate_limited',$psl->update(),'Dispatched failure cannot retry immediately');
    same($copies, DB::table('domains_psl_snapshots')->orderBy('id')->get()->toJson(), 'Transport retains copies');
}
// Changing the source never sends validators for the old endpoint; non-HTTPS never reaches the fake.
$setting = Setting::create(['key'=>PSL::SETTING,'value'=>'http://psl.example.com/list','type'=>'string']);
$clock->at += 86400; $before = count($calls); $lastNetwork = $psl->status()->last_network_fetch_at;
same('source_invalid', $psl->update(), 'Unsafe source'); same($before, count($calls), 'Invalid source zero HTTP');
same($lastNetwork, $psl->status()->last_network_fetch_at, 'Invalid source is not network fetch');
$setting->update(['value'=>'https://psl.example.com/list']); $response = ['status'=>304,'body'=>''];
same('unexpected_304', $psl->update(), 'Foreign source 304 refused'); same([], $calls[array_key_last($calls)][1], 'No cross-source validators');
$setting->delete(); $clock->at += 86400; $response = ['status'=>200,'body'=>pslFixture(42,42)];
same('identical', $psl->update(), 'Recovers'); same(0, $psl->status()->consecutive_failures, 'Success resets failures');
$validated = $psl->current()->validated_at;
foreach ([30*86400-1,30*86400,30*86400+1,90*86400-1,90*86400,90*86400+1] as $age) {
    $clock->at = $validated+$age; same($age > 30*86400, $psl->staleWarning(), '30 day boundary'); same($age <= 90*86400, $psl->discoveryAllowed(), '90 day boundary');
}
$clock->at = $validated + 7*86400 - 1; $before = count($calls); $psl->tick(); same($before,count($calls),'Weekly minus second');
$clock->at++; $psl->tick(); same($before+1,count($calls),'Weekly exact'); same('rate_limited',$psl->update(),'Scheduled admission caps manual');
$clock->at -= 86400; same('rate_limited',$psl->update(),'Clock rollback retains cap');
// An expired DNS lease can be replaced, but its original owner must never dispatch afterward.
$clock->at=(int)$psl->status()->last_network_fetch_at+86400;$dispatchAt=$clock->at+120;
$stalled=new PSL($clock,new PslTransport(null,function()use($clock,$psl):array {
    $clock->at+=119;same('rate_limited',$psl->update(),'Pending lease protects manual admission through 119 seconds');
    same('rate_limited',$psl->update(true),'Pending lease protects scheduled admission through 119 seconds');
    $clock->at++;same('identical',$psl->update(),'Expired DNS lease can be replaced at 120 seconds');
    $clock->at++;return [['ip'=>'8.8.8.8']];
}),$v);
same('superseded',$stalled->update(),'Superseded DNS attempt cannot send');
same($dispatchAt,$psl->status()->last_network_fetch_at,'Only replacement dispatch records network time');
same('identical',$psl->status()->last_result,'Superseded worker retains replacement result');
// Replacement additions cannot mask disappearing old rules. Exactly 5% loss is accepted.
$original = $v->parse(pslFixture()); $v->parse(pslFixture(38,38), $original);
try { $v->parse(str_replace('i37.example','new.example',pslFixture(38,40)), $original); expect(false,'More than five percent refused'); } catch(PslFailure $e) { same('rules_lost_icann',$e->safeCode,'Loss despite replacement'); }
foreach (['com', '*.foo.*.com','!bad!name.com','foo..com','foo.com.','a_b.com','-bad.com',implode('.',array_fill(0,5,str_repeat('a',60))),str_repeat('x',64).'.com'] as $bad) {
    try { $v->parse(str_replace('co.uk',$bad,$body)); expect(false,'Invalid rule '.$bad); } catch(PslFailure $e) { same('rule_invalid',$e->safeCode,'Full line parser'); }
}
try { (new PslValidator)->parse($body); expect(false,'Production floor'); } catch(PslFailure $e) { same('too_small',$e->safeCode,'Production floor enforced'); }
try { (new PslValidator(128,41,1))->parse($body); expect(false,'Rule count floor'); } catch(PslFailure $e) { same('too_few_rules',$e->safeCode,'Count floor'); }
try { (new PslValidator(128,5,41))->parse($body); expect(false,'Private rule count floor'); } catch(PslFailure $e) { same('too_few_rules',$e->safeCode,'Private count floor'); }
// Old conditional validators must not prevent a fresh download after the warning threshold.
DB::table('domains_psl_snapshots')->where('state','current')->update(['etag'=>'"stale"','last_modified'=>'Wed, 01 Oct 2025 00:00:00 GMT']);
$clock->at=$psl->current()->validated_at+31*86400;same(true,$psl->staleWarning(),'Saved copy is actually stale');$response=['status'=>200,'body'=>pslFixture(42,42)];
same('identical',$psl->update(),'Aged unchanged copy revalidated');same([],$calls[array_key_last($calls)][1],'Aged request is unconditional');same(false,$psl->staleWarning(),'Fresh 200 clears warning');
// A release may raise floors without invalidating a previously accepted loss baseline.
$clock->at+=86400;$response=['status'=>200,'body'=>pslFixture(43,43)];
$raised=new PSL($clock,new PslTransport(function()use(&$response){return $response;}),new PslValidator(strlen(pslFixture(42,42))+1,43,43));
same('updated',$raised->update(),'Stored baseline ignores new size/count floors');
$badBaseline=str_replace('co.uk','bad rule',pslFixture(43,43));
DB::table('domains_psl_snapshots')->where('state','current')->update(['revision'=>hash('sha256',$badBaseline),'compressed_body'=>base64_encode(gzencode($badBaseline))]);
$beforeBaseline=DB::table('domains_psl_snapshots')->orderBy('id')->get()->toJson();$clock->at+=86400;
same('stored_copy_invalid',$raised->update(),'Hash-valid baseline parser failure is remapped');same('stored_copy_invalid',$raised->status()->last_result,'Stored refusal persists');
same($beforeBaseline,DB::table('domains_psl_snapshots')->orderBy('id')->get()->toJson(),'Invalid baseline retains both snapshots');
DB::table('domains_psl_snapshots')->where('state','current')->update(['compressed_body'=>'broken']);$clock->at+=86400;
same('stored_copy_invalid',$raised->update(),'Stored baseline failure is distinct from rejected download');
// Retained down(), missing status and partial schemas fail closed.
$migration = require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000007_create_psl_snapshots.php'; $migration->down(); PslSchema::verify();
DB::table('domains_psl_status')->delete();
$refused=false;try { PslSchema::verify(); } catch(RuntimeException $e) { $refused=str_contains($e->getMessage(),'status'); }expect($refused,'Missing singleton refused');
Schema::drop('domains_psl_status');
$refused=false;try { PslSchema::install(); } catch(RuntimeException $e) { $refused=str_contains(strtolower($e->getMessage()),'partial'); }expect($refused,'Partial refused');
echo "Public suffix storage, validation, rotation, retention, conditional fetch, shared cap and age boundaries passed.\n"; $nativeSuccessful=true;
