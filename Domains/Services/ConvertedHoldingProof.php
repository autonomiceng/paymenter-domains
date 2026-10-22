<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
/** Converter-sealed historical completion, never a dispatch/funding grant. Source audit stays outside runtime. */
final class ConvertedHoldingProof
{
    public const SOURCE_REVISIONS=['b9b12e9675c8aaea90b5c6ccd4d30b9e42af4dad','e1ace20007e2141e71812f5f46565208d2c31cef'];
    public static function completed(Operation $o,Domain $d,AccountBinding $b): bool
    {
        $proof=$o->attempt['converted_completion']??[];$data=$proof['data']??null;
        if($d->provenance!=='converted_purchase'||$d->adoptionPending()||$d->registrar_key!=='enom'||!is_array($data)
            ||!is_string($proof['mac']??null)||!hash_equals(hash_hmac('sha256','domains-conversion-v1:'.json_encode($data,JSON_THROW_ON_ERROR),(string)config('app.key')),$proof['mac'])){return false;}
        return $o->state==='succeeded'&&in_array($o->action,['register','renew'],true)&&$o->account_fingerprint===$b->fingerprint
            &&$d->account_fingerprint===$b->fingerprint&&$o->domain_id===$d->id&&$o->service_id===$d->service_id&&$o->server_id===$d->server_id
            &&$o->registrar_key===$d->registrar_key&&$o->authority_generation===$d->authority_generation
            &&$o->confirmed_expires_at?->format('Y-m-d')===$d->provider_expires_at?->format('Y-m-d')
            &&($data['projection']??null)===[$o->id,$o->operation_key,$o->action,(int)$o->term,$d->id,$d->domain,$d->service_id,$d->server_id,
                $b->fingerprint,$d->authority_generation,$d->provider_domain_id,$o->provider_order_id,$d->provider_expires_at?->format('Y-m-d'),$o->cost['actual']??null,$o->attempt['authorized_cost']??null]
            &&in_array($data['revision']??null,self::SOURCE_REVISIONS,true)&&preg_match('/^[a-f0-9]{64}$/D',$data['source_digest']??'')===1
            &&preg_match('/^[a-f0-9]{64}$/D',$data['accepted_read_sha256']??'')===1;
    }
}
