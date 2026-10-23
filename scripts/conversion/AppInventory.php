<?php
// Linux process and cron collectors for the app profile. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Illuminate\Support\Facades\{DB,Schema};
final class AppInventory
{
    public static function read(string $path): string
    {
        $data=@file_get_contents($path);if(!is_string($data)){throw new \DomainException('Required app inventory inaccessible.');}return $data;
    }
    public static function collect(string $app): array
    {
        QuietWindow::primary(DB::connection());
        $queue=self::queue();
        $parked=true;foreach(['Enom','Domains']as$name){$p=$app.'/extensions/Servers/'.$name;if(file_exists($p)||is_link($p)){$parked=false;}}
        $cron=self::cron();$processes=self::processes($app);
        return [...$cron,...$processes,...$queue,'parked'=>$parked,'maintenance'=>self::read($app.'/storage/framework/down'),
            'web_fence'=>self::webFence($app),'maintenance_active'=>config('app.maintenance.driver','file')==='file'&&app()->isDownForMaintenance()];
    }
    public static function queue(): array
    {
        $driver=config('queue.connections.'.config('queue.default').'.driver');
        if($driver==='database'){Inputs::databaseQueue();}
        return ['queue_driver'=>$driver,'jobs'=>Schema::hasTable('jobs')?DB::table('jobs')->pluck('payload')->all():[]];
    }
    public const INDEX_SHA256='e1ad0c43365cdef3ee38738ea7140804e90eaeb0a11102884e97cbd0848c26af';
    public const FENCE_SHA256='a8dc7e2db39e3e41b20ccb5709ce726679ef69d7a4c78de4a7d63b6665e08289';
    public static function webFence(string $app): bool
    {
        // Paymenter 1.5.9's entry point runs this Laravel prerendered fence before autoload/bootstrap.
        foreach(['public/index.php'=>self::INDEX_SHA256,'storage/framework/maintenance.php'=>self::FENCE_SHA256]as$file=>$hash){
            $path=$app.'/'.$file;if(!is_file($path)||is_link($path)||realpath($path)!==$path||hash('sha256',self::read($path))!==$hash){return false;}
        }
        // Refuse additional executable PHP files, including public storage symlink targets.
        $pending=[$app.'/public'];$seen=[];
        while($pending){$dir=array_pop($pending);$real=realpath($dir);
            if($real===false||isset($seen[$real])||!is_readable($dir)){return false;}$seen[$real]=true;
            $entries=@scandir($dir);if($entries===false){return false;}
            foreach($entries as$entry){if($entry==='.'||$entry==='..'){continue;}$path=$dir.'/'.$entry;
                if(is_dir($path)){$pending[]=$path;continue;}
                if(!is_file($path)||!is_readable($path)){return false;}
                if(preg_match('/\.(?:php[0-9]*|phtml|phar)(?:\.|$)/i',$entry)&&$path!==$app.'/public/index.php'){return false;}
            }
        }
        return true;
    }
    // Alternate roots are an internal fixture seam; collect() always uses live OS paths.
    public static function processes(string $app,string $proc='/proc'): array
    {
        $status=self::read($proc.'/self/status');if(!preg_match('/^Uid:\s+\d+\s+(\d+)/m',$status,$m)){throw new \DomainException('Effective uid unavailable.');}$uid=(int)$m[1];
        $ancestors=[getmypid()];$pid=getmypid();while($pid>1){
            if(!preg_match('/^PPid:\s+(\d+)/m',self::read($proc.'/'.$pid.'/status'),$m)){throw new \DomainException('Process ancestry unavailable.');}
            $pid=(int)$m[1];$ancestors[]=$pid;
        }
        $mounts=self::read($proc.'/mounts');$restricted=preg_match('/\s\/proc\s+proc\s+[^\n]*\bhidepid=(?:1|2|invisible|noaccess)\b/',$mounts)===1;
        $dirs=glob($proc.'/[0-9]*');if(!$dirs){throw new \DomainException('Process inventory unavailable.');}
        $processes=[];$peers=0;$unreadable=[];
        foreach($dirs as$dir){if(in_array((int)basename($dir),$ancestors,true)){continue;}
            $s=@file_get_contents($dir.'/status');if($s===false){if(!is_dir($dir)||($restricted&&@fileowner($dir)!==false&&fileowner($dir)!==$uid)){continue;}throw new \DomainException('Required process status inaccessible.');}
            if(!preg_match('/^Uid:\s+\d+\s+(\d+)/m',$s,$m)){throw new \DomainException('Process uid unavailable.');}$own=(int)$m[1]===$uid;
            $command=@file_get_contents($dir.'/cmdline');if($command===false){if(!is_dir($dir)){continue;}throw new \DomainException('Required process command inaccessible.');}
            if($command===''&&(!$own||preg_match('/^State:\s+Z\b/m',$s))){continue;}
            $cwd=@readlink($dir.'/cwd');if($cwd===false){if(!is_dir($dir)){continue;}
                if($own){throw new \DomainException('Required process cwd inaccessible.');}$cwd=null;$unreadable[]=basename($dir);
            }
            // Linux ptrace permissions commonly conceal foreign cwd on shared hosts. Commands remain inspected.
            $paths=[];foreach(explode("\0",$command)as$arg){if($arg===''){continue;}$path=realpath(str_starts_with($arg,'/')?$arg:($cwd??'').'/'.$arg);if($path!==false){$paths[]=$path;}}
            $processes[]=['command'=>$command,'cwd'=>$cwd,'own'=>$own,'paths'=>$paths];if($own){$peers++;}
        }
        return ['processes'=>$processes,'own_peers'=>$peers,'visibility'=>$restricted?'own processes only (hidepid)':'visible process commands and own working directories',
            'foreign_cwd_unreadable'=>$unreadable];
    }
    public static function cron(string $etc='/etc'): array
    {
        $p=proc_open(['crontab','-l'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>getenv('PATH')?:'/usr/bin:/bin','LANG'=>'C','LC_ALL'=>'C']);
        if(!is_resource($p)){throw new \DomainException('User crontab unavailable.');}
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        if($code!==0&& !($code===1&&$out===''&&preg_match('/^no crontab for [^\r\n]+\s*$/D',trim($err)))){
            throw new \DomainException('User crontab unavailable; install an inspectable crontab command.');
        }
        $files=[];$unreadable=[];
        if(!is_readable($etc.'/cron.d')){$unreadable[]=$etc.'/cron.d';}
        foreach([$etc.'/crontab',...(glob($etc.'/cron.d/*')?:[])]as$file){
            if(!file_exists($file)&&!is_link($file)){continue;}$content=@file_get_contents($file);
            if($content===false){$unreadable[]=$file;}else{$files[$file]=$content;}
        }
        return ['user_cron'=>$out,'system_cron'=>$files,'cron_unreadable'=>$unreadable];
    }
}
