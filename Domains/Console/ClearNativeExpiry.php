<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Console;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\NativeIdentity;
/** Explicit, repeatable cutover repair. Never changes provider expiry or invoices. */
final class ClearNativeExpiry extends \Illuminate\Console\Command
{
    protected $signature='domains:native-expiry {--clear : Clear the native expiry date on each managed domain service found}';
    protected $description='Find managed domain services with a native expiry date; clear them with --clear';
    public function handle(): int
    {
        $found=0;$cleared=0;
        foreach(Service::whereNotNull('expires_at')->lazyById(100)as$seed){
            DB::transaction(function()use($seed,&$found,&$cleared):void {
                $s=Service::whereKey($seed->id)->lockForUpdate()->first();
                if(!$s||$s->expires_at===null||!NativeIdentity::routed($s)){return;}
                ++$found;if(!$this->option('clear')){return;}
                // Deliberately bypass native form validation, not model events. Other dirty
                // attributes are absent and each classification is rechecked under its lock.
                $s->expires_at=null;$s->saveOrFail();++$cleared;
                logger()->notice('Managed domain legacy native expiry cleared.',['service_id'=>$s->id]);
            });
        }
        $this->line("Managed domain services with a native expiry date: {$found}. Cleared: {$cleared}.");
        if($found>0&&!$this->option('clear')){$this->line('Pause processing and drain native workers, then run again with --clear.');}
        return $found>0&&!$this->option('clear')?self::FAILURE:self::SUCCESS;
    }
}
