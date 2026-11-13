<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
/** Old staff addresses. Each staff page's address now names the page; a bookmark, a saved alert link or a script that
 * still uses an old address gets a permanent redirect to the same page, with the rest of the path and the query kept. */
final class MovedStaffPageController
{
    /** Old first segment under the panel path => new one. */
    public const MOVED = ['dns-routing-profiles' => 'dns-templates', 'domain-watch' => 'monitored-domains', 'domain-tlds' => 'tlds',
        'adoption-pending' => 'import-pending', 'registrar-setup' => 'registrars'];
    /** Pages inside a moved area that were renamed too, by their old path after the new first segment. */
    public const RENAMED = ['monitored-domains/dns-failures' => 'monitored-domains/affected-dns-checks', 'monitored-domains/dns-setup' => 'monitored-domains/dns-monitoring-setup',
        'monitored-domains/settings' => 'monitored-domains/monitoring-settings'];
    /** Single old pages outside a moved area, old path => new. A plain route answers before the panel's
     * /domains/{record}, so no page has to sit behind staff sign-in just to redirect. */
    public const PAGES = ['domains/adopt' => 'domains/import', 'tlds/price-list' => 'what-you-sell'];
    /** Route values are read by name, since a controller receives them by position and an empty rest is dropped. */
    public function __invoke(Request $request): RedirectResponse
    {
        $path = trim($request->route('to').'/'.trim((string) $request->route('rest'), '/'), '/');
        foreach (self::RENAMED as $old => $new) {
            if ($path === $old || str_starts_with($path, $old.'/')) { $path = $new.substr($path, strlen($old)); break; }
        }
        return self::to($request, $request->route('panel').'/'.$path);
    }
    public function page(Request $request): RedirectResponse
    { return self::to($request, $request->route('panel').'/'.$request->route('to')); }
    public function preview(Request $request): RedirectResponse
    { return self::to($request, $request->route('panel').'/domains/'.$request->route('record').'/import-preview'); }
    private static function to(Request $request, string $path): RedirectResponse
    { return redirect()->to(url($path).(($query = $request->getQueryString()) !== null ? '?'.$query : ''), 301); }
    /** Registers the old addresses under the panel path. */
    public static function routes(string $panel): void
    {
        $panel = trim($panel, '/');
        foreach (self::MOVED as $old => $new) {
            \Illuminate\Support\Facades\Route::get($panel.'/'.$old.'/{rest?}', self::class)->where('rest', '.*')->defaults('panel', $panel)->defaults('to', $new)->name('domains.moved.'.$old);
        }
        foreach (self::PAGES as $old => $new) {
            \Illuminate\Support\Facades\Route::get($panel.'/'.$old, [self::class, 'page'])->defaults('panel', $panel)->defaults('to', $new)->name('domains.moved.'.str_replace('/', '.', $old));
        }
        // A domain's import preview: /domains/{record}/adoption is now /domains/{record}/import-preview.
        \Illuminate\Support\Facades\Route::get($panel.'/domains/{record}/adoption', [self::class, 'preview'])->defaults('panel', $panel)
            ->whereNumber('record')->name('domains.moved.adoption');
    }
}
