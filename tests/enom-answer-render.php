<?php
// Static render of the Enom answer form; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/staff-operation-fixture.php';
require_once __DIR__.'/Support/render-artifacts.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Blade, Cache};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ReadAdapter;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationReconciliation, RegistrarRegistry, StaffSettingsReview as Review};
$out = renderOutput($argv[1] ?? null);
$history = new class implements Transport {
    public ?Transport $domain = null; public ?DateTimeImmutable $sent = null;
    public function send($env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        if (!in_array($command, [ReadCommand::Orders, ReadCommand::Order], true)) { return $this->domain->send($env, $command, $parameters, $uid, $token, $timeoutMs); }
        $at = fn (int $i) => $this->sent->modify('-'.(10 * ($i + 1)).' minutes')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        if ($command === ReadCommand::Orders) { return enomHistoryEnvelope(enomHistoryPage(array_map(fn ($i) => ['id' => (string)(8001 + $i), 'date' => $at($i)], range(0, 2)), $parameters['Start'], 3)); }
        $i = (int)$parameters['OrderID'] - 8001;
        return enomHistoryDetail($parameters['OrderID'], 'other-'.$i.'-placeholder.com', $at($i), '2028-10-18', '8.95', 1, $at($i));
    }
};
$historyRegistry = new RegistrarRegistry;
$historyRegistry->register('enom', ['token'], $verify, fn ($b, $c) => new Driver(new ReadAdapter($b, 'account-enom', $c['token'], $history), ['com']));
$scheduled = new OperationReconciliation($claims, new AccountBindings($historyRegistry), $clock);
app()->instance(AccountBindings::class, $bindings);
$staff->role->update(['permissions' => [...$all, 'admin.domains.manage-policy', Review::permission('enom_answer')]]);
$panel = Filament::getCurrentPanel(); $css = file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$admin = getenv('ADMIN_THEME_CSS'); $css .= is_string($admin) && is_file($admin) ? file_get_contents($admin) : '';
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render = static function ($test, string $name) use ($out, $css): void {
    // The panel sidebar reads the current request; give it the page's own GET request.
    app()->instance('request', \Illuminate\Http\Request::create('/admin/domains/operations/'.$test->instance()->getRecord()->id, 'GET'));
    $html = Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
        ['page' => $test->instance(), 'body' => $test->html().($test->effects['partials']['action-modals.0'] ?? '')]);
    $html = preg_replace('~<script\b[^>]*>.*?</script>~si', '', $html); $html = preg_replace('~<link\b[^>]*>~si', '', $html);
    $html = str_replace('x-cloak="-lg"', '', $html); $html = str_replace('class="fi-main-ctn"', 'style="display:flex;opacity:1" class="fi-main-ctn"', $html);
    $html = str_replace('</head>', '<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>', $html);
    expect(!str_contains($html, 'account-enom'), 'No synthetic credential'); writeRenderArtifact($out.'/'.$name.'.html', $html);
};
foreach (['present' => ''] as $name => $domainFault) {
    [$op, $binding] = $prepare(placeholderComFixtureName('answer-'.$name.'-placeholder.com'), 'null');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 60, 2592000, 'enom_registration_v1'));
    $history->domain = $wire; $history->sent = new DateTimeImmutable($op->attempt['dispatch_attempted_at']); $observation->fault = $domainFault;
    $clock->instant = $history->sent->modify('+25 hours'); Cache::flush(); $scheduled->run($op->id);
    $page = Livewire::actingAs($staff)->test(ViewOperation::class, ['record' => $op->id])->mountAction('enom_answer')->set('mountedActions.0.data.outcome', 'not_processed');
    $render($page, 'enom-answer-'.$name);
}
echo $out."\n"; $nativeSuccessful = true;
