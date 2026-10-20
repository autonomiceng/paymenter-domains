@if (!empty($test))
    <div class="domains-testbar" role="note"><x-ri-flask-line class="domains-i" aria-hidden="true" /><span><strong>Test mode:</strong> @if ($test === 'some')Items marked Test use a test system and aren't registered for real.@else This page uses a test system. Nothing here is registered for real.@endif</span></div>
@endif
