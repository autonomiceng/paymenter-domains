<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Staff\Requests;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
/** One staff request page's reads, made once: the request's registrar account, and its release facts and offer.
 * The request page opens the scope for one Livewire request and closes it when the response is built, and after each staff
 * action. Outside a scope every call reads now, so jobs and other pages are unchanged. An entry is keyed by the signed-in staff
 * member and by the request and domain rows as loaded, so a changed or refreshed row reads again. Never kept across requests. */
final class RequestReads
{
    /** @var ?array<string,mixed> */
    private static ?array $reads=null;
    public static function open(): void { self::$reads=[]; }
    public static function close(): void { self::$reads=null; }
    /** Account name, registrar and mode for the Registrar account fact, and the account row the notice reads its switches from.
     * @return array{value:?string,sub:?string,account:?Account} */
    public static function account(Operation $op): array
    {
        return self::once('account',$op,static function() use ($op): array {
            $account=Account::where('server_id',$op->server_id)->where('registrar_key',$op->registrar_key)->where('account_fingerprint',$op->account_fingerprint)->first();
            $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??null;
            $name=\App\Models\Server::whereKey($op->server_id)->value('name');
            if (!$name) { return ['value'=>null,'sub'=>'Registrar account not found. Check Registrars.','account'=>null]; }
            return ['value'=>$name,'sub'=>implode(', ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account?->environment]??null])) ?: null,'account'=>$account];
        });
    }
    /** The advisory holding facts a release check reads (HeldRelease::readFacts). */
    public static function releaseFacts(Operation $op, \Closure $read): array { return self::once('release',$op,$read); }
    /** Whether the signed-in staff member is offered Release hold (HeldRelease::offered). */
    public static function offered(Operation $op, \Closure $decide): bool { return self::once('offer',$op,$decide); }
    private static function once(string $kind, Operation $op, \Closure $read): mixed
    {
        if (self::$reads===null) { return $read(); }
        $rows=serialize([$op->getAttributes(),$op->domain?->getAttributes()]);
        $key=$kind."\0".Auth::id()."\0".$op->getKey()."\0".hash('sha256',$rows);
        // A failed read is not kept, so the next caller reads again and sees the same failure.
        return array_key_exists($key,self::$reads) ? self::$reads[$key] : self::$reads[$key]=$read();
    }
}
