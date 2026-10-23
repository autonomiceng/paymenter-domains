<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Str;
final class OwnerHelp
{
    // Stable help page anchors and the ## heading each one belongs to.
    public const ANCHORS=['nameservers'=>'Nameservers','glue-records'=>'Glue records','dns-records'=>'DNS records','dnssec'=>'DNSSEC','transfer-lock'=>'Transfer lock','transfer-code'=>'Transfer code','privacy'=>'Domain privacy','contacts'=>'Contacts','auto-renew'=>'Auto-renew'];
    // Owner management sections and the anchor that explains each one.
    public const SECTIONS=['nameservers'=>'nameservers','glue'=>'glue-records','dns'=>'dns-records','srv'=>'dns-records','ds'=>'dnssec','lock'=>'transfer-lock','auth_code_email'=>'transfer-code','privacy'=>'privacy','contacts'=>'contacts','auto_renew_off'=>'auto-renew','auto-renew'=>'auto-renew'];
    // One short line per popover. The help page has the full text.
    public const SUMMARIES=[
        'nameservers'=>'Changes can take up to 48 hours to reach everyone.',
        'glue-records'=>'Needed only when your nameservers are named under this domain.',
        'dns-records'=>'If you use other nameservers, manage DNS records with that provider.',
        'dnssec'=>'Copy the DS record exactly as your DNS provider shows it.',
        'transfer-lock'=>'When it’s off, anyone with the auth code can move this domain.',
        'transfer-code'=>'Also called an EPP code. Treat it like a password.',
        'privacy'=>'Some registries require the owner’s details to stay public.',
        'contacts'=>'The owner holds the domain. The other contacts handle admin, tech and billing.',
        'auto-renew'=>'Sends a renewal invoice before the domain expires.',
    ];
    public static function path(): string
    {
        return dirname(__DIR__).'/resources/help/owner-help.md';
    }
    /** The help page body: raw HTML escaped, unsafe links dropped, a stable id on each ## heading. */
    public static function html(?string $markdown=null): string
    {
        $html=(string)Str::markdown($markdown??(string)file_get_contents(self::path()),['html_input'=>'escape','allow_unsafe_links'=>false]);
        $ids=array_flip(self::ANCHORS);
        $html=preg_replace('~<h1>.*?</h1>\s*~s','',$html,1);
        return preg_replace_callback('~<h2>(.*?)</h2>~s',fn(array $m)=>isset($ids[html_entity_decode($m[1],ENT_QUOTES)])?'<h2 id="'.$ids[html_entity_decode($m[1],ENT_QUOTES)].'" class="domains-h2">'.$m[1].'</h2>':$m[0],$html);
    }
}
