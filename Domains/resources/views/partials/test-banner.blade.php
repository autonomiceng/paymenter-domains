@if (!empty($test))
    <div class="domains-testbar" role="note"><x-ri-flask-line class="domains-i" aria-hidden="true" /><span><strong>Test mode:</strong> @if ($test === 'some')Some endings use our test system, so those names aren't registered for real.@else Orders and changes use our test system. Nothing is registered for real.@endif</span></div>
@endif
