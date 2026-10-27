<?php
// Synthetic transport only, including namespaced cURL functions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion {
    function link($source,$target){if($GLOBALS['collector_race']??false){file_put_contents($target,'concurrent destination');}return \link($source,$target);}
    function curl_init(){return new \stdClass;}
    function curl_setopt_array($handle,$options){$GLOBALS['collector_curl_options']=$options;return true;}
    function curl_exec($handle){$o=$GLOBALS['collector_curl_options'];$body=$GLOBALS['collector_curl_reply'];return $o[CURLOPT_WRITEFUNCTION]($handle,$body)===strlen($body);}
    function curl_getinfo($handle,$option){return $GLOBALS['collector_curl_status'];}
    function curl_close($handle){}
}
namespace {
require __DIR__.'/Support/conversion.php';
foreach(['EvidenceClient','EvidenceCollector','EvidenceFile','EvidenceHost']as$class){require dirname(__DIR__).'/scripts/conversion/'.$class.'.php';}
use DomainsConversion\{EvidenceClient,EvidenceCollector,EvidenceFile,EvidenceHost,Inputs,Planner,Converter};
use Illuminate\Support\Facades\DB;
$token='private-synthetic-token-for-evidence';$setting=$server->settings()->where('key','token')->sole();$setting->value=$token;$setting->save();
// Make a second positively purchased source holding, so an omitted candidate can stay pending.
DB::table('enom_domains')->where('id',22)->update(['billing_managed'=>true]);
$o=(array)DB::table('enom_operations')->where('id',122)->first();$p=Inputs::decrypt($o['policy']);$p['billing_managed']=true;
DB::table('enom_operations')->where('id',122)->update(['policy'=>Inputs::encrypt($p)]);
$time=10.0;$sleeps=[];$calls=[];
$clock=static function()use(&$time){return $time;};$sleep=static function($delay)use(&$time,&$sleeps){$sleeps[]=$delay;$time+=$delay;};
$exchange=static function($options)use(&$calls,&$time,$evidence,$token){
    parse_str($options[CURLOPT_POSTFIELDS],$fields);$calls[]=[$time,$options[CURLOPT_URL],$fields['Command'],$fields['SLD']];
    same('GetDomainInfo',$fields['Command'],'Closed command');same($token,$fields['PW'],'Only in-memory request holds token');
    foreach([CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT_MS=>10000,CURLOPT_TIMEOUT_MS=>30000,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_VERBOSE=>false]as$key=>$value){same($value,$options[$key],'Bounded transport option');}
    if($fields['SLD']==='imported'){throw new RuntimeException($token);}
    return [200,str_replace('</interface-response>','<Password>'.$token.'</Password><Contact>private contact</Contact></interface-response>',$evidence['domains']['21']['xml'])];
};
$client=new EvidenceClient($exchange,$clock,$sleep);$collector=new EvidenceCollector($client);$out=$temporary.'/evidence.json';
$before=Planner::sourceDigest();$native=DB::table('settings')->get()->toJson();ob_start();
EvidenceHost::readOnly(DB::connection());
$summary=$collector->collect($out,$staff->id,'conversion_review');$stdout=ob_get_clean();$stderr=json_encode($summary);
same(['collected'=>1,'omitted'=>1,'source_ledger_ids'=>[22]],$summary,'Partial failure discloses IDs/counts only');
same('', $stdout,'Collector prints no stdout');same(2,count($calls),'Only two purchase candidates read');same(1.0,$calls[1][0]-$calls[0][0],'Failed read also rate limited');
same('https://resellertest.enom.com/interface.asp',$calls[0][1],'TEST host');
$failedStart=$calls[1][0];$client->read('GetDomainInfo',$server->id+999,'test',strtolower($uid),$token,(array)DB::table('enom_domains')->where('id',21)->first());
same(1.0,end($calls)[0]-$failedStart,'Failed start retained across duplicate native servers for the same login');
$bytes=file_get_contents($out);$collected=Inputs::json($out);same(0,substr_count($stdout.$stderr.$bytes,$token),'No credential bytes in outputs');
expect(!str_contains($bytes,'private contact')&&!str_contains($bytes,'Password'),'Extra XML/contact data omitted');
same(0600,fileperms($out)&0777,'Private mode');same(Inputs::digest([$uid,$token]),$collected['accounts'][$server->id]['credential_digest'],'Converter digest, original UID case');
same($before,Planner::sourceDigest(),'Collection leaves ledger/native finance unchanged');same($native,DB::table('settings')->get()->toJson(),'Settings untouched');
DB::connection()->getPdo()->exec('PRAGMA query_only = OFF');
$dry=(new Converter($collected,$maintenance))->run();same([22,23,24],$dry['adoption_pending'],'Round trip purchases proven name and leaves omitted candidate pending');
// Malformed, foreign, wrong-ID and stale-expiry replies are omissions, never weaker proof.
foreach(['<broken/>',str_replace('syntheticuid','foreign',$evidence['domains']['21']['xml']),
    str_replace('DomainNameID="121"','DomainNameID="999"',$evidence['domains']['21']['xml']),
    str_replace('2028-10-18','2028-10-17',$evidence['domains']['21']['xml'])]as$index=>$reply){
    $bad=new EvidenceCollector(new EvidenceClient(static fn()=>[200,$reply],$clock,$sleep));
    $badOut=$temporary.'/bad-'.$index.'.json';
    try{$bad->collect($badOut,$staff->id,'conversion_review',[21]);throw new LogicException('Missing account escaped');}
    catch(\DomainsConversion\EvidenceIncomplete $e){same(['collected'=>0,'omitted'=>1,'source_ledger_ids'=>[21]],$e->summary,'Invalid read omitted with counts/IDs');}
    expect(!file_exists($badOut),'No unusable account proof published');
}
// File refusal happens before any wire request, replacement uses a new inode and leaves no temporary files.
$inode=fileinode($out);$count=count($calls);conversionRefuses(fn()=>$collector->collect($out,$staff->id,'conversion_review'),'Existing file','Private canonical');same($count,count($calls),'Existing-file refusal sends nothing');
$collector->collect($out,$staff->id,'conversion_review',[21],1,false,true);clearstatcache(true,$out);expect($inode!==fileinode($out),'Atomic replacement inode');same(0600,fileperms($out)&0777,'Replacement stays private');same([],glob($temporary.'/.conversion-evidence-*'),'No temporary evidence remains');
$GLOBALS['collector_race']=true;conversionRefuses(fn()=>EvidenceFile::write($temporary.'/race.json',[],false,[]),'Concurrent create','Concurrent evidence');$GLOBALS['collector_race']=false;
same('concurrent destination',file_get_contents($temporary.'/race.json'),'Concurrent file never overwritten');same([],glob($temporary.'/.conversion-evidence-*'),'Race cleans temporary');
symlink($out,$temporary.'/link.json');conversionRefuses(fn()=>EvidenceFile::write($temporary.'/link.json',[],true,[]),'Symlink','Private canonical');unlink($temporary.'/link.json');
conversionRefuses(fn()=>EvidenceFile::write($temporary.'/secret.json',['domains'=>['xml'=>$token]],false,[$token]),'Secret refusal','secret');expect(!file_exists($temporary.'/secret.json'),'Secret refusal creates no evidence');
conversionRefuses(fn()=>EvidenceFile::write($temporary.'/oversized.json',['xml'=>str_repeat('x',8_000_001)],false,[]),'Aggregate cap','exceeds');
$count=count($calls);$collector->collect($temporary.'/limit.json',$staff->id,'conversion_review',[],1);same($count+1,count($calls),'Limit caps request count');
conversionRefuses(fn()=>$collector->collect($temporary.'/narrow.json',$staff->id,'conversion_review',[23]),'Empty scope','Account evidence incomplete');same($count+1,count($calls),'Explicit ID narrows rather than overriding purchase-candidate gate');
conversionRefuses(fn()=>$collector->collect($temporary.'/unknown.json',$staff->id,'conversion_review',[999]),'Unknown ID','Unknown source');
conversionRefuses(fn()=>$collector->collect($temporary.'/actor.json',$user->id,'conversion_review'),'Unprivileged actor','Staff source');
conversionRefuses(fn()=>$collector->collect($temporary.'/reason.json',$staff->id,'invalid reason'),'Bounded reason','Staff source');
// Closed allowlist is checked before clock, secret handling or transport, even with caller-controlled parameters.
$domain=(array)DB::table('enom_domains')->where('id',21)->first();
foreach(['Purchase','Extend','GetBalance','getdomaininfo','GetDomainInfo&Command=Purchase','']as$command){
    try{$client->read($command,$server->id,'test',$uid,$token,$domain);throw new LogicException('Command escaped');}catch(LogicException $e){same('Collector command refused.',$e->getMessage(),'Allowlist rejects other commands');}}
foreach(['0','tls-keys']as$keylog){putenv('SSLKEYLOGFILE='.$keylog);$count=count($calls);conversionRefuses(fn()=>$client->read('GetDomainInfo',$server->id,'test',$uid,$token,$domain),'Key log','read refused');same($count,count($calls),'Key-log guard sends nothing');}putenv('SSLKEYLOGFILE');
// Exercise production cURL configuration through socket-free namespace functions, including callback cap and redirects.
$GLOBALS['collector_curl_reply']=$evidence['domains']['21']['xml'];$GLOBALS['collector_curl_status']=200;
$curl=new EvidenceClient(null,$clock,$sleep);same($GLOBALS['collector_curl_reply'],$curl->read('GetDomainInfo',$server->id,'live',$uid,$token,$domain),'Live read synthetic cURL');
same('https://reseller.enom.com/interface.asp',$GLOBALS['collector_curl_options'][CURLOPT_URL],'Live host');
foreach([301,302,307,500]as$status){$GLOBALS['collector_curl_status']=$status;conversionRefuses(fn()=>$curl->read('GetDomainInfo',$server->id,'test',$uid,$token,$domain),'HTTP/redirect','incomplete');}
$GLOBALS['collector_curl_status']=200;$GLOBALS['collector_curl_reply']=str_repeat('x',EvidenceClient::LIMIT+1);
conversionRefuses(fn()=>$curl->read('GetDomainInfo',$server->id,'test',$uid,$token,$domain),'Callback cap','incomplete');
$oversize=new EvidenceClient(static fn()=>[200,str_repeat('x',EvidenceClient::LIMIT+1)],$clock,$sleep);
conversionRefuses(fn()=>$oversize->read('GetDomainInfo',$server->id,'test',$uid,$token,$domain),'Injected cap','incomplete');
$throwing=new EvidenceClient(static function()use($token){throw new RuntimeException($token);},$clock,$sleep);
try{$throwing->read('GetDomainInfo',$server->id,'test',$uid,$token,$domain);throw new LogicException('Transport exception required');}catch(RuntimeException $e){same(null,$e->getPrevious(),'Transport exception discarded');expect(!str_contains((string)$e,$token),'Transport diagnostics hide secret');}
// A retained second account with no selected successful anchor makes publication unusable.
$second=\App\Models\Server::create(['name'=>'Second source','type'=>'server','extension'=>'Enom','enabled'=>true]);
foreach($server->settings as$setting){$copy=$setting->replicate();$copy->settingable_id=$second->id;$copy->save();}
conversionRefuses(fn()=>$collector->collect($temporary.'/multi.json',$staff->id,'conversion_review',[21]),'Missing retained account','Account evidence incomplete');
expect(!file_exists($temporary.'/multi.json'),'Scoped multi-account output refused before publication');$second->settings()->delete();$second->delete();
// Live guard must reject all scope before the first read, with both independent requirements exercised.
$set=static function($key,$value)use($server){$s=$server->settings()->firstOrNew(['key'=>$key]);$s->type='string';$s->encrypted=false;$s->value=$value;$s->save();};
$liveOld=hash('sha256','live:'.strtolower($uid));$set('environment','live');$set('enom_account_binding',$liveOld);$set('enom_live_url',rtrim(config('app.url'),'/'));
foreach(DB::table('enom_operations')->get()as$op){$p=Inputs::decrypt($op->policy);$p['account_fingerprint']=$liveOld;$c=Inputs::decrypt($op->cost);if(isset($c['quote'])){$c['quote']['account_fingerprint']=$liveOld;}DB::table('enom_operations')->where('id',$op->id)->update(['policy'=>Inputs::encrypt($p),'cost'=>Inputs::encrypt($c)]);}
$count=count($calls);
foreach([[[],false],[[21],false],[[],true]]as[$ids,$live]){conversionRefuses(fn()=>$collector->collect($temporary.'/live-refused.json',$staff->id,'conversion_review',$ids,null,$live),'Live authorization','Live collection requires');same($count,count($calls),'Live refusal sends zero');}
$collector->collect($temporary.'/live.json',$staff->id,'conversion_review',[21],null,true);same($count+1,count($calls),'Explicit live + ID permits only named read');same('https://reseller.enom.com/interface.asp',end($calls)[1],'Configured live endpoint');
// SQL read-only mode rejects real DML while permitting reads; no framework interception.
EvidenceHost::readOnly(DB::connection());same(4,DB::table('enom_domains')->count(),'Read-only reads allowed');
try{DB::table('enom_domains')->where('id',21)->update(['status'=>'changed']);throw new LogicException('Readonly accepted write');}catch(\Illuminate\Database\QueryException){}
DB::connection()->getPdo()->exec('PRAGMA query_only = OFF');
// Rebootstrap the actual host against a disposable file database, protecting it before providers boot.
$database=$temporary.'/host.sqlite';DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($database));
$databaseHash=hash_file('sha256',$database);
putenv('DB_DATABASE='.$database);$_ENV['DB_DATABASE']=$_SERVER['DB_DATABASE']=$database;
$runtime=$fixture.'/extensions/Servers/Domains';$parked=$fixture.'/collector-parked-Domains';
expect(!file_exists($parked)&&!is_link($parked),'Fixture parking target unused');$present=file_exists($runtime)||is_link($runtime);
if($present){expect(rename($runtime,$parked),'Park disposable installed runtime');}
try {
EvidenceHost::boot($fixture);same(1,(int)DB::selectOne('PRAGMA query_only')->query_only,'Actual boot protects primary before provider boot');
try{DB::table('enom_domains')->where('id',21)->update(['status'=>'changed']);throw new LogicException('Boot accepted write');}catch(\Illuminate\Database\QueryException){}
conversionRefuses(fn()=>DB::connection()->reconnect(),'Reconnect loses readonly','cannot reconnect');
same('array',config('cache.default'),'Boot memory cache');same('null',config('logging.default'),'Boot null logger');
$collector->collect($temporary.'/boot.json',$staff->id,'conversion_review',[21],null,true);
same($databaseHash,hash_file('sha256',$database),'Boot and collection leave database bytes unchanged');
}finally{if($present){expect(rename($parked,$runtime),'Restore disposable installed runtime');}}
echo "Conversion evidence: closed reads, scope/live/secrecy/atomic guards, partial round trip passed\n";
$nativeSuccessful=true;
}
