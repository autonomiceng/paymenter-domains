<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
/** Synthetic fixture adapter for the current price columns. No runtime compatibility writes. */
function costEvidence(?object $row): ?string
{
    if($row===null||$row->cost_enabled===null){return null;}
    $utc=new DateTimeZone('UTC');
    return json_encode(['action'=>$row->action,'term'=>(int)$row->term,'enabled'=>(bool)$row->cost_enabled,
        'total'=>$row->wholesale===null?null:\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::money((string)$row->wholesale,4),
        'currency'=>$row->currency,'source_unit'=>$row->cost_source_unit,'source'=>$row->source,
        'observed_at'=>(new DateTimeImmutable((string)$row->observed_at,$utc))->format(DATE_ATOM),
        'fresh_until'=>(new DateTimeImmutable((string)$row->fresh_until,$utc))->format(DATE_ATOM)],JSON_THROW_ON_ERROR);
}
function costColumns(mixed $json): array
{
    if($json===null){return ['cost_enabled'=>null];}
    try{return \Paymenter\Extensions\Servers\Domains\Catalogue\CurrentCost::values(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::cost(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($json)));}
    catch(\Throwable){return ['cost_enabled'=>true,'cost_source_unit'=>'invalid'];}
}
