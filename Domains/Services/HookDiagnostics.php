<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Process-local diagnostics. No operation or financial state is written. */
final class HookDiagnostics
{
    private static array $deferred=[];
    public static function context(array $missing): array
    {
        $context=['missing_events'=>array_map(static fn(string $name):string=>match($name){
            'Service saving model event'=>'eloquent.saving: '.\App\Models\Service::class,
            'Service updating model event'=>'eloquent.updating: '.\App\Models\Service::class,
            'Service updated model event'=>'eloquent.updated: '.\App\Models\Service::class,
            default=>$name,
        },$missing),'mode'=>app()->runningInConsole()?'console':'http','pid'=>getmypid()];
        if(app()->runningInConsole()){
            $command=self::command();
            $context['command']=is_string($command)&&preg_match('/^[a-z][a-z0-9:_-]{0,127}$/iD',$command)?$command:null;
        }else{
            // Route templates exclude customer identifiers. Unmatched paths are never logged.
            try{$context['request_path']='/'.ltrim(app('router')->getRoutes()->match(request())->uri(),'/');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface){$context['request_path']='[unmatched]';}
        }
        $release=config('app.release')??env('RELEASE_ID');
        if(is_string($release)&&preg_match('/^[a-z0-9._:+-]{1,128}$/iD',$release)){$context['release']=$release;}
        return $context;
    }
    private static function command(): ?string
    {
        // Symfony globals plus Laravel's optional --env value, without starting Artisan.
        $definition=(new \Symfony\Component\Console\Application)->getDefinition();
        $definition->addOption(new \Symfony\Component\Console\Input\InputOption('env',null,\Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL));
        $prefix=[];
        foreach($_SERVER['argv']??[] as$token){
            if(!is_string($token)){return null;}
            $prefix[]=$token;
            $input=new \Symfony\Component\Console\Input\ArgvInput($prefix);
            try{$input->bind($definition);}
            // Unknown or malformed options make the following values unsafe to attribute.
            catch(\Symfony\Component\Console\Exception\RuntimeException){return null;}
            $command=$input->getArgument('command');
            // Stop before command-specific options and customer arguments are parsed.
            if($command!==null){return $command;}
        }
        return null;
    }
    public static function deferred(int $operation,array $missing): void
    {
        if(isset(self::$deferred[$operation])){return;}
        self::$deferred[$operation]=true;
        logger()->warning('Domains paid operation deferred: required Service listeners missing.',[
            'operation_id'=>$operation,...self::context($missing)]);
    }
}
