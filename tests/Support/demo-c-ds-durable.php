<?php
// Native encrypted sequence/grant witness. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DsManagement;
$dsWire=(object)['rows'=>[], 'writes'=>[], 'fault'=>''];
$dsExchange=static function($origin,$cmd,$p,$t)use($managementExchange,$dsWire):string {
    if (!str_starts_with($cmd,'dnsSec')) { return $managementExchange($origin,$cmd,$p,$t); }
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'DS reads and writes stay outside locks');
    $reply=['code'=>300,'detail'=>'synthetic'];
    if ($cmd==='dnsSecListRecords') {
        if ($dsWire->fault!=='missing') { $reply['ds_record']=$dsWire->rows; }
    } else {
        $dsWire->writes[]=$cmd;
        $tuple=['key_tag'=>$p['keyTag'],'algorithm'=>$p['alg'],'digest_type'=>$p['digestType'],'digest'=>$p['digest']];
        if ($cmd==='dnsSecAddRecord') { $dsWire->rows[]=$tuple; }
        else { $dsWire->rows=array_values(array_filter($dsWire->rows,static fn($v)=>$v!==$tuple)); }
        if ($dsWire->fault==='lost') { throw new RuntimeException('Synthetic lost DS acknowledgement'); }
    }
    return json_encode(['request'=>['operation'=>$cmd],'reply'=>$reply],JSON_THROW_ON_ERROR);
};
[$d]=$holding('ds-durable');
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>true]);
$fact=['suffix'=>'com','input'=>'ds','algorithms'=>[13],'digest_types'=>[2],
    'combinations'=>[['algorithm'=>13,'digest_type'=>2]],'source'=>'synthetic-registry','version'=>'rule-1',
    'reviewed_at'=>$clock->now()->modify('-1 hour')->format(DATE_ATOM),'valid_until'=>$clock->now()->modify('+1 day')->format(DATE_ATOM),'expiry_policy'=>'refuse'];
$driver=new DsManagement($binding,$dsExchange,['format'=>1,'entries'=>['com'=>$fact]],$clock);
$tuple=['key_tag'=>123,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('AB',32)];
$op=$submit($d,$driver,'ds_add',['ds'=>$tuple]);
same(['format'=>1,...$fact],$op->fresh()->policy['management']['sequence']['preimage']['ds_policy'],'Encrypted operation retains exact policy version');
$dsWire->fault='lost';
same('uncertain',$management->run($op->id,$driver)->state,'Lost DS response stays uncertain');
$management->run($op->id,$driver);same(['dnsSecAddRecord'],$dsWire->writes,'Unknown send cannot run again');
$dsWire->fault='';$ms->route='apibatch';
same('succeeded',$management->reconcile($op->id,$driver)->state,'Independent exact delta resolves the lost response');
$ms->route='api';Illuminate\Support\Facades\Cache::flush();
try { $submit($d,$driver,'ds_delete',['ds'=>$tuple]); throw new LogicException('Missing last-delete confirmation'); } catch (DomainException) {}
$op=$submit($d,$driver,'ds_delete',['ds'=>$tuple,'confirm_last_delete'=>true]);
$dsWire->fault='lost';same('uncertain',$management->run($op->id,$driver)->state,'Lost final deletion stays uncertain');
$ms->route='apibatch';$dsWire->fault='missing';
same('uncertain',$management->reconcile($op->id,$driver)->state,'Missing collection never proves final deletion');
$dsWire->fault='';same('succeeded',$management->reconcile($op->id,$driver)->state,'Explicit empty independent read confirms final deletion');
same(['dnsSecAddRecord','dnsSecDeleteRecord'],$dsWire->writes,'Neither unknown mutation is replayed');
same(null,$op->invoice_id,'DS sequence creates no invoice');
echo "Native DS encrypted sequence and unknown-send recovery passed\n";
