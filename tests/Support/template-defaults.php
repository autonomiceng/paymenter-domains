<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reminder-defaults.php';

// Exact defaults shipped before the glossary wording, per template and field, oldest first.
function priorReminderDefaults(): array
{
$prior=array (
  'domains_domain_expiring' =>
  array (
    'in_app_body' =>
    array (
      0 => '@if($invoice)@if($overdue)Your renewal invoice is overdue. Contact support to check renewal options.@else Pay the renewal invoice by {{ $due }}.@endif@elseif($invoice_on)Your renewal invoice is scheduled for {{ $invoice_on }}.@elseif($complimentary)Complimentary pricing. Contact support to confirm its renewal.@else Renewal is turned off. To keep this domain, contact support before it expires.@endif',
    ),
    'body' =>
    array (
      0 => '# {{ $domain }} expires on {{ $expires }}

@if($invoice)
@if($overdue)
Your renewal invoice for **{{ $years }} {{ $years === 1 ? \'year\' : \'years\' }}** ({{ $amount }}) was due on **{{ $due }}**. Contact support to check whether this domain can still be renewed.

<div class="action"><a class="button button-blue" href="{{ $invoice_url }}">View invoice</a></div>
@else
Your renewal invoice is ready for **{{ $years }} {{ $years === 1 ? \'year\' : \'years\' }}** ({{ $amount }}). Pay it by **{{ $due }}** and we\'ll renew the domain for you.

<div class="action"><a class="button button-blue" href="{{ $invoice_url }}">Pay invoice</a></div>
@endif
@elseif($invoice_on)
Your renewal invoice is scheduled for **{{ $invoice_on }}**. Pay it by the due date and we\'ll renew the domain for you.
@elseif($complimentary)
This domain has complimentary pricing. Contact support to confirm its renewal.

<div class="action"><a class="button button-blue" href="{{ $domain_url }}">View domain</a></div>
@else
Renewal is turned off. To keep this domain, contact support before **{{ $expires }}**. After it expires, websites and email that use it may stop working.

<div class="action"><a class="button button-blue" href="{{ $domain_url }}">View domain</a></div>
@endif',
    ),
  ),
  'domains_transfer_code_paid' =>
  array (
    'name' =>
    array (
      0 => 'Paid transfer needs a code',
    ),
    'subject' =>
    array (
      0 => 'Enter the transfer code for {{ $domain }}',
    ),
    'in_app_title' =>
    array (
      0 => 'Enter the transfer code for {{ $domain }}',
    ),
    'edit_preference_message' =>
    array (
      0 => 'Transfer code reminders',
    ),
    'body' =>
    array (
      0 => 'Thanks for your payment. To move {{ $domain }} to us, enter its transfer code (also called an auth code). Your current registrar gives you this code. We\'ll check the transfer once we have it.

Enter it by **{{ $deadline }}**.

<div class="action"><a class="button button-blue" href="{{ $domain_url }}">Enter transfer code</a></div>

If we don\'t have it by then, we\'ll cancel the transfer and add {{ $amount }} to your account credit.',
    ),
  ),
  'domains_transfer_code_reminder' =>
  array (
    'name' =>
    array (
      0 => 'Transfer code deadline reminder',
    ),
    'subject' =>
    array (
      0 => 'Reminder: transfer code for {{ $domain }}',
    ),
    'in_app_title' =>
    array (
      0 => 'Enter the transfer code for {{ $domain }}',
    ),
    'edit_preference_message' =>
    array (
      0 => 'Transfer code reminders',
    ),
    'body' =>
    array (
      0 => 'We still need the transfer code for {{ $domain }}. Enter it by **{{ $deadline }}**.

<div class="action"><a class="button button-blue" href="{{ $domain_url }}">Enter transfer code</a></div>

If we don\'t have it by then, we\'ll cancel the transfer and add {{ $amount }} to your account credit.',
    ),
  ),
);
foreach(previousReminderDefaults() as $field=>$value){array_unshift($prior['domains_domain_expiring'][$field],$value);}
return $prior;
}
