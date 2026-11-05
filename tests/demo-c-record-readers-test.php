<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Services\RecordFormat;
foreach (['enom','namesilo'] as $registrar) {
    $legacy=['identity'=>['registrar'=>$registrar,'generation'=>1],'at'=>'2026-11-04T12:00:00+00:00','sequence'=>4,
        'families'=>['dns'=>['state'=>'complete','records'=>[]],'srv'=>['state'=>'unknown'],
            'ds'=>['state'=>'unknown'],'glue'=>['state'=>'partial','records'=>['ns.example.com'=>'8.8.8.8']]]];
    $old=RecordFormat::read($legacy,$registrar);
    same($registrar==='enom'?'complete':'unknown',$old['families']['dns']['state'],'Legacy has no new provider completeness');
    $new=$legacy+['format'=>1,'capability_revision'=>$registrar.'-records-1',
        'expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'unsupported','glue'=>'supported']];
    $new['families']['dns']=['state'=>'partial','records'=>[['opaque'=>'retained CAA and TTL']]];
    $new['families']['srv']=['state'=>'complete','records'=>[]];
    $read=RecordFormat::read($new,$registrar);
    same($new['families']['dns'],$read['families']['dns'],'Partial siblings and opaque fields retained without whole-list authority');
    same('unknown',$read['families']['srv']['state'],'Unsupported is never complete empty');
    $new['families']['dns']=['state'=>'complete','records'=>[]];
    same('complete',RecordFormat::read($new,$registrar)['families']['dns']['state'],'Explicit new supported empty is readable');
    foreach ([['format',2],['capability_revision','future'],['expected_families',['dns'=>'supported']],
        ['format','1'],['expected_families',null]] as [$key,$bad]) {
        $copy=$new;$copy[$key]=$bad;
        same(null,RecordFormat::read($copy,$registrar),'Malformed or unknown observation metadata refused');
    }
    $copy=$new;unset($copy['format']);
    same(null,RecordFormat::read($copy,$registrar),'Half-upgraded metadata is not legacy');

}
echo "Demo C record readers passed.\n";
