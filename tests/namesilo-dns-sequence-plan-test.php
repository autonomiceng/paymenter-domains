<?php
// Synthetic offline sequence fixtures. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Dns,DnsSequence};
expect(is_file(dirname(__DIR__).'/Domains/Registrars/NameSilo/DnsSequence.php'),'DNS sequence freezes complete inventory and computes per-record deltas');
$reject=static function(callable $f):void {try{$f();}catch(DomainException){return;}throw new RuntimeException('Expected unsafe DNS sequence refusal');};
$a=['name'=>'@','type'=>'A','address'=>'192.0.2.1','mx_pref'=>10,'record_id'=>'oldA','ttl'=>300];
$txt=['name'=>'verify','type'=>'TXT','address'=>'  "synthetic"  ','mx_pref'=>10,'record_id'=>'oldT','ttl'=>7207];
$caa=['name'=>'@','type'=>'CAA','address'=>'0:issue:ca.example.com','mx_pref'=>10,'record_id'=>'keepC','ttl'=>600];
$srv=['name'=>'_sip._tcp','type'=>'SRV','address'=>'1:443:target.example.com','mx_pref'=>10,'record_id'=>'keepS','ttl'=>900,'distance'=>0,'priority_proven'=>false,'weight'=>1,'port'=>443,'target'=>'target.example.com.'];
$before=['state'=>'complete','records'=>[$a,$txt,$caa,$srv]];
$updated=array_replace($a,['address'=>'192.0.2.2']);$added=array_diff_key(array_replace($txt,['name'=>'new','ttl'=>301]),['record_id'=>true]);
$steps=DnsSequence::plan('example.com',$before,[$updated,$added]);
same(['update','delete','add'],array_column($steps,'kind'),'One deterministic delta per writable change; opaque records are preserved');
same($a,$steps[0]['before'],'Update retains original ID and TTL');same($updated,$steps[0]['after'],'Update freezes exact requested content');
same($txt,$steps[1]['before'],'Deletion names the observed record');
$next=['state'=>'complete','records'=>[array_replace($updated,['record_id'=>'newA']),$txt,$caa,$srv]];
$proof=DnsSequence::confirm($before,$next,$steps[0],null);
same($next,$proof,'Unique independent readback recovers a lost update response and retains new ID');
same(null,DnsSequence::confirm($before,$next,$steps[0],'wrongID'),'Original acknowledged ID cannot be substituted');
same($next,DnsSequence::confirm($before,$next,$steps[0],'newA'),'Original acknowledgement still requires independent inventory');
same(null,DnsSequence::confirm($before,$before,$steps[0],null),'No observed mutation is not completion');
$ambiguous=$next;$ambiguous['records'][]=array_replace($updated,['record_id'=>'otherA']);
same(null,DnsSequence::confirm($before,$ambiguous,$steps[0],null),'Two matching new IDs remain ambiguous');
$unrelated=$next;$unrelated['records'][1]['ttl']=1;
same(null,DnsSequence::confirm($before,$unrelated,$steps[0],null),'An unrelated edit cannot be absorbed into the remaining preimage');
$one=['state'=>'complete','records'=>[$a]];$empty=['state'=>'complete','records'=>[]];
$del=DnsSequence::plan('example.com',$one,[])[0];
same($empty,DnsSequence::confirm($one,$empty,$del,null),'Explicit empty inventory proves final record deletion');
foreach(['unknown','partial'] as $state){$bad=['state'=>$state,'records'=>[$a]];$reject(fn()=>DnsSequence::plan('example.com',$bad,[]));same(null,DnsSequence::confirm($one,['state'=>$state,'records'=>[]],$del,null),'Unknown inventory never proves deletion');}
$reject(fn()=>DnsSequence::plan('example.com',$before,[array_replace($a,['type'=>'TXT'])]));
$reject(fn()=>DnsSequence::plan('example.com',$before,[array_replace($caa,['ttl'=>1])]));
$reject(fn()=>DnsSequence::plan('example.com',$before,[$a,$a]));
$reject(fn()=>DnsSequence::plan('example.com',$before,[array_replace($a,['record_id'=>'foreign'])]));
$reject(fn()=>DnsSequence::plan('example.com',$before,[array_diff_key($a,['ttl'=>true])]));
$reject(fn()=>DnsSequence::plan('example.com',$before,[$a,$txt])); // no writable changes
$dup=['state'=>'complete','records'=>[$a,array_replace($a,['record_id'=>'sameContent'])]];
$dupDel=DnsSequence::plan('example.com',$dup,[$a])[0];
same(['state'=>'complete','records'=>[$a]],DnsSequence::confirm($dup,['state'=>'complete','records'=>[$a]],$dupDel,null),'Duplicate content remains distinct by ID');
$add=DnsSequence::plan('example.com',$empty,[$added])[0];
$made=['state'=>'complete','records'=>[$added+['record_id'=>'made']]];
same($made,DnsSequence::confirm($empty,$made,$add,null),'Lost add response recovers only one new matching record');
same(null,DnsSequence::confirm($empty,['state'=>'complete','records'=>[$added+['record_id'=>'made'],$added+['record_id'=>'twice']]],$add,null),'Ambiguous add does not authorize another mutation');
echo "DNS sequence planning and unique readback passed\n";
