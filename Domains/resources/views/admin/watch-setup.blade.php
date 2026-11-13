<details style="padding: 1rem">
    <summary style="cursor: pointer; font-weight: 600">Set up domain monitoring</summary>
    <ol style="list-style: decimal; padding-left: 1.5rem">
        <li style="margin: .75rem 0">Ask your site admin to connect monitoring on the server. Changing the resolver in Settings does not turn monitoring on.</li>
        @php
            $pslPage = \App\Models\Server::where('extension', 'Domains')->exists() ? \Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class : \Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::class;
            $pslLabel = $pslPage === \Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class ? 'Registrars → System checks' : 'Public suffix list';
        @endphp
        <li style="margin: .75rem 0">The public suffix list downloads automatically. @if (\Illuminate\Support\Facades\Route::has($pslPage::getRouteName()) && $pslPage::canAccess()) Failed updates appear under <a style="text-decoration: underline" href="{{ $pslPage::getUrl() }}">{{ $pslLabel }}</a>. @else A staff member with domain monitoring access can check failed updates under {{ $pslLabel }}. @endif</li>
        <li style="margin: .75rem 0">In <a style="text-decoration: underline" href="{{ \Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::getUrl() }}">DNS templates</a>, add expected nameservers and records if you want DNS checks. Expiry checks run without one.</li>
        <li style="margin: .75rem 0">Hosting products with a domain field are monitored by themselves. Once monitoring is connected, the Monitoring settings tab lists each one with an on/off switch.</li>
        <li style="margin: .75rem 0">Check that an active hosting service shows up here and finishes a check. Checks run every five minutes. Monitoring reads public DNS and registry data. It doesn't buy or register domains.</li>
    </ol>
</details>
