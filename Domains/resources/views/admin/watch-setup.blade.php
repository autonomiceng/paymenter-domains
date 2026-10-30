<details style="padding: 1rem">
    <summary style="cursor: pointer; font-weight: 600">Set up domain monitoring</summary>
    <ol style="list-style: decimal; padding-left: 1.5rem">
        <li style="margin: .75rem 0">Ask your site admin to connect monitoring on the server. Changing the resolver in Settings does not turn monitoring on.</li>
        <li style="margin: .75rem 0">Check that the <a style="text-decoration: underline" href="{{ \Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::getUrl() }}">Public suffix list</a> was downloaded in the last {{ \Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList::MAX_AGE_DAYS }} days.</li>
        <li style="margin: .75rem 0">In <a style="text-decoration: underline" href="{{ \Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::getUrl() }}">DNS templates</a>, add expected nameservers and records if needed.</li>
        <li style="margin: .75rem 0">In <a style="text-decoration: underline" href="{{ \Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource::getUrl() }}">Hosting products</a>, choose the domain field and template, save, then turn monitoring on. Saving leaves it off.</li>
        <li style="margin: .75rem 0">Check that an active hosting service shows up here and finishes a check. Checks run every five minutes. Monitoring reads public DNS and registry data. It doesn't buy or register domains.</li>
    </ol>
</details>
