<div class="domains-sell-choices">
    @if(count($accounts = $page->accounts()) > 1)
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="account" aria-label="Registrar account">
                <option value="{{ $page::ALL }}">All accounts</option>
                @foreach($accounts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    @endif
    @if($page->canSeeCosts())
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="years" aria-label="Years">
                @foreach(range(1,10) as $year)<option value="{{ $year }}">{{ \Paymenter\Extensions\Servers\Domains\Presentation\TermChoice::years($year) }}</option>@endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    @endif
</div>
