<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
/** Dedicated standard Linux/systemd host only; all cron spools and application runtimes must be inspectable. */
final class Host
{
    public static function verify(string $app,array $units): void
    {
        foreach(['Enom','Domains']as$name){if(file_exists($app.'/extensions/Servers/'.$name)||is_link($app.'/extensions/Servers/'.$name)){throw new \DomainException('Park both registrar runtimes outside the active extension directory.');}}
        $ancestors=[getmypid()];$pid=getmypid();while($pid>1){$status=self::contents('/proc/'.$pid.'/status');if(!preg_match('/^PPid:\s+(\d+)/m',$status,$m)){throw new \DomainException('Process ancestry unavailable.');}$pid=(int)$m[1];$ancestors[]=$pid;}
        // The manager's effective view includes loaded instances, aliases, templates and drop-ins.
        $names=[];foreach(['list-unit-files','list-units']as$verb){
            $args=[$verb,'--type=service','--type=timer','--type=socket','--type=path','--all','--no-legend','--no-pager','--plain'];
            foreach(explode("\n",self::systemctl($args))as$line){if(trim($line)===''){continue;}$name=preg_split('/\s+/',trim($line))[0];
                if(!preg_match('/^[a-zA-Z0-9_.@-]+\.(service|timer|socket|path)$/D',$name)){throw new \DomainException('Unsupported effective unit name.');}$names[$name]=true;}}
        if(!$names){throw new \DomainException('Effective unit inventory unavailable.');}$files=[];
        foreach(array_keys($names)as$name){$files[$name]=self::systemctl(['show','--property=Id,Names,FragmentPath,DropInPaths,ExecStart,WorkingDirectory,Triggers,TriggeredBy','--',$name]);}
        $processes=[];foreach(glob('/proc/[0-9]*/cmdline')?:[]as$path){$dir=dirname($path);if(in_array((int)basename($dir),$ancestors,true)){continue;}
            $command=@file_get_contents($path);if($command===false){if(!is_dir($dir)){continue;}throw new \DomainException('Process inventory inaccessible.');}
            if($command===''){continue;}$cwd=@readlink($dir.'/cwd');if($cwd===false){if(!is_dir($dir)){continue;}throw new \DomainException('Process working directory inaccessible.');}$processes[]=['command'=>$command,'cwd'=>$cwd];}
        $cron=[];foreach(['/etc/crontab','/etc/anacrontab',...glob('/etc/cron.d/*')]as$file){if(is_file($file)){$cron[]=self::contents($file);}}
        foreach(['/etc/cron.hourly','/etc/cron.daily','/etc/cron.weekly','/etc/cron.monthly']as$dir){
            if(!is_dir($dir)||!is_readable($dir)){throw new \DomainException('Periodic cron inventory inaccessible.');}
            foreach(new \DirectoryIterator($dir)as$file){if($file->isDot()){continue;}if($file->isLink()||!$file->isFile()){throw new \DomainException('Unsupported periodic cron entry.');}$cron[]=self::contents($file->getPathname());}}
        $spools=0;foreach(['/var/spool/cron','/var/spool/cron/crontabs']as$dir){if(!is_dir($dir)){continue;}$spools++;if(!is_readable($dir)){throw new \DomainException('All-user cron inventory inaccessible.');}
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS))as$file){if($file->isLink()||!$file->isFile()){throw new \DomainException('Unsupported cron spool.');}$cron[]=self::contents($file->getPathname());}}
        if(!$spools){throw new \DomainException('Standard all-user cron spool must be inspectable.');}
        self::quiescent($app,$units,$files,$processes,$cron);Inputs::maintenance($units);
    }
    private static function systemctl(array $args): string
    {
        $pipes=[];$p=proc_open(['systemctl',...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p)){throw new \DomainException('Effective unit inventory unavailable.');}
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($p)!==0||$err!==''){throw new \DomainException('Effective unit inventory unavailable.');}return $out;
    }
    private static function contents(string $path): string
    {
        $data=@file_get_contents($path);if(!is_string($data)){throw new \DomainException('Required host inventory inaccessible.');}return $data;
    }
    /** Pure policy check used by offline fixtures; production always collects its own inventories above. */
    public static function quiescent(string $app,array $units,array $files,array $processes,array $cron): void
    {
        $listed=array_merge(...array_values($units));
        $related=[];foreach($files as$file=>$content){if(str_contains($content,$app)||preg_match('/^user@.*\.service$/D',basename($file))||preg_match('/(?:php(?:[0-9.]+)?(?:-fpm)?|nginx|apache2?|httpd|caddy|frankenphp|cron|anacron|run-parts)/i',basename($file).' '.$content)){$related[basename($file)]=true;}}
        do{$before=count($related);foreach($files as$file=>$content){$name=basename($file);preg_match_all('/^(?:Triggers|TriggeredBy|Names)=(.*)$/m',$content,$m);
                $edges=preg_split('/\s+/',implode(' ',$m[1]));if(isset($related[$name])||array_intersect($edges,array_keys($related))){$related[$name]=true;foreach($edges as$edge){if($edge!==''){$related[$edge]=true;}}}}}while(count($related)>$before);
        foreach(array_keys($related)as$name){if(!in_array($name,$listed,true)){throw new \DomainException('Application unit omitted from stopped inventory.');}}
        foreach($processes as$process){$command=str_replace("\0",' ',$process['command']);
            if(preg_match('/systemd\s+--user(?:\s|$)/',$command)||str_contains($command,$app)||$process['cwd']===$app||str_starts_with($process['cwd'],$app.'/')||preg_match('/(?:^|[\/\s])(?:php(?:[0-9.]+)?(?:-fpm)?|nginx|apache2?|httpd|caddy|frankenphp|artisan|horizon)(?:\s|$|:)/i',$command)){throw new \DomainException('Application/web process still active on dedicated host.');}}
        foreach($cron as$content){foreach(explode("\n",$content)as$line){if(trim($line)!==''&&!str_starts_with(ltrim($line),'#')&&!preg_match('/^[A-Z_]+=/',trim($line))){throw new \DomainException('All-user cron must be empty on supported host.');}}}
    }
}
