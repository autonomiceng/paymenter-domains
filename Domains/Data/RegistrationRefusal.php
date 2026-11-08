<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Minimal refused-order envelope bound to the exact admitted send. Never reconstructed from a later read. */
final readonly class RegistrationRefusal
{
    private function __construct(public array $record) {}
    public static function envelope(mixed $facts): ?array
    {
        return is_array($facts)&&array_keys($facts)===['err_count','rrp_code','text','order_id','done']
            &&is_int($facts['err_count'])&&$facts['err_count']>=1&&$facts['err_count']<=100
            &&is_string($facts['rrp_code'])&&$facts['rrp_code']==='541'
            &&in_array($facts['text'],['Validation error. Failed to create order.','Validation error; failed to add; order(s)'],true)
            &&$facts['order_id']===null&&$facts['done']===true?$facts:null;
    }
    public static function enom(AdmittedOperation $op, array $envelope): self
    {
        if($op->binding->registrarKey!=='enom'||$op->query->action!==Action::Register||self::envelope($envelope)===null){
            throw new \InvalidArgumentException('Invalid registration refusal.');}
        return new self(['operation_key'=>$op->operationKey,'claim_key'=>$op->claimKey,'generation'=>$op->authorityGeneration,
            'server_id'=>$op->binding->serverId,'account_fingerprint'=>$op->binding->fingerprint,
            'name'=>$op->query->name,'years'=>$op->query->years,'envelope'=>$envelope]);
    }
}
