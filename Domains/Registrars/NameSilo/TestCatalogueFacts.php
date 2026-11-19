<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts, TermSupport};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
/** Independently dated OTE protocol evidence, not metadata inferred from account prices or old flags. */
final class TestCatalogueFacts
{
    /** Sale authority requires this TLD's complete, still-current evidence, not just its account header. */
    public static function current(AccountBinding $binding,string $tld,?Facts $cached,Clock $clock): bool
    {
        try {return $cached!==null&&$cached==self::read($binding,
            app(AccountBindings::class)->credentialRevision($binding),$tld,$clock);}
        catch(\Throwable){return false;}
    }
    private static function account(AccountBinding $binding,string $revision): array
    {
        $account=ActivationEvidence::testAccount($binding);
        app(AccountBindings::class)->requireCredentialRevision($binding,$revision);
        $accounts=config('domains.namesilo_test.accounts',[]);
        $row=is_array($accounts)?($accounts[$account->stable_identity]??null):null;
        return is_array($row)&&($row['fingerprint']??null)===$binding->fingerprint
            &&($row['credential_revision']??null)===$revision?$row:[];
    }
    public static function read(AccountBinding $binding,string $revision,string $tld,Clock $clock): Facts
    {
        try {
            $row=self::account($binding,$revision);
            return self::configuredFacts($row['facts'][$tld]??null,$tld,$clock);
        }catch(\Throwable){throw new \DomainException('Current TEST NameSilo protocol facts unavailable.');}
    }
    /** An account read failure cannot tell a preview which ending is missing evidence. */
    public static function hasEvidence(AccountBinding $binding,string $revision,string $tld,Clock $clock): ?bool
    {
        try { return self::checks($binding,$revision,$clock)($tld); }
        catch(\DomainException){ return null; }
    }
    /** Check the saved account binding once per turn. Configuration errors stay distinct from account read failures. */
    public static function checks(AccountBinding $binding,string $revision,Clock $clock): \Closure
    {
        $row=self::account($binding,$revision);
        return static function(string $tld)use($row,$clock):bool {
            try {self::configuredFacts($row['facts'][$tld]??null,$tld,$clock);return true;}
            catch(\DomainException|\InvalidArgumentException|\TypeError|\ValueError){return false;}
        };
    }
    private static function configuredFacts(mixed $e,string $tld,Clock $clock): Facts
    {
        if(!is_array($e)||!is_array($e['terms']??null)||!is_array($e['registry_fields']??null)
            ||count($e['terms'])!==3||array_diff(array_keys($e['terms']),['register','renew','transfer'])!==[]){throw new \DomainException;}
        foreach($e['terms'] as $terms){if(!is_array($terms)||!in_array($terms,[[],[1]],true)){throw new \DomainException;}}
        $dates=[];
        foreach(['observed_at','fresh_until'] as $key){
            $value=$e[$key]??null;$date=is_string($value)?\DateTimeImmutable::createFromFormat(DATE_ATOM,$value):false;
            if(!$date||$date->format(DATE_ATOM)!==$value){throw new \DomainException;}$dates[]=$date;
        }
        $facts=new Facts($e['terms'],$e['registry_fields'],$e['renewal_safe']??null,$e['requires_nameservers']??null,
            $e['auto_renew_only']??null,'namesilo_test_protocol',...$dates);
        if(!$facts->fresh($clock->now())){throw new \DomainException;}
        // Optional reviewed NS17 evidence rides with the same dated row; first-pass suffixes only.
        return TermSupport::attach($tld,$facts,$e);
    }
}
