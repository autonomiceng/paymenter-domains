<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders a few staff entries outside a resource page.
declare(strict_types=1);
final class FactsProbe extends Livewire\Component implements Filament\Actions\Contracts\HasActions, Filament\Schemas\Contracts\HasSchemas
{
    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Schemas\Concerns\InteractsWithSchemas;
    public static array $components = [];
    public function facts(Filament\Schemas\Schema $schema): Filament\Schemas\Schema { return $schema->components(self::$components); }
    public function render(): string { return '<div>{{ $this->facts }}</div>'; }
}
/** @return array{html:string,entries:array<string,Filament\Infolists\Components\TextEntry>} */
function renderFacts(array $components): array
{
    FactsProbe::$components = $components;
    $test = Livewire\Livewire::test(FactsProbe::class);
    $entries = [];
    foreach ($test->instance()->getSchema('facts')->getComponents() as $entry) { $entries[$entry->getName()] = $entry; }
    return ['html' => $test->html(), 'entries' => $entries];
}
