<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Role,Server,User};
use Illuminate\Support\Facades\{Auth,Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{DefaultContact,Driver};
/** Deliberate contact continuity change; grants no operation release or provider write authority. */
final readonly class NameSiloAccountReset
{
    public const LABEL='Accept current NameSilo account';
    public function __construct(private AccountBindings $bindings,private Clock $clock,private ReadLimiter $limiter) {}
    private static function actor(bool $lock=false): User
    {
        $id=Auth::id();$q=User::whereKey($id);if($lock){$q->lockForUpdate();}$u=$q->first();
        if($u){$r=Role::whereKey($u->role_id);if($lock){$r->lockForUpdate();}$u->setRelation('role',$r->first());}
        abort_unless($u&&$u->hasPermission(Staff::VIEW)&&$u->hasPermission('admin.domains.manage'),403);
        return $u;
    }
    private static function server(int $id,bool $lock=false): Server
    {
        $q=Server::whereKey($id)->where('extension','Domains')->where('enabled',true);if($lock){$q->lockForUpdate();}
        $s=$q->first();if(!$s){throw new \DomainException('Enable this Domains server before reviewing the account.');}return $s;
    }
    private static function pinned(Server $server,bool $lock=false): array
    {
        $q=$server->settings()->where('key',NameSiloAccountGuard::PIN);if($lock){$q->lockForUpdate();}$s=$q->first();
        if(!$s||!$s->encrypted){throw new \DomainException('No pinned NameSilo account to replace.');}
        try {
            $v=json_decode($s->value,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($v)||!is_string($v['binding']??null)||!preg_match('/^[a-f0-9]{64}$/D',$v['binding'])
                ||!is_string($v['contact_id']??null)||!preg_match('/^[1-9][0-9]{0,19}$/D',$v['contact_id'])
                ||DefaultContact::email($v['email']??null)!==($v['email']??null)){throw new \DomainException;}
            return $v;
        }catch(\Throwable){throw new \DomainException('The pinned NameSilo account cannot be read.');}
    }
    private static function expected(Server $server,bool $lock=false): string
    {
        $q=$server->settings()->where('key',NameSiloAccountGuard::EXPECTED);if($lock){$q->lockForUpdate();}
        try{return DefaultContact::email($q->first()?->value);}catch(\Throwable){throw new \DomainException('Set the Expected default contact email before accepting this account.');}
    }
    private function live(int $id): array
    {
        try {
            $a=Account::where('server_id',$id)->where('registrar_key','namesilo')->sole();
            $binding=CatalogueAcceptances::binding($a);
            $reader=$this->bindings->reader($id,true);$binding->requireMatch($reader->binding());
            if(!$reader instanceof Driver){throw new \DomainException;}
            $ctx=new RequestContext($binding,RequestOrigin::Interactive,$this->clock->now()->modify('+25 seconds'),new RequestBudget(1),$this->clock,$this->limiter);
            return ['binding'=>$binding->fingerprint,...$reader->defaultContact($ctx)];
        }catch(\Throwable){throw new \DomainException('Could not read the current NameSilo default contact. Try again.');}
    }
    public function preview(int $id): string
    {
        OperationClaims::outside();$u=self::actor();$s=self::server($id);$stamp=StaffSettingsReview::stamp($id,null);
        $pinned=self::pinned($s);$expected=self::expected($s);$live=$this->live($id);self::actor();
        if(!hash_equals($stamp,StaffSettingsReview::stamp($id,null))){throw new \DomainException('Account settings changed. Open this review again.');}
        return Crypt::encryptString(json_encode(['actor'=>(int)$u->id,'server'=>$id,'stamp'=>$stamp,'pinned'=>$pinned,'live'=>$live,
            'expected'=>$expected,'key'=>bin2hex(random_bytes(16)),'until'=>$this->clock->now()->getTimestamp()+300],JSON_THROW_ON_ERROR));
    }
    public function details(int $id,string $ticket): array
    {
        $u=self::actor();
        try{$v=json_decode(Crypt::decryptString($ticket),true,12,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Open the account review again.');}
        if(($v['actor']??null)!==(int)$u->id||($v['server']??null)!==$id||!is_int($v['until']??null)||$v['until']<=$this->clock->now()->getTimestamp()){
            throw new \DomainException('Open the account review again.');}
        return $v;
    }
    public function accept(int $id,string $ticket,string $reason): void
    {
        OperationClaims::outside();self::actor();StaffReason::require($reason);$review=$this->details($id,$ticket);
        if($review['live']['email']!==$review['expected']){throw new \DomainException('Current email does not match Expected default contact email. Nothing was saved.');}
        // Re-read on submit. The operator must see the same candidate that is saved.
        if($this->live($id)!==$review['live']){throw new \DomainException('The current contact changed. Open this review again.');}
        DB::transaction(function()use($id,$ticket,$reason,$review):void {
            $u=self::actor(true);$s=self::server($id,true);
            $a=Account::where('server_id',$id)->where('registrar_key','namesilo')->lockForUpdate()->sole();
            $this->bindings->requireReadableLocked($s,$a);$binding=CatalogueAcceptances::binding($a);
            $this->details($id,$ticket);
            if($binding->fingerprint!==$review['live']['binding']||!hash_equals($review['stamp'],StaffSettingsReview::stamp($id,null,true))
                ||self::pinned($s,true)!==$review['pinned']||self::expected($s,true)!==$review['live']['email']){
                throw new \DomainException('Account settings changed. Open this review again.');}
            $keys=DB::table(StaffSettingsReview::KEYS)->where('server_id',$id)->where('review_key',$review['key']);
            if((clone $keys)->lockForUpdate()->exists()){throw new \DomainException('This account review was already used.');}
            DB::table(StaffSettingsReview::KEYS)->insert(['server_id'=>$id,'review_key'=>$review['key'],'expires_at'=>$review['until']]);
            $pin=$s->settings()->where('key',NameSiloAccountGuard::PIN)->lockForUpdate()->sole();
            $pin->encrypted=true;$pin->value=json_encode($review['live'],JSON_THROW_ON_ERROR);$pin->save();
            StaffSettingsReview::append($s,['key'=>$review['key'],'actor_id'=>(int)$u->id,'at'=>$this->clock->now()->format(DATE_ATOM),
                'action'=>self::LABEL,'reason'=>$reason,'phase'=>'completed','input'=>[],'tld_id'=>null,
                'before'=>['fingerprint'=>$review['pinned']],'after'=>['fingerprint'=>$review['live']]]);
        });
    }
}
