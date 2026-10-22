<?php
// Application-scoped maintenance policy. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Illuminate\Support\Facades\{DB,Schema};
final class AppHost
{
    public static function verify(string $app,array $operator,int $seconds=120): array
    {
        self::seconds($seconds);
        $inspect=static function()use($app,$operator):array {
            $inventory=AppInventory::collect($app);self::quiescent($app,$operator,$inventory);Inputs::registrarsOff();return $inventory;
        };
        $before=$inspect();$snapshot=QuietWindow::snapshot();$start=hrtime(true);
        // Real monotonic time, never a caller-provided elapsed-time assertion.
        do{usleep(250_000);}while(hrtime(true)-$start<$seconds*1_000_000_000);
        $after=$inspect();QuietWindow::unchanged($snapshot,QuietWindow::snapshot());
        return ['profile'=>'app','operator_inventory_sha256'=>Inputs::digest($operator),'quiet_seconds'=>$seconds,'cron_unreadable'=>$after['cron_unreadable'],
            'process_visibility'=>$after['visibility'],'foreign_cwd_unreadable'=>$after['foreign_cwd_unreadable']];
    }
    public static function seconds(int $seconds): void
    {if($seconds<60){throw new \DomainException('Quiet window must be at least 60 seconds.');}}
    /** Synthetic policy seam; production always collects its own inputs. */
    public static function quiescent(string $app,array $operator,array $i): void
    {
        if(($operator['profile']??null)!=='app'||($operator['external_schedulers_disabled']??null)!==true||($operator['web_workers_drained_after_maintenance']??null)!==true
            ||($operator['standard_public_index_only']??null)!==true||($operator['web_auto_prepend_disabled']??null)!==true
            ||!is_array($operator['stopped']??null)||!$operator['stopped']||!array_is_list($operator['stopped'])){
            throw new \DomainException('App operator inventory and disabled external schedulers required.');
        }
        foreach($operator['stopped']as$entry){if(!is_string($entry)||trim($entry)===''||strlen($entry)>1000){throw new \DomainException('Stopped entry points require explicit descriptions.');}}
        $down=json_decode($i['maintenance']??'',true);
        if(!is_array($down)||!str_starts_with(ltrim($i['maintenance']??''),'{')||($down['secret']??null)!==null||($down['redirect']??null)!==null||($down['except']??[])!==[]||!is_string($down['template']??null)||$down['template']===''||($down['status']??503)!==503
            ||($i['maintenance_active']??null)!==true||($i['web_fence']??null)!==true){throw new \DomainException('Prerendered file maintenance without configured bypass or exceptions required.');}
        if(($i['parked']??null)!==true){throw new \DomainException('Park both registrar runtimes outside the active extension directory.');}
        if(!isset($i['processes'],$i['own_peers'],$i['visibility'])||!is_array($i['processes'])
            ||($i['own_peers']<1&&$i['visibility']!=='own processes only (hidepid)')){
            throw new \DomainException('Process visibility must be positively established.');
        }
        foreach($i['processes']as$p){
            if(!is_string($p['command']??null)||!array_key_exists('cwd',$p)||($p['own']??true)&&!is_string($p['cwd'])){
                throw new \DomainException('Required process inventory inaccessible.');
            }
            $command=str_replace("\0",' ',$p['command']);$cwd=$p['cwd'];if(is_string($cwd)&&str_ends_with($cwd,' (deleted)')){$cwd=substr($cwd,0,-10);}
            foreach($p['paths']??[]as$path){if($path===$app||str_starts_with($path,$app.'/')){throw new \DomainException('Application process still active.');}}
            if(str_contains($command,$app)||$cwd===$app||(is_string($cwd)&&str_starts_with($cwd,$app.'/'))
                ||preg_match('/\bartisan\b/i',$command)){
                throw new \DomainException('Application process still active.');
            }
        }
        if(!is_string($i['user_cron']??null)||!is_array($i['system_cron']??null)){
            throw new \DomainException('Required cron inventory inaccessible.');
        }
        foreach([$i['user_cron'],...$i['system_cron']]as$content){
            if(!is_string($content)){throw new \DomainException('Required cron inventory inaccessible.');}
            foreach(explode("\n",$content)as$line){if(trim($line)===''||str_starts_with(ltrim($line),'#')){continue;}
                if(str_contains($line,$app)||preg_match('/artisan|schedule:run/i',$line)){throw new \DomainException('Application cron entry still active.');}}
        }
        if(!in_array($i['queue_driver']??null,['database','sync'],true)){throw new \DomainException('Only database or sync queues supported in app profile.');}
        if(!is_array($i['jobs']??null)){throw new \DomainException('Required queue inventory inaccessible.');}
        foreach($i['jobs']as$job){
            if(!is_string($job)){throw new \DomainException('Registrar queue payload cannot be inspected.');}
            try{$decoded=json_decode($job,true,128,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \DomainException('Registrar queue payload cannot be inspected.');}
            if(!is_array($decoded)||array_is_list($decoded)){throw new \DomainException('Registrar queue payload cannot be inspected.');}
            $strings=[];array_walk_recursive($decoded,static function($v)use(&$strings):void{if(is_string($v)){$strings[]=$v;}});
            foreach($strings as$text){if(preg_match('/Enom|Domains/i',$text)){
                throw new \DomainException('Registrar queue jobs must be drained before app conversion.');
            }}
        }
    }
}
