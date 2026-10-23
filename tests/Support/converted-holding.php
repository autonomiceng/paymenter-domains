<?php
// Synthetic converter-sealed history only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
function convertedHoldingFixture(\Paymenter\Extensions\Servers\Domains\Models\Operation $original): array
{
    \Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();
    $d=$original->domain->fresh();
    \Illuminate\Support\Facades\DB::table('domains_domains')->where('id',$d->id)->update(['provenance'=>'converted_purchase']);
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$original->id)->update(['action'=>'renew']);
    $d=$d->fresh();$op=$original->fresh();
    // A converted renewal retains the matched historical cap required by the real converter.
    $op->attempt=[...$op->attempt,'authorized_cost'=>$op->cost['actual']];
    $binding=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(
        \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->firstOrFail());
    $data=['revision'=>\Paymenter\Extensions\Servers\Domains\Services\ConvertedHoldingProof::SOURCE_REVISIONS[0],
        'source_digest'=>str_repeat('a',64),'accepted_read_sha256'=>str_repeat('b',64),
        'projection'=>[$op->id,$op->operation_key,$op->action,(int)$op->term,$d->id,$d->domain,$d->service_id,$d->server_id,
            $binding->fingerprint,$d->authority_generation,$d->provider_domain_id,$op->provider_order_id,
            $d->provider_expires_at?->format('Y-m-d'),$op->cost['actual']??null,$op->attempt['authorized_cost']??null]];
    $op->attempt=[...$op->attempt,'converted_completion'=>['data'=>$data,
        'mac'=>hash_hmac('sha256','domains-conversion-v1:'.json_encode($data,JSON_THROW_ON_ERROR),(string)config('app.key'))]];
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>$op->getAttributes()['attempt']]);
    return [$op->fresh(),$d,$binding];
}
