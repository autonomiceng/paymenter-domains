<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
/** Keeps existing callers working while they move to DomainDecisions (rows, status, sync) and DomainNotices (notice and head). */
final class DomainFacts
{
    public const SECTIONS=DomainDecisions::SECTIONS;
    public const LINKS=DomainDecisions::LINKS;
    public const ALERTS=DomainDecisions::ALERTS;
    public const NO_ACCOUNT=DomainDecisions::NO_ACCOUNT;
    public const UNREADABLE=DomainDecisions::UNREADABLE;
    public const UNREADABLE_RENEWAL=DomainDecisions::UNREADABLE_RENEWAL;
    public const ADD_CONTACTS=DomainDecisions::ADD_CONTACTS;
    public static function help(): array { return DomainDecisions::help(); }
    public static function registered(Domain $d): bool { return DomainDecisions::registered($d); }
    public static function ended(Domain $d): bool { return DomainDecisions::ended($d); }
    public static function for(Domain $d, array|false|null $notice=false): array { return DomainDecisions::for($d, $notice); }
    public static function alertCause(string $code, ?Domain $d=null): string { return DomainDecisions::alertCause($code, $d); }
    public static function status(Domain $d, ?array $inputs=null): array { return DomainDecisions::status($d, $inputs); }
    public static function listTone(array $status, ?string $sync, bool $noAccount=false): string { return DomainDecisions::listTone($status, $sync, $noAccount); }
    public static function statusLine(array $status): ?string { return DomainDecisions::statusLine($status); }
    public static function note(array $status, ?string $sync, bool $noAccount=false, ?Domain $d=null): array { return DomainDecisions::note($status, $sync, $noAccount, $d); }
    public static function whileHeld(array $facts, ?string $first): array { return DomainDecisions::whileHeld($facts, $first); }
    public static function besideHead(array $facts, ?string $said): array { return DomainDecisions::besideHead($facts, $said); }
    public static function recordHold(Domain $d): ?string { return DomainDecisions::recordHold($d); }
    public static function sync(Domain $d, ?Account $account=null, ?bool $stopped=null, bool $accountLoaded=false): ?string { return DomainDecisions::sync($d, $account, $stopped, $accountLoaded); }
    public static function unread(Domain $d, ?string $sync): string { return DomainDecisions::unread($d, $sync); }
    public static function test(Domain $d): bool { return DomainDecisions::test($d); }
    public static function syncNow(Domain $d): bool { return DomainDecisions::syncNow($d); }
    public static function withProgress(array $facts, array $progress, ?int $named=null): array { return DomainDecisions::withProgress($facts, $progress, $named); }
    public static function progress(array $progress, string $section, ?int $named=null): ?array { return DomainDecisions::progress($progress, $section, $named); }
    public static function differs(int $count, int $of): string { return DomainDecisions::differs($count, $of); }
    public static function requestUrl(array $status): ?string { return DomainNotices::requestUrl($status); }
    public static function stepHtml(array $status, bool $mark=true): string|HtmlString|null { return DomainNotices::stepHtml($status, $mark); }
    public static function holdHtml(int $request, bool $mark=true): HtmlString { return DomainNotices::holdHtml($request, $mark); }
    public static function linkRequest(string|HtmlString $words, int $request): string|HtmlString { return DomainNotices::linkRequest($words, $request); }
    public static function head(Domain $d, array $status, array $facts, ?int $held=null, array|false|null $notice=false): array { return DomainNotices::head($d, $status, $facts, $held, $notice); }
    public static function headRequest(Domain $d, array $status, ?int $held=null, array|false|null $notice=false): ?int { return DomainNotices::headRequest($d, $status, $held, $notice); }
    public static function requestLink(string $url, string $label): string { return DomainNotices::requestLink($url, $label); }
    public static function noteStep(string $note, array $status): string|HtmlString { return DomainNotices::noteStep($note, $status); }
    public static function notice(Domain $d): ?array { return DomainNotices::notice($d); }
}
