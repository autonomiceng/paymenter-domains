<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Log,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Disposition,DriverResult,Money,RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Recording never changes a spending guard's result or makes another provider request. */
final class BalanceReads
{
    public static function read(RegistrarDriver $reader, RequestContext $context, string $source): DriverResult
    {
        try {
            $result = $reader->balance($context);
            $context->binding->requireMatch($result->binding);
        } catch (\Throwable $e) {
            // A local quota refusal did not read the provider and cannot invalidate its last result.
            if(!$e instanceof ReadDeferred){self::record($context->binding,null,$context->now(),$source,'read_failed');}
            throw $e;
        }
        $money = $result->disposition === Disposition::Accepted ? $result->evidence()->balance : null;
        self::record($context->binding,$money,$context->now(),$source,$money?null:'read_failed');
        return $result;
    }
    public static function record(AccountBinding $binding, ?Money $money, \DateTimeImmutable $at, string $source, ?string $error=null): void
    {
        // Standalone protocol tests and pre-upgrade fixtures have no extension storage.
        try {
            if (!class_exists(DB::class) || !app()->bound('db') || !Schema::hasTable(BalanceSchema::TABLE)) { return; }
            if (!in_array($source,['daily','preflight','renewal','manual'],true)) { throw new \LogicException('Invalid balance source.'); }
            DB::transaction(function()use($binding,$money,$at,$source,$error):void {
                $a=Account::where('server_id',$binding->serverId)->lockForUpdate()->firstOrFail();
                $binding->requireMatch(CatalogueAcceptances::binding($a));
                $q=DB::table(BalanceSchema::TABLE)->where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint);
                $row=(clone $q)->lockForUpdate()->first();
                if ($row && (int)$row->attempted_at>$at->getTimestamp()) { return; }
                $values=['source'=>$source,'attempted_at'=>$at->getTimestamp(),
                    'last_error_code'=>$money?null:(in_array($error,['read_failed','rate_limited','configuration_unavailable'],true)?$error:'read_failed')];
                if ($money) { $values += ['amount'=>$money->amount,'currency'=>$money->currency,'read_at'=>$at->getTimestamp()]; }
                if($row){$q->update($values);}else{DB::table(BalanceSchema::TABLE)->insert($values+['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint]);}
            });
        } catch (\Throwable $e) { Log::warning('Registrar balance could not be recorded.',['exception_class'=>$e::class]); }
    }
}
