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

function priorWatchDefaults(): array
{
$prior=array (
  'domains_watch_expiring' =>
  array (
    'name' =>
    array (
      0 => 'Domain checks: expiry approaching',
    ),
    'edit_preference_message' =>
    array (
      0 => 'Public domain check notices',
    ),
    'subject' =>
    array (
      0 => 'Check renewal for {{ $domain }} before {{ $date }}',
    ),
    'in_app_title' =>
    array (
      0 => 'Check renewal for {{ $domain }} before {{ $date }}',
    ),
    'in_app_body' =>
    array (
      0 => 'The public registry lists {{ $date }} as the expiry date for {{ $domain }}. Public registry dates may not show recent changes. Check renewal with your registration provider.',
    ),
    'body' =>
    array (
      0 => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">Check renewal for {{ $domain }} before {{ $date }}</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">The public registry lists {{ $date }} as the expiry date for {{ $domain }}. Public registry dates may not show recent changes. Check renewal with your registration provider.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You receive this because public domain check notices are enabled in your account. This notice is not a bill.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain checks</a></td></tr><tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">To pause notices about this domain, or turn them off for all domains, use the link above.</td></tr></table>',
    ),
  ),
  'domains_watch_expired' =>
  array (
    'name' =>
    array (
      0 => 'Domain checks: registry expiry reached',
    ),
    'edit_preference_message' =>
    array (
      0 => 'Public domain check notices',
    ),
    'subject' =>
    array (
      0 => 'Registry expiry date reached for {{ $domain }}',
    ),
    'in_app_title' =>
    array (
      0 => 'Registry expiry date reached for {{ $domain }}',
    ),
    'in_app_body' =>
    array (
      0 => 'The public registry expiry date for {{ $domain }} was reached on {{ $date }}. Contact your registration provider to check its status. Whether it can still be renewed depends on your registration provider and the registry.',
    ),
    'body' =>
    array (
      0 => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">Registry expiry date reached for {{ $domain }}</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">The public registry expiry date for {{ $domain }} was reached on {{ $date }}. Contact your registration provider to check its status. Whether it can still be renewed depends on your registration provider and the registry.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You receive this because public domain check notices are enabled in your account. This notice is not a bill.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain checks</a></td></tr><tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">To pause notices about this domain, or turn them off for all domains, use the link above.</td></tr></table>',
    ),
  ),
  'domains_watch_ns_changed' =>
  array (
    'name' =>
    array (
      0 => 'Domain checks: nameservers changed',
    ),
    'edit_preference_message' =>
    array (
      0 => 'Public domain check notices',
    ),
    'subject' =>
    array (
      0 => 'Nameservers changed for {{ $domain }}',
    ),
    'in_app_title' =>
    array (
      0 => 'Nameservers changed for {{ $domain }}',
    ),
    'in_app_body' =>
    array (
      0 => 'As of {{ $date }}, {{ $domain }} no longer uses the nameservers provided by {{ $company }}. If you did not expect this, contact support.',
    ),
    'body' =>
    array (
      0 => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">Nameservers changed for {{ $domain }}</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">As of {{ $date }}, {{ $domain }} no longer uses the nameservers provided by {{ $company }}. If you did not expect this, contact support.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You receive this because public domain check notices are enabled in your account. This notice is not a bill.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain checks</a></td></tr><tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">To pause notices about this domain, or turn them off for all domains, use the link above.</td></tr></table>',
    ),
  ),
  'domains_watch_ns_restored' =>
  array (
    'name' =>
    array (
      0 => 'Domain checks: nameserver change resolved',
    ),
    'edit_preference_message' =>
    array (
      0 => 'Public domain check notices',
    ),
    'subject' =>
    array (
      0 => 'Nameserver change resolved for {{ $domain }}',
    ),
    'in_app_title' =>
    array (
      0 => 'Nameserver change resolved for {{ $domain }}',
    ),
    'in_app_body' =>
    array (
      0 => 'As of {{ $date }}, {{ $domain }} uses the nameservers provided by {{ $company }} again.',
    ),
    'body' =>
    array (
      0 => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">Nameserver change resolved for {{ $domain }}</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">As of {{ $date }}, {{ $domain }} uses the nameservers provided by {{ $company }} again.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You receive this because public domain check notices are enabled in your account. This notice is not a bill.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain checks</a></td></tr><tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">To pause notices about this domain, or turn them off for all domains, use the link above.</td></tr></table>',
    ),
  ),
);

// Exact nameserver defaults from the following release.
$prior['domains_watch_ns_changed']['name'][]='Monitoring alert: nameservers changed';
$prior['domains_watch_ns_changed']['subject'][]='Nameservers changed for {{ $domain }}';
$prior['domains_watch_ns_changed']['in_app_title'][]='Nameservers changed for {{ $domain }}';
$prior['domains_watch_ns_changed']['in_app_body'][]='As of {{ $date }}, {{ $domain }} no longer uses {{ $company }} nameservers. If you didn’t expect this, contact support.';
$prior['domains_watch_ns_changed']['body'][]='<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">Nameservers changed for {{ $domain }}</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">As of {{ $date }}, {{ $domain }} no longer uses {{ $company }} nameservers. If you didn’t expect this, contact support.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain</a></td></tr><tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You get this because monitoring alerts are on. Turn them off on the domain page.</td></tr></table>';
$prior['domains_watch_ns_restored']['name'][]=hex2bin('4d6f6e69746f72696e6720616c6572743a206e616d6573657276657273206261636b20746f204265636f');
$prior['domains_watch_ns_restored']['subject'][]='{{ $domain }} uses {{ $company }} nameservers again';
$prior['domains_watch_ns_restored']['in_app_title'][]='{{ $domain }} uses {{ $company }} nameservers again';
$prior['domains_watch_ns_restored']['in_app_body'][]='As of {{ $date }}, {{ $domain }} uses {{ $company }} nameservers again.';
$prior['domains_watch_ns_restored']['body'][]='<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">{{ $domain }} uses {{ $company }} nameservers again</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">As of {{ $date }}, {{ $domain }} uses {{ $company }} nameservers again.</td></tr><tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain</a></td></tr><tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You get this because monitoring alerts are on. Turn them off on the domain page.</td></tr></table>';
return $prior;
}
