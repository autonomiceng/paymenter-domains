<?php
// Development-only source pin admission. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
final class SourcePins
{
    public static function schema(array $files): array
    {
        // Include complete inventories, so added/removed model and migration files also refuse.
        $unread=['Admin','Api','Http','Policies','resources','routes'];
        $schema=array_filter($files,fn($p)=>$p!=='LICENSE'&&!in_array(explode('/',$p)[0],$unread,true),ARRAY_FILTER_USE_KEY);
        ksort($schema);if(!$schema){throw new \DomainException('Source schema inventory required.');}return $schema;
    }
    public static function admit(array $candidate,array $pins): void
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$candidate['revision']??'')){throw new \DomainException('Full source revision required.');}
        if(!in_array($candidate['revision'],\Paymenter\Extensions\Servers\Domains\Services\ConvertedHoldingProof::SOURCE_REVISIONS,true)){
            throw new \DomainException('Declare matching runtime proof revision support before pinning.');
        }
        $schema=self::schema($candidate['files']);
        foreach($pins as$pin){if(self::schema($pin['files'])===$schema){return;}}
        if((SourceSchema::PIN_SCHEMAS[$candidate['revision']]??null)!==Inputs::digest($schema)){
            throw new \DomainException('Changed source schema requires explicit converter support.');
        }
    }
}
