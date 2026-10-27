<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Illuminate\Support\Facades\DB;
final class EvidenceHost
{
    public static function readOnly(\Illuminate\Database\Connection $db): void
    {
        if($db->getConfig('read')||$db->getConfig('write')||$db->transactionLevel()!==0){throw new \DomainException('Single primary read-only connection required.');}
        // A lost session must fail closed instead of reconnecting without its SQL read-only mode.
        $db->setReconnector(static function():never{throw new \DomainException('Collector read-only session cannot reconnect.');});
        // Native PDO session controls, without changing any framework resolver or query builder.
        match($db->getDriverName()){
            'sqlite'=>$db->getPdo()->exec('PRAGMA query_only = ON'),
            'mysql','mariadb'=>$db->getPdo()->exec('SET SESSION TRANSACTION READ ONLY'),
            default=>throw new \DomainException('Unsupported read-only database driver.'),
        };
    }
    public static function boot(string $path): void
    {
        if(realpath($path)!==$path||!is_file($path.'/bootstrap/app.php')||!is_file($path.'/vendor/autoload.php')){throw new \DomainException('Canonical app required.');}
        foreach(['Enom','Domains']as$name){$p=$path.'/extensions/Servers/'.$name;if(file_exists($p)||is_link($p)){throw new \DomainException('Park registrar runtimes before collection.');}}
        $loader=require $path.'/vendor/autoload.php';$application=require $path.'/bootstrap/app.php';
        $application->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class,static function($app):void{
            // Configure memory-only framework stores before provider registration/boot can use them.
            $app['config']->set(['cache.default'=>'array','session.driver'=>'array','logging.default'=>'null',
                'logging.channels.null'=>['driver'=>'monolog','handler'=>\Monolog\Handler\NullHandler::class]]);
            $app->register(\Illuminate\Database\DatabaseServiceProvider::class);
            $name=$app['config']->get('database.default');$configuration=$app['config']->get('database.connections.'.$name);
            if(!is_array($configuration)){throw new \DomainException('Default database required.');}
            // Auxiliary connections are unavailable in this development process; all application reads share the protected session.
            $app['config']->set('database.connections',[$name=>$configuration]);
            self::readOnly($app['db']->connection($name));
        });
        $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__,2).'/Domains/',true);
    }
}
