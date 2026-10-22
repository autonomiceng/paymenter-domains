#!/usr/bin/env php
<?php
// Development-only, local Git objects; no provider/network access. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
foreach(['Inputs','SourceSchema','SourcePins']as$class){require __DIR__.'/conversion/'.$class.'.php';}
use DomainsConversion\{Inputs,SourcePins};
require dirname(__DIR__).'/Domains/Services/ConvertedHoldingProof.php';
try {
    $options=getopt('',['checkout:','revision:']);
    if(!is_string($options['checkout']??null)||!is_string($options['revision']??null)){
        throw new DomainException('Required: --checkout PATH --revision FULL_SHA. Checkout must be clean at that revision.');
    }
    $git=static function(array $args)use($options):string {
        $p=proc_open(['git','-C',$options['checkout'],...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p)){throw new DomainException('Local Git inventory unavailable.');}
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($p)!==0||$err!==''){throw new DomainException('Local Git inventory failed.');}return $out;
    };
    $revision=trim($git(['rev-parse','HEAD']));
    if($revision!==$options['revision']||trim($git(['status','--porcelain','--untracked-files=all','--','Enom']))!==''){
        throw new DomainException('Clean source checkout at the declared revision required.');
    }
    $files=[];foreach(explode("\n",trim($git(['ls-tree','-r','--name-only',$revision,'--','Enom'])))as$file){
        if(!str_starts_with($file,'Enom/')){throw new DomainException('Runtime source inventory required.');}
        $path=$options['checkout'].'/'.$file;
        if(is_link($path)||!is_file($path)){throw new DomainException('Regular source files required.');}
        $bytes=file_get_contents($path);
        if($bytes!==$git(['show',$revision.':'.$file])){throw new DomainException('Source checkout bytes changed.');}
        $files[substr($file,5)]=hash('sha256',$bytes);
    }
    $pins=Inputs::pins();$candidate=['revision'=>$revision,'files'=>$files];SourcePins::admit($candidate,$pins);
    foreach($pins as$pin){if($pin['revision']===$revision){throw new DomainException('Revision already pinned.');}}
    // Review the generated diff before committing; this script has no schema-support override flag.
    $pins[]=$candidate;file_put_contents(__DIR__.'/conversion/source.json',json_encode($pins,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
    echo "Source pin added; review the exact byte inventory before commit.\n";
} catch(Throwable $error){fwrite(STDERR,Inputs::refusal($error)."\n");exit(1);}
