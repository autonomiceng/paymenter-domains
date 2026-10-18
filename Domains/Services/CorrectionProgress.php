<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Frozen link on a separately authorized operation; progress never edits original requirements. */
final class CorrectionProgress
{
    public static function link(array $locked, string $key, string $action): array
    {
        $op=$locked['operations']->firstWhere('operation_key',$key);
        $section=match($action) {
            'contact_profile','contacts'=>'contacts','nameservers'=>'nameservers',
            'privacy_enable','privacy_disable'=>'privacy','auto_renew_off'=>'auto_renew',
            default=>throw new \DomainException('Unsupported correction section.'),
        };
        if (!$op || ($op->attempt['seal']??null)!=='sealed') { throw new \DomainException('No frozen original attempt.'); }
        return ['operation_key'=>$key,'section'=>$section,'generation'=>$op->authority_generation];
    }
    public static function matches(Operation $op, Operation $original, Domain $d): bool
    {
        $link=$op->policy['management']['correction_link']??null;
        try { $expected=self::link(['operations'=>collect([$original])],$original->operation_key,$op->action); }
        catch (\Throwable) { return false; }
        return $link===$expected&&($op->policy['management']['correction']??null)===$original->operation_key
            &&$original->domain_id===$d->id&&$op->domain_id===$d->id
            &&$original->authority_generation===$d->authority_generation&&$op->authority_generation===$d->authority_generation
            &&$original->account_fingerprint===$d->account_fingerprint&&$op->account_fingerprint===$d->account_fingerprint;
    }
}
