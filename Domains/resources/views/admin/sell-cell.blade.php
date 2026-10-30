@php
    $page = $getLivewire(); $ending = $getRecord()['ending']; $column = $getName(); $can = $page->canEdit(); $row = $page->chosen[$ending] ?? null;
    $box = static fn (bool $on, string $key) => 'wire:key="sell.'.e($ending).'.'.$key.'.'.($on ? 1 : 0).'"';
@endphp
@if($column === 'sell')
    <input type="checkbox" class="fi-checkbox-input" aria-label="Sell .{{ $ending }}" @checked($row !== null) @disabled(!$can || $page->isHidden($ending)) {!! $box($row !== null, 'sell') !!}
        wire:click="toggleEnding(@js($ending))" wire:loading.attr="disabled" wire:target="toggleEnding(@js($ending))" />
@elseif($column === 'transfers')
    @if($row !== null)
        <input type="checkbox" class="fi-checkbox-input" aria-label="Transfers for .{{ $ending }}" @checked($row['transfer']) @disabled(!$can) {!! $box($row['transfer'], 'transfer') !!}
            wire:click="toggleTransfer(@js($ending))" />
    @endif
@elseif($row !== null)
    <div style="display:flex;flex-wrap:wrap;gap:.25rem .875rem;align-items:center;font-size:.875rem">
        @foreach($page->yearsFor($ending) as $year)
            <label style="display:inline-flex;gap:.375rem;align-items:center;font-variant-numeric:tabular-nums">
                <input type="checkbox" class="fi-checkbox-input" @checked(in_array($year, $row['terms'], true)) @disabled(!$can) {!! $box(in_array($year, $row['terms'], true), 'year'.$year) !!}
                    wire:click="toggleYear(@js($ending), {{ $year }})" />{{ $year }}
            </label>
        @endforeach
        @if(isset($page->unknownYears[$ending]))
            <span style="opacity:.7">Couldn't read the years.</span>
        @endif
        @if($can && !isset($page->years[$ending]) && $page->readable())
            <x-filament::link tag="button" size="sm" :wire:click="'moreYears('.\Illuminate\Support\Js::from($ending).')'">{{ isset($page->unknownYears[$ending]) ? 'Try again' : 'More years' }}</x-filament::link>
        @endif
    </div>
@endif
