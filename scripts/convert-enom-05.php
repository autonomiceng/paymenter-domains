#!/usr/bin/env php
<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One-time offline maintenance tool, excluded from runtime.
declare(strict_types=1);
require __DIR__.'/conversion/Inputs.php';
use DomainsConversion\{Inputs,Host,Converter};
$options=getopt('',['app:','source:','source-revision:','evidence:','units:','dry-run','stage-schema','apply','backup-marker:']);
try {
    foreach(['app','source','source-revision','evidence','units']as$key){if(!isset($options[$key])||!is_string($options[$key])){throw new DomainException('Required: --app --source --source-revision --evidence --units; default --dry-run. Apply additionally requires --apply --backup-marker.');}}
    if(isset($options['apply'],$options['dry-run'])){throw new DomainException('Choose dry-run or apply.');}
    Inputs::source($options['source'],$options['source-revision']);$appPath=realpath($options['app']);
    if(!$appPath||$appPath!==$options['app']||!is_file($appPath.'/bootstrap/app.php')){throw new DomainException('Canonical Paymenter application path required.');}
    foreach(['Enom','Domains']as$name){if(file_exists($appPath.'/extensions/Servers/'.$name)){throw new DomainException('Park registrar runtimes before bootstrap.');}}
    $lock=fopen($appPath.'/storage/framework/domains-conversion.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new DomainException('Another conversion owns maintenance.');}
    $loader=require $appPath.'/vendor/autoload.php';$application=require $appPath.'/bootstrap/app.php';
    $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__).'/Domains/',true);
    foreach(['SourceSchema','Planner','Mapper','Journal','Host','Converter']as$class){require __DIR__.'/conversion/'.$class.'.php';}
    $units=Inputs::json($options['units']);$evidence=Inputs::json($options['evidence']);
    Host::verify($appPath,$units);\DomainsConversion\SourceSchema::verify();
    if(isset($options['stage-schema'])){
        if(isset($options['apply'])){throw new DomainException('Stage schema separately from apply.');}
        Converter::stageSchema();echo json_encode(['schema_staged'=>true])."\n";exit(0);
    }
    $converter=new Converter($evidence,static fn()=>Host::verify($appPath,$units));
    echo json_encode($converter->run(isset($options['apply']),$options['backup-marker']??null),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {
    // Never emit exception traces, native row values, credentials, payloads or file contents.
    fwrite(STDERR,"Conversion refused: ".Inputs::refusal($error)."\n");exit(1);
}
