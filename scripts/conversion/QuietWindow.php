<?php
// Persisted quiet-window observations, no locks/cache writes. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Illuminate\Support\Facades\{DB,Schema,Cache};
final class QuietWindow
{
    public static function primary($db): void
    {
        if($db->getConfig('read')!==null||$db->getConfig('write')!==null||$db->getReadPdo()!==$db->getPdo()){
            throw new \DomainException('Split read/write database connections unsupported.');
        }
    }
    public static function snapshot(): array
    {
        $db=DB::connection();self::primary($db);if($db->transactionLevel()!==0){throw new \DomainException('Quiet window requires current reads outside transactions.');}
        $tables=Schema::getTableListing(Schema::getCurrentSchemaListing(),false);sort($tables);$result=[];
        // All tables include every planner relation, jobs/failed_jobs and all old/new extension tables.
        foreach($tables as$table){
            $columns=Schema::getColumnListing($table);$rows=$db->table($table)->get()->map(fn($r)=>(array)$r)->all();
            $encoded=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($encoded);
            $result['tables'][$table]=['max_id'=>in_array('id',$columns,true)?$db->table($table)->max('id'):null,
                'updated_at'=>in_array('updated_at',$columns,true)?$db->table($table)->max('updated_at'):null,'rows'=>count($rows),'digest'=>Inputs::digest($encoded)];
        }
        $result['mutexes']=self::mutexes();self::noMutex($result['mutexes']);return $result;
    }
    public static function noMutex(array $mutexes): void
    {if($mutexes){throw new \DomainException('Schedule mutex or uninspectable live file cache entry present.');}}
    public static function unchanged(array $before,array $after): void
    {
        self::noMutex($before['mutexes']??[]);self::noMutex($after['mutexes']??[]);
        if($before!==$after){throw new \DomainException('Application changed during quiet window.');}
    }
    public static function mutexes(): array
    {
        $found=[];foreach(self::stores()as$name){$found=[...$found,...self::storeMutexes($name)];}return $found;
    }
    public static function stores(): array
    {
        $schedule=app(\Illuminate\Console\Scheduling\Schedule::class);
        if(get_class($schedule)!==\Illuminate\Console\Scheduling\Schedule::class){throw new \DomainException('Custom schedule implementation unsupported.');}$names=[config('cache.default')];
        $mutexes=[(new \ReflectionProperty($schedule,'eventMutex'))->getValue($schedule),(new \ReflectionProperty($schedule,'schedulingMutex'))->getValue($schedule)];
        foreach($schedule->events()as$event){
            if(!in_array(get_class($event),[\Illuminate\Console\Scheduling\Event::class,\Illuminate\Console\Scheduling\CallbackEvent::class],true)){throw new \DomainException('Custom schedule event unsupported.');}
            if($event->mutexNameResolver!==null){throw new \DomainException('Custom schedule mutex names require separate support.');}$mutexes[]=$event->mutex;
        }
        foreach($mutexes as$mutex){
            if(!in_array(get_class($mutex),[\Illuminate\Console\Scheduling\CacheEventMutex::class,\Illuminate\Console\Scheduling\CacheSchedulingMutex::class],true)
                ||$mutex->cache!==Cache::getFacadeRoot()){throw new \DomainException('Custom schedule mutex implementation unsupported.');}
            $name=$mutex->store??config('cache.default');if(!is_string($name)){throw new \DomainException('Schedule cache name unavailable.');}$names[]=$name;
        }
        return array_values(array_unique($names));
    }
    private static function storeMutexes(string $name): array
    {
        $config=config('cache.stores.'.$name);$store=Cache::store($name)->getStore();$found=[];
        if(($config['driver']??null)==='database'&&get_class($store)===\Illuminate\Cache\DatabaseStore::class){
            $connection=$store->getConnection();
            foreach([[$connection,(new \ReflectionProperty($store,'table'))->getValue($store),false],[$store->getLockConnection()??$connection,(new \ReflectionProperty($store,'lockTable'))->getValue($store),true]]as[$db,$table,$locks]){
                self::primary($db);foreach($db->table($table)->get()as$row){if(($locks||str_contains($row->key,'framework/schedule-'))&&(int)$row->expiration>time()){
                    $found[]=['table'=>$table,'key'=>$row->key,'expiration'=>$row->expiration];
                }}
            }
            return $found;
        }
        if(($config['driver']??null)==='file'&&get_class($store)===\Illuminate\Cache\FileStore::class){
            // File cache hashes its keys irreversibly. Conservatively require no live entries (including locks).
            foreach(array_unique([$store->getDirectory(),(new \ReflectionProperty($store,'lockDirectory'))->getValue($store)??$store->getDirectory()])as$root){
                if(!is_dir($root)||!is_readable($root)){throw new \DomainException('File cache inventory inaccessible.');}
                $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
                foreach($iterator as$file){if($file->isLink()||!$file->isFile()){throw new \DomainException('File cache inventory inaccessible.');}
                    if($file->getFilename()==='.gitignore'){continue;}$bytes=AppInventory::read($file->getPathname());
                    if(!preg_match('/^\d{10}/D',$bytes)){throw new \DomainException('File cache entry unreadable.');}
                    if((int)substr($bytes,0,10)>time()){$found[]=['file'=>hash('sha256',$file->getPathname()),'digest'=>hash('sha256',$bytes)];}
                }
            }
            return $found;
        }
        throw new \DomainException('Persisted database or empty file cache inventory required; unsupported cache store.');
    }
}
