# Design note: one SMTP login for mail apps

Status: built 2026-11-11 (mailhub-mirror 0.8.0 `submit.py`, mailhub-roundcube 0.8.0), not yet released.
Proposed 2026-11-10; the review changes are folded in below.

## Goal

A mail app (Thunderbird, a phone's mail app) sets up MailHub with a single account: IMAP and SMTP
on the same host, with the same login. Every address the user connected in MailHub can then send
from that one account, each through its own provider.

Before this, the app needed the MailHub IMAP login plus one SMTP server per address, with that
provider's password. That was the hardest part of the phone and desktop setup, and each new client
repeated it.

## What it builds on

- **The webmail sends through each provider** (`identity_smtp`, settings written by
  `mailhub_accounts`), with the account's own `user` and password, the same as for IMAP.
- **The account files** (`accounts/<user>/<slug>.json`, 0600 in 0700 folders) hold `email`, `user`,
  `pass` and the IMAP server, and since 0.8.0 the SMTP server too (`smtp_host`, `smtp_port`,
  `smtp_security`: `ssl`, `tls` = STARTTLS, `none`).
- **The device logins** (`admin` + `MAILHUB_PASSWORD`, or a user's generated device password) are
  the passwd-file mailhub-mirror renders for Dovecot.
- **Outbound 465/587 to providers already works from the box**: the webmail uses it.

## Shape

`mailhub-submit`: `submit.py` in the mailhub-mirror image (Python, `aiosmtpd` listening, `smtplib`
to the provider), run as its own service so a crash or a restart doesn't touch the sync.

Postfix was rejected. It can route by sender (`sender_dependent_relayhost_maps` plus per-sender SASL),
but it is heavy to configure for this, and it queues. Dovecot's `submission` service relays to one
fixed host only.

## Listening side

- **465 implicit TLS** (RFC 8314) and **587 STARTTLS**, required before AUTH (for networks that block
  465). The IMAPS certificate (`tls/`) is used; on renewal Maison restarts the service.
- **AUTH PLAIN and LOGIN only, after TLS.**
- **SIZE** 25 MB (Gmail's limit).

## Authentication: the device login, never the master

Logins are checked **in the relay**, against a copy of the passwd-file (`state/.submit/passwd`,
written by the mirror next to Dovecot's). Dovecot is never asked. There were two reasons:

- Dovecot accepts its master login from private addresses (`allow_nets` in `seed/dovecot/auth.conf`),
  and the relay is on a private address. Not talking to Dovecot means a client can't reach the
  master login through this port, whatever Dovecot's settings become.
- Dovecot slows down failed logins per source IP. Through a relay that IP is the relay's, so one
  person guessing passwords would slow down sending for every user.

On top of that, a PLAIN authorization id other than the user itself is refused, and so is any name
that isn't a local user (`*`, `/`, `@`, capitals). Failed logins back off per client IP: after 5 in
10 minutes every login from that IP is refused for 1, then 2, 4… minutes, up to 15.

## Sender check: no open relay

- **The allowed senders** are the `email` of each of the user's account files.
- **Both addresses must be allowed.** `MAIL FROM` must be one of them, and the `From:` header must be
  exactly that single address. Otherwise `550 5.7.1`.
- **The provider is chosen from that address**, never from anything else the client sends.
- **Main addresses only.** Aliases (sending as `sales@` through the `contact@` account) come later,
  with an explicit list in the account file.
- **A daily cap**: 500 recipients per user per rolling 24 h (`MAILHUB_SUBMIT_DAILY_RCPT`). The
  device password now also lets someone send as every address the user has, so this limits the
  damage if it leaks.

## Provider side: synchronous, no queue

- **The provider is contacted from `MAIL FROM` onward.** At `MAIL FROM` the relay connects and logs
  in, so a bad app password shows before the upload. Each `RCPT` gets the provider's own answer, so
  a recipient the provider refuses is refused to the client for that recipient only. At the end of
  `DATA` the provider's reply is the client's: 2xx and 5xx kept; 4xx, a timeout or a connection
  error become 4xx.
- **Every reply names the account**: `[contact@…] <provider text>`.
- **Nothing is spooled**, so nothing is silently dropped and there is no queue to manage. A refusal
  shows up in the mail app at send time.
- **Timeouts:** connecting 20 s; the whole provider transaction at most 90 s, under Thunderbird's
  100 s.
- **Logs:** one line per message: user, account, recipients count, size, provider reply code.
  Never the subject, the body or a password.

## Credentials

- The SMTP password is the account's `pass`, as `identity_smtp` uses today. No new secret store.
- Account files written before 0.8.0 have no SMTP fields. The webmail rewrites them once per session
  (`mailhub_accounts::backfill()`, at login and when Mail accounts is opened). Until then the relay
  refuses that sender with "open Mail accounts and save it again".
- The provider login is read through one function (`credentials()`), so an OAuth token flow can
  replace the password later.

## Sent copies

The relay saves nothing. Clients keep their default Sent folder, which is the local `Sent`: the
same place the webmail saves to (`sent_mbox = 'Sent'`). Nothing is configured per identity.
Gmail also keeps its own copy of what its SMTP sends.

Later, for both the webmail and the relay: the mirror files each message of the local `Sent` into
`Live/<From address>/Sent`, and copies it to the provider except for Gmail.

## Store listing

- **Service `mailhub-submit`:** `ports: ["465:31465", "587:31587"]`, 64 MB, read-only mounts of
  `accounts/`, `mirror/state/.submit/` and `tls/`.
- **Tips and Phones & computers:** "SMTP server | `${APP_TLS_HOST}`, port 465 SSL/TLS (or 587
  STARTTLS), same login".
- **`rationale.md`:** two more published ports; outbound submission to providers only (no port 25,
  no IP-reputation issue: the provider signs and delivers); device logins only; the sender check;
  the daily cap.

## Tests

`mailhub-mirror/tests/test_submit.py` (29 cases, against an in-process fake provider, run in CI):
the device login on both ports; refusing an authorization id, master-login syntax, AUTH before
STARTTLS, MAIL before AUTH, and starting the backoff; refusing a foreign sender, another user's
address, `From:` ≠ `MAIL FROM`, two `From:`s, no `From:`, and `<>`; one refused recipient among
others; the provider's 5xx/4xx at DATA; a stalled provider (4xx within the budget); a refused
provider password; the daily cap; SIZE.

Still to do by hand on a test box: real Gmail (app password) and privateemail.com sends with DKIM
checked, and Thunderbird plus one phone client end to end.
