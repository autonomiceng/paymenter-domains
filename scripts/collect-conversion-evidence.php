#!/usr/bin/env php
<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Development-only read-only collector.
declare(strict_types=1);
// Application/provider diagnostics must not escape through PHP output or its error log.
ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');
ob_start();$level=ob_get_level();
try {
    $values=['--app','--out','--accepted-by','--reason','--domain','--limit'];
    // Reject typos/positionals before getopt could silently broaden TEST scope.
    for($i=1;$i<count($argv);$i++){
        $parts=explode('=',$argv[$i],2);$key=$parts[0];
        if(in_array($key,$values,true)){if(count($parts)===1&&(!isset($argv[++$i])||str_starts_with($argv[$i],'--'))){throw new RuntimeException;}}
        elseif(!in_array($key,['--live','--replace'],true)||count($parts)!==1){throw new RuntimeException;}
    }
    $options=getopt('',['app:','out:','accepted-by:','reason:','domain:','limit:','live','replace']);
    foreach(['app','out','accepted-by','reason']as$key){if(!is_string($options[$key]??null)){throw new RuntimeException;}}
    $positive=static function($v):int{if(!is_string($v)||!preg_match('/^[1-9][0-9]{0,8}$/D',$v)){throw new RuntimeException;}return (int)$v;};
    $actor=$positive($options['accepted-by']);$ids=isset($options['domain'])?array_map($positive,(array)$options['domain']):[];
    $limit=isset($options['limit'])?$positive($options['limit']):null;
    foreach(['Inputs','OwnershipRead','SourceAccount','Planner','Mapper','EvidenceClient','EvidenceFile','EvidenceCollector','EvidenceHost']as$class){require_once __DIR__.'/conversion/'.$class.'.php';}
    \DomainsConversion\EvidenceFile::target($options['out'],isset($options['replace']));
    \DomainsConversion\EvidenceHost::boot($options['app']);
    $summary=(new \DomainsConversion\EvidenceCollector(new \DomainsConversion\EvidenceClient))->collect(
        $options['out'],$actor,$options['reason'],$ids,$limit,isset($options['live']),isset($options['replace']));
    while(ob_get_level()>=$level){ob_end_clean();}
    fwrite(STDERR,json_encode($summary,JSON_THROW_ON_ERROR)."\n");
} catch(\DomainsConversion\EvidenceIncomplete $failure) {
    while(ob_get_level()>=$level){ob_end_clean();}
    fwrite(STDERR,json_encode($failure->summary,JSON_THROW_ON_ERROR)."\nAccount evidence incomplete; no file published.\n");exit(1);
} catch(Throwable) {
    while(ob_get_level()>=$level){ob_end_clean();}
    fwrite(STDERR,"Collection refused. Check app, staff permissions, scope, live authorization and private output prerequisites.\n");exit(1);
}
