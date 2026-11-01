<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
/** How long an unpaid new domain order keeps its name. A bad saved value falls back to the default. */
final class UnpaidHoldSetting
{
    public const KEY='domains_unpaid_hold_minutes';
    public const DEFAULT=60;
    public const MIN=5;
    public const MAX=10080;
    public static function minutes(int $serverId): int
    {
        $value=Server::find($serverId)?->settings()->where('key',self::KEY)->value('value');
        return filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>self::MIN,'max_range'=>self::MAX]])?:self::DEFAULT;
    }
    public static function save(StaffSettingsReview $r,#[\SensitiveParameter] array $data): void
    {
        $r->local(function()use($r,$data):void {
            if(($data['confirmed']??false)!==true){throw new \DomainException('Explicit unpaid hold confirmation required.');}
            $minutes=filter_var($data['minutes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>self::MIN,'max_range'=>self::MAX]]);
            if($minutes===false){throw new \DomainException('Enter between '.self::MIN.' and '.self::MAX.' minutes.');}
            $s=Server::whereKey($r->serverId)->lockForUpdate()->firstOrFail();
            if($minutes===self::minutes($r->serverId)){throw new \DomainException('Unpaid hold unchanged.');}
            $row=$s->settings()->where('key',self::KEY)->lockForUpdate()->first()??$s->settings()->make(['key'=>self::KEY]);
            $row->value=(string)$minutes;$row->type='integer';$row->encrypted=false;$row->save();
        });
    }
}
