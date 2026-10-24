# Domain help

How each setting on a domain page works. Changes show in History once the registrar confirms them.

## Nameservers

Nameservers decide where your website and email are hosted. Your DNS provider or hosting company tells you which nameservers to use.

- Enter 2 to 12 hostnames, one per line, such as ns1.example.com.
- **Use the default nameservers** fills in the default nameservers. Choose this if you want to manage DNS records on this page.
- Before saving, we show the current and new nameservers so you can check them.
- Changes can take up to 48 hours to reach everyone.

If the new nameservers aren't set up for your domain, your website and email can stop working until you fix them or switch back.

## Glue records

Glue records register your own nameservers, like ns1.example.com, with their IP addresses. You need them only when your nameservers are named under this domain.

- Add one record for each nameserver hostname and IP address.
- To change an IP address, update the record. To stop using a nameserver, delete it.
- Only glue records added or changed here are listed. Others may already exist at the registrar.

Deleting a glue record that domains still use can stop those domains from resolving.

## DNS records

DNS records point your domain to your website, email and other services. They're used only while the domain uses the default nameservers. If you use other nameservers, manage DNS records with that provider instead.

- Use @ for the domain itself, and a name like www for a subdomain.
- Alias (CNAME) and mail (MX) targets end with a dot, such as mail.example.com.
- For SRV records, enter the service without the leading underscore, such as sip. The target ends with a dot.

Deleting or changing records that your website or email use can stop them working. Before saving a change that deletes records, we list them so you can check.

## DNSSEC

DNSSEC protects visitors from forged DNS answers. Your DNS provider signs your DNS records and gives you a DS record to add here.

- Add the DS record exactly as your DNS provider shows it: key tag, algorithm, digest type and digest.
- If your current DS records can't be shown, you can still add one.

A DS record that doesn't match your DNS provider's keys can make your domain unreachable. Remove old DS records only after your DNS provider stops using those keys, or when you turn DNSSEC off there.

## Transfer lock

The transfer lock stops anyone from moving this domain to another registrar. Keep it on unless you are moving the domain.

When the lock is off, anyone with the auth code can start a transfer. Turn it back on if you decide not to move the domain.

## Auth code

Your new registrar asks for the auth code (also called EPP code or transfer code) when you move the domain to them. We ask the registrar to email the auth code to the owner, or to the admin contact for some registrars. The page shows who gets it.

Treat the auth code like a password. Anyone with it can move this domain once the transfer lock is off.

## WHOIS privacy

WHOIS privacy hides the owner's name, address, phone and email from public WHOIS lookups. The registrar shows its privacy service details instead.

- Some domains include privacy at no charge. Otherwise it renews separately from the domain, one year at a time, and we send an invoice for it, plus tax where applicable.
- Some registries don't allow privacy, or require the owner's details to stay public.

## Contacts

Each domain has an owner (registrant) and admin, tech and billing contacts. You can change one contact or all of them at once.

- Use details you can be reached at. Registries can suspend domains with false contact details.
- Changing the owner's name, organization or email may need email verification, and can block transfers to another registrar for 60 days.
- Some registrars apply contact changes in two steps. Prepare the new details first, then apply them once History shows them as confirmed.

## Auto-renew

Auto-renew sends a renewal invoice before your domain expires, so it stays registered. If you turn it off, the domain expires on the date shown unless you renew it.

Registrar auto-renew is a separate setting at the registrar. Turning it off means the registrar stops renewing the domain on its own. Your domain then renews only as set by Auto-renew, which doesn't change.
