{{-- A "?" button next to a title. Opens on hover, focus, click or tap; Esc closes it. Without JS it opens on hover and focus. A class, not [hidden], hides the text: the theme forces [hidden] off. --}}
@php($anchor = \Paymenter\Extensions\Servers\Domains\Services\OwnerHelp::SECTIONS[$id])
<span class="domains-help" data-help>
<button type="button" class="domains-help-btn" aria-expanded="false" aria-controls="help-{{ $id }}" aria-label="About {{ $label }}">?</button>
<span id="help-{{ $id }}" class="domains-help-pop">{{ \Paymenter\Extensions\Servers\Domains\Services\OwnerHelp::SUMMARIES[$anchor] }} <a class="domains-a" href="{{ route('domains.help') }}#{{ $anchor }}">Learn more<span class="domains-sr"> about {{ $label }}</span></a></span>
</span>
