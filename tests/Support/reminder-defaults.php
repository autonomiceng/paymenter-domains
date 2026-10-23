<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

// Exact defaults before the renewal-off wording changed.
function previousReminderDefaults(): array
{
$button=static fn(string $url,string $label):string=>'<div class="action"><a class="button button-blue" href="{{ $'.$url.' }}">'.$label.'</a></div>';
return [
                'in_app_body'=>'@if($invoice)@if($overdue)Your renewal invoice is overdue. Contact support to check renewal options.@else Pay the renewal invoice by {{ $due }}.@endif@elseif($invoice_on)Your renewal invoice is scheduled for {{ $invoice_on }}.@elseif($complimentary)Complimentary pricing. Contact support to confirm its renewal.@else Renewal is off. Contact support if you want to keep this domain.@endif',
                'body'=>"# {{ \$domain }} expires on {{ \$expires }}\n\n@if(\$invoice)\n@if(\$overdue)\nYour renewal invoice for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}) was due on **{{ \$due }}**. Contact support to check whether this domain can still be renewed.\n\n".$button('invoice_url','View invoice')."\n@else\nYour renewal invoice is ready for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}). Pay it by **{{ \$due }}** and we'll renew the domain for you.\n\n"
                    .$button('invoice_url','Pay invoice')."\n@endif\n@elseif(\$invoice_on)\nYour renewal invoice is scheduled for **{{ \$invoice_on }}**. Pay it by the due date and we'll renew the domain for you.\n@elseif(\$complimentary)\nThis domain has complimentary pricing. Contact support to confirm its renewal.\n\n".$button('domain_url','View domain')."\n@else\nRenewal is turned off, so the domain will expire unless you renew it. Websites and email that use it will stop working. Contact support if you want to keep this domain.\n\n".$button('domain_url','View domain')."\n@endif"];
}
