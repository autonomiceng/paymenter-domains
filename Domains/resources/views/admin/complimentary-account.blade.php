<x-filament::section heading="Domain funding">
    <form method="post" action="{{ route('domains.staff.complimentary-account', $customer->id) }}" class="domains-form-stack">
        @csrf
        <label class="domains-form-switch"><input type="checkbox" role="switch" name="enabled" value="1" @checked($setting['enabled'] ?? false)> Domains are complimentary</label>
        <p>Beco pays for this customer’s domain registrations, renewals and transfers. Hosting keeps its normal price.</p>
        <label>Reason <input class="fi-input domains-form-input" type="text" name="reason" required maxlength="500"></label>
        <div><x-filament::button type="submit">Save domain funding</x-filament::button></div>
    </form>
</x-filament::section>
