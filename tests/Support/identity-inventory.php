<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
/** Pinned-fixture tripwire only: new writes still require manual review. */
function identityInventory(string $root,array $manifest): array
{
    $actual=[];
    foreach($manifest['roots'] as $directory){
        if(!is_dir($root.'/'.$directory)){continue;} // Pinned members below report missing paths.
        // Do not follow symlinked extension directories; the runner links this extension into its fixture.
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if($file->isFile()&&$file->getExtension()==='php'){$path=substr($file->getPathname(),strlen($root)+1);$actual[$path]=hash_file('sha256',$file->getPathname());}
        }
    }
    foreach($manifest['extra'] as $path){$actual[$path]=is_file($root.'/'.$path)?hash_file('sha256',$root.'/'.$path):null;}
    $problems=[];
    foreach(array_unique([...array_keys($manifest['files']),...array_keys($actual)]) as $path){
        if(!array_key_exists($path,$manifest['files'])){$problems[]='Added native path: '.$path;}
        elseif(!array_key_exists($path,$actual)||$actual[$path]===null){$problems[]='Missing native path: '.$path;}
        elseif($manifest['files'][$path]!==$actual[$path]){$problems[]='Changed native path: '.$path;}
    }
    return $problems;
}
