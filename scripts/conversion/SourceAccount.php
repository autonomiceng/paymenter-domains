<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use App\Models\{Server,User};
final class SourceAccount
{
    public static function read(Server $s): array
    {
        $settings=$s->settings;$v=$settings->pluck('value','key');$uid=$v->get('uid');$token=$v->get('token');$env=$v->get('environment','test');
        if(!is_string($uid)||trim($uid)!==$uid||$uid===''||!is_string($token)||$token===''||!$settings->firstWhere('key','uid')?->encrypted||!$settings->firstWhere('key','token')?->encrypted||!in_array($env,['test','live'],true)){throw new \DomainException('Encrypted bound source credentials required.');}
        $old=hash('sha256',$env.':'.strtolower($uid));if($v->get('enom_account_binding')!==$old){throw new \DomainException('Source account binding mismatch.');}
        if($env==='live'&&$v->get('enom_live_url')!==rtrim((string)config('app.url'),'/')){throw new \DomainException('Source live-site binding mismatch.');}
        return ['uid'=>$uid,'token'=>$token,'environment'=>$env,'old'=>$old];
    }
    public static function actor(int $id,string $reason): void
    {
        $actor=User::find($id);
        if(!$actor||!$actor->role||!$actor->hasPermission('admin.enom.view')||!$actor->hasPermission('admin.enom.manage-policy')||!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)){
            throw new \DomainException('Staff source view/policy permissions and bounded reason required.');
        }
    }
}
