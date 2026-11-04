<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Catalogue\Facts;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
/** Independently dated OTE protocol evidence, not metadata inferred from account prices or old flags. */
final class TestCatalogueFacts
{
    public static function current(AccountBinding $binding): bool
    {
        try {self::account($binding,app(AccountBindings::class)->credentialRevision($binding));return true;}
        catch(\Throwable){return false;}
    }
    private static function account(AccountBinding $binding,string $revision): array
    {
        $account=ActivationEvidence::testAccount($binding);
        app(AccountBindings::class)->requireCredentialRevision($binding,$revision);
        $accounts=config('domains.namesilo_test.accounts',[]);
        $row=is_array($accounts)?($accounts[$account->stable_identity]??null):null;
        if(!is_array($row)||($row['fingerprint']??null)!==$binding->fingerprint
            ||($row['credential_revision']??null)!==$revision){throw new \DomainException('TEST protocol evidence binding changed.');}
        return $row;
    }
    public static function read(AccountBinding $binding,string $revision,string $tld,Clock $clock): Facts
    {
        try {
            $row=self::account($binding,$revision);
            $e=$row['facts'][$tld]??null;
            if(!is_array($e)||!is_array($e['terms']??null)||!is_array($e['registry_fields']??null)
                ||array_keys($e['terms'])!==['register','renew','transfer']){throw new \DomainException;}
            foreach($e['terms'] as $terms){if(!is_array($terms)||!in_array($terms,[[],[1]],true)){throw new \DomainException;}}
            $dates=[];
            foreach(['observed_at','fresh_until'] as $key){
                $value=$e[$key]??null;$date=is_string($value)?\DateTimeImmutable::createFromFormat(DATE_ATOM,$value):false;
                if(!$date||$date->format(DATE_ATOM)!==$value){throw new \DomainException;}$dates[]=$date;
            }
            $facts=new Facts($e['terms'],$e['registry_fields'],$e['renewal_safe']??null,$e['requires_nameservers']??null,
                $e['auto_renew_only']??null,'namesilo_test_protocol',...$dates);
            if(!$facts->fresh($clock->now())){throw new \DomainException;}
            return $facts;
        }catch(\Throwable){throw new \DomainException('Current TEST NameSilo protocol facts unavailable.');}
    }
}
