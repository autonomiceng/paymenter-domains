{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $counts = \Paymenter\Extensions\Servers\Domains\Admin\Support\SalesSetupReview::summary($page->review['rows'] ?? []);
    $save = $counts['selling'] + $counts['checking'] + $counts['paused'] + $counts['ready'];
    $labels = [
        'selling' => 'Starts or stays for sale',
        'checking' => 'Checking first',
        'paused' => 'Saves, orders paused',
        'ready' => 'Ready to save',
        'unchanged' => 'Unchanged',
        'blocked' => 'Refused',
    ];
@endphp
<div class="fi-prose" role="status">
    @if ($page->review === [])
        <p>Review changes to see which TLDs can be saved.</p>
    @else
        <dl class="domains-card">
            @foreach ($labels as $kind => $label)
                @if ($counts[$kind] > 0)
                    <div><dt>{{ $label }}</dt><dd>{{ $counts[$kind] }}</dd></div>
                @endif
            @endforeach
        </dl>
        @if ($save === 0)<p>Nothing to save.</p>@endif
    @endif
</div>
<div class="fi-ac domains-actions">
    @if (isset($page->review['review_key']))
        <x-filament::button type="button" wire:click="save" wire:loading.attr="disabled">
            Save {{ $save }} {{ $save === 1 ? 'TLD' : 'TLDs' }}
        </x-filament::button>
    @endif
    {{ $page->cancelAction }}
</div>
