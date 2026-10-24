<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Illuminate\Support\Facades\DB;
/** Offline evidence only. No transport, shell interpolation or old class deserialization. */
final class Inputs
{
    public const REVISION='b9b12e9675c8aaea90b5c6ccd4d30b9e42af4dad';
    private static ?string $selected=null;
    public static function selectedRevision(): string { return self::$selected??throw new \DomainException('Source revision must be selected before planning.'); }
    public static function pins(): array
    {return json_decode(file_get_contents(__DIR__.'/source.json'),true,512,JSON_THROW_ON_ERROR);}
    public static function revision(string $revision): void
    {if(!in_array($revision,array_column(self::pins(),'revision'),true)){throw new \DomainException('Accepted source revision required.');}self::$selected=$revision;}
    public static function hashes(string $revision,array $actual): void
    {
        self::revision($revision);$pin=array_values(array_filter(self::pins(),fn($p)=>$p['revision']===$revision))[0];
        ksort($actual);$expected=$pin['files'];ksort($expected);
        if($actual!==$expected){throw new \DomainException('Installed source bytes differ from accepted revision.');}
    }
    public static function source(string $directory,string $revision): void
    {
        self::revision($revision);
        if(!is_dir($directory)||is_link($directory)){throw new \DomainException('Accepted source revision required.');}
        $actual=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink()||!$file->isFile()){throw new \DomainException('Source links are refused.');}
            $actual[substr($file->getPathname(),strlen(rtrim($directory,'/'))+1)]=hash_file('sha256',$file->getPathname());
        }
        self::hashes($revision,$actual);self::$selected=$revision;
    }
    public static function json(string $file): array
    {
        if(!is_file($file)||is_link($file)||filesize($file)>8_000_000){throw new \DomainException('A bounded regular evidence file is required.');}
        return json_decode(file_get_contents($file),true,128,JSON_THROW_ON_ERROR);
    }
    /** Backup is an operator-created manifest, with hashes checked against both retained archives. */
    public static function backup(?string $path,string $digest): void
    {
        if(!$path){throw new \DomainException('Apply requires a prior backup marker.');}$b=self::json($path);
        if(($b['revision']??null)!==self::selectedRevision()||($b['source_digest']??null)!==$digest
            ||($b['key_sha256']??null)!==hash('sha256',(string)config('app.key'))){throw new \DomainException('Backup does not match this source/key.');}
        foreach(['database','files']as$kind){$p=$b[$kind]['path']??'';
            if(!is_string($p)||!is_file($p)||is_link($p)||filesize($p)===0||hash_file('sha256',$p)!==($b[$kind]['sha256']??null)
                ||filemtime($p)>filemtime($path)){throw new \DomainException('Prior matching backup archives required.');}}
    }
    /** Masked, inactive units plus maintenance file are verified on Linux systemd deployments only. */
    public static function maintenance(array $units,?\Closure $inspect=null): void
    {
        if(!app()->isDownForMaintenance()||array_keys($units)!==['checkout','schedulers','workers','dispatch']){throw new \DomainException('Maintenance and all four stopped roles required.');}
        $inspect??=static function(string $unit):string {
            $pipes=[];$process=proc_open(['systemctl','show','--property=ActiveState,UnitFileState','--', $unit],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process)){throw new \DomainException('Cannot inspect service manager.');}
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0||$err!==''){throw new \DomainException('Service inspection failed.');}return $out;
        };
        foreach($units as $role=>$names){if(!is_array($names)||!$names){throw new \DomainException('Every stopped role requires a unit.');}
            foreach($names as $unit){if(!is_string($unit)||Host::template($unit)||!preg_match('/^[a-zA-Z0-9_.@-]+\.(service|timer|socket|path)$/D',$unit)){throw new \DomainException('Explicit systemd unit instance required.');}
                $state=$inspect($unit);if(!str_contains($state,"ActiveState=inactive\n")||!str_contains($state,"UnitFileState=masked\n")){throw new \DomainException('All maintenance units must be inactive and masked.');}}}
        self::registrarsOff();
    }
    public static function registrarsOff(): void
    {
        if(\App\Models\Server::where('extension','NameSilo')->exists()){throw new \DomainException('Independent NameSilo server is unsupported; keep it retired separately.');}
        foreach(\App\Models\Server::whereIn('extension',['Enom','Domains'])->get() as $s){
            foreach(['operations_enabled','domains_billing_enabled','domains_observations_enabled']as$key){if(filter_var($s->settings->firstWhere('key',$key)?->value,FILTER_VALIDATE_BOOLEAN)){throw new \DomainException('Registrar dispatch/billing/observations must be off.');}}}
        if(\Illuminate\Support\Facades\Schema::hasTable('domains_accounts')&&DB::table('domains_accounts')->where('processing_enabled',true)->exists()){throw new \DomainException('Neutral processing must be off.');}
    }
    public static function refusal(\Throwable $error): string
    {return $error instanceof \DomainException?$error->getMessage():'Review source/schema, offline evidence, maintenance and backup prerequisites.';}
    public static function decrypt(?string $value): array { return $value===null?[]:json_decode(decrypt($value,false),true,128,JSON_THROW_ON_ERROR); }
    public static function encrypt(array $value): string { return encrypt(json_encode($value,JSON_THROW_ON_ERROR),false); }
    public static function digest(mixed $value): string { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR)); }
}
