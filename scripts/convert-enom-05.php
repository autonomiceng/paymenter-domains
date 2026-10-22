#!/usr/bin/env php
<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One-time offline maintenance tool, excluded from runtime.
declare(strict_types=1);
require __DIR__.'/conversion/Inputs.php';
use DomainsConversion\{Inputs,Host,Converter};
$options=getopt('',['app:','source:','source-revision:','evidence:','units:','host-profile:','operator-inventory:','quiet-seconds:','dry-run','stage-schema','apply','backup-marker:']);
try {
    foreach(['app','source','source-revision','evidence']as$key){if(!isset($options[$key])||!is_string($options[$key])){throw new DomainException('Required: --app --source --source-revision --evidence --units; default --dry-run. Apply additionally requires --apply --backup-marker.');}}
    $profile=$options['host-profile']??'dedicated';
    if(!in_array($profile,['dedicated','app'],true)){throw new DomainException('Choose --host-profile=dedicated|app.');}
    $inventory=$profile==='app'?'operator-inventory':'units';
    if(!isset($options[$inventory])||!is_string($options[$inventory])){
        throw new DomainException($profile==='dedicated'?'Required: --app --source --source-revision --evidence --units; default --dry-run. Apply additionally requires --apply --backup-marker.':'App profile requires --operator-inventory.');
    }
    if(isset($options[$profile==='app'?'units':'operator-inventory'])){throw new DomainException('Provide only the inventory for the selected host profile.');}
    $quiet=$options['quiet-seconds']??'120';
    if(!is_string($quiet)||!preg_match('/^[0-9]{2,5}$/D',$quiet)||(int)$quiet<60){throw new DomainException('Quiet window must be at least 60 seconds.');}
    if(isset($options['apply'],$options['dry-run'])){throw new DomainException('Choose dry-run or apply.');}
    Inputs::source($options['source'],$options['source-revision']);$appPath=realpath($options['app']);
    if(!$appPath||$appPath!==$options['app']||!is_file($appPath.'/bootstrap/app.php')){throw new DomainException('Canonical Paymenter application path required.');}
    foreach(['Enom','Domains']as$name){if(file_exists($appPath.'/extensions/Servers/'.$name)){throw new DomainException('Park registrar runtimes before bootstrap.');}}
    $lock=fopen($appPath.'/storage/framework/domains-conversion.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new DomainException('Another conversion owns maintenance.');}
    $loader=require $appPath.'/vendor/autoload.php';$application=require $appPath.'/bootstrap/app.php';
    $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__).'/Domains/',true);
    foreach(['SourceSchema','Planner','Mapper','Journal','AppInventory','QuietWindow','AppHost','Host','Converter']as$class){require __DIR__.'/conversion/'.$class.'.php';}
    $units=Inputs::json($options[$inventory]);$evidence=Inputs::json($options['evidence']);
    $checks=[];$inspect=static function()use($appPath,$units,$profile,$quiet,&$checks):void{$checks[]=Host::profile($appPath,$units,$profile,(int)$quiet);};
    $inspect();\DomainsConversion\SourceSchema::verify();
    if(isset($options['stage-schema'])){
        if(isset($options['apply'])){throw new DomainException('Stage schema separately from apply.');}
        Converter::stageSchema();if($profile==='app'){$inspect();}echo json_encode($profile==='app'?['schema_staged'=>true,'host_checks'=>$checks]:['schema_staged'=>true])."\n";exit(0);
    }
    $converter=new Converter($evidence,$inspect,$profile);
    $result=$converter->run(isset($options['apply']),$options['backup-marker']??null);if($profile==='app'){$result['host_checks']=$checks;}
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {
    // Never emit exception traces, native row values, credentials, payloads or file contents.
    fwrite(STDERR,"Conversion refused: ".Inputs::refusal($error)."\n");exit(1);
}
