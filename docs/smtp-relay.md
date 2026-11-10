# Design note: one SMTP login for mail apps

Status: proposal, nothing built. Written 2026-11-10.

## Goal

A mail app (Thunderbird, a phone's mail app) sets up MailHub with a single account: IMAP and SMTP
on the same host, with the same login. Every address the user connected in MailHub can then send
from that one account, each through its own provider.

Today the app needs the MailHub IMAP login plus one SMTP server per address, with that provider's
password. That is the hardest part of the phone and desktop setup, and each new client repeats it.

## What exists today

- **The webmail sends through each provider.** The webmail has a Roundcube identity per connected
  account, sent through that provider's SMTP (the `identity_smtp` plugin; `mailhub_accounts` writes
  its settings). The provider login it uses is the account's own `user` and password, the same as
  for IMAP.
- **The mirror already holds those credentials.** It reads `accounts/<user>/<slug>.json`, written by
  `mailhub_accounts`: `email`, `user`, `pass` (plain text, in a 0700 folder) and the IMAP host. The
  SMTP host, port and security are not in that file; they stay in Roundcube's prefs.
- **The device login** (`admin` plus `MAILHUB_PASSWORD`, or a user's generated device password) is
  checked by Dovecot. IMAPS 993 is published on the host. The app is `requires: [public-ip]`.
- **Outbound 465/587 to providers already works from the box**: the webmail uses it.
- On wisera on 2026-11-10, only 993 was listening among the mail ports; 465 and 587 were free.

## Proposal

### Shape

A small submission server, `mailhub-submit`: Python, `aiosmtpd` for the listening side and
`smtplib` for the provider side, about 300 lines. Build it into the mailhub-mirror image under a
second entrypoint and run it as its own service, so a crash or a restart doesn't touch the sync.

Postfix was considered and rejected. It can route by sender (`sender_dependent_relayhost_maps`, plus
per-sender SASL credentials), but it is heavy to configure for this, and it queues. Dovecot 2.4's
`submission` service relays to one fixed host only, so it can't pick a provider per address.

### Listening side

- **Port 465, implicit TLS** (RFC 8314), with the IMAPS certificate (`tls/`, the one Maison renews).
  One port is enough. 587 with STARTTLS is optional; leave it out unless a client needs it.
- **AUTH PLAIN and LOGIN only, after TLS.** No plain-text listener.
- **SIZE** advertised at what the providers accept, e.g. 25 MB. Gmail's limit is 25 MB.

### Authentication: the device login, never the master

The username and password are checked against Dovecot: an `IMAP LOGIN` to `mailhub-imap` on the
internal port, or Dovecot's auth service over an internal listener.

**Any `authzid`, and any `*` master-user syntax, is refused before Dovecot sees it.** The relay
connects to Dovecot from a private address, and the master login is accepted from private addresses
(`allow_nets` in `seed/dovecot/auth.conf`). Passing a client's authzid through would expose the
master login to the internet. This is the most important rule in this note, and it needs its own
test.

Failed logins are rate-limited per client IP (fail2ban-style backoff in the process). The same goes
for IMAPS, if it isn't done there already.

### Sender check: no open relay

For the logged-in local user (`admin`, or a registry user):

- **The allowed senders** are the `email` of each of its account files (`accounts/<user>/*.json`).
- **Both addresses must be allowed.** `MAIL FROM` and the `From:` header must each be one of them,
  and must name the same account. Anything else gets `550 5.7.1`.
- **The provider is chosen from that address**, never from anything else the client sends.
- **v1 covers each account's main address only.** Aliases (sending as `sales@` through the `contact@`
  account) come later, with an explicit list in the account file.

### Provider side: synchronous, no queue

- **The relay stays open until the provider answers.** It holds the client's `DATA` until the
  provider has answered, then passes on the provider's reply: 2xx stays 2xx, 5xx stays 5xx, and
  4xx, timeouts or a connection error become 4xx.
- **Nothing is spooled**, so nothing is silently dropped and there is no queue to manage, monitor or
  back up. A refusal shows up in Thunderbird at send time: wrong app password, Gmail's sending
  limit, a revoked password.
- **Timeouts:** upstream connect 20 s, whole transaction 120 s.
- **Logs:** one line per message: user, account, recipients count, size, provider reply code.
  Never the subject or the body.

### Credentials

- **Add the SMTP settings to the account file.** `mailhub_accounts` writes `smtp_host`, `smtp_port`
  and `smtp_security` next to the IMAP ones. The relay reads the account files read-only, like the
  mirror does.
- **No new secret store.** The SMTP password is the account's `pass`, as `identity_smtp` uses
  today.
- **Missing fields mean no relay for that account.** An account file written before this change
  has no SMTP fields: the relay refuses that sender with a clear message ("open Mail accounts and
  save the account again"), or the webmail rewrites the files once on login.

### Sent copies

Providers differ: Gmail saves a copy of what its SMTP sends, most others don't. Thunderbird saves its
own copy over IMAP by default, into the folder its account settings name.

- **v1: the relay saves nothing.** The tips and the Phones & computers page tell the user to point
  each identity's *Copies & Folders* at `Live/<address>/Sent`. The mirror replays that APPEND to the
  provider, so the sent copy reaches the provider's Sent folder.
- **Later, optionally:** the relay appends the copy itself (not for Gmail), and the docs tell clients
  not to save one. This only pays off if v1 proves confusing.

### Store listing

- **New service `mailhub-submit`:** `ports: ["465:31465"]`, `cpu_shares`, about 64 MB, read-only
  mounts of `accounts/` and `tls/`, on the internal network with `mailhub-imap`.
- **Tips and Phones & computers:** a second row, "SMTP server | `${APP_TLS_HOST}`, port 465,
  SSL/TLS, same login". Drop "send through each provider's own SMTP".
- **`rationale.md`:** a second published port; outbound submission to providers (not port 25, so
  no IP reputation issue: the provider signs and delivers); the master-login rule; the sender check.

## Tests before shipping

1. **The master login.** A client logging in with an authzid, or with `admin*master`-style syntax,
   is refused. A normal device login works.
2. **No open relay:**
   - a sender that isn't one of the user's addresses is refused (`MAIL FROM`, then `From:`);
   - another user's address is refused;
   - `MAIL FROM` and `From:` naming two different accounts are refused.
3. **The provider's reply passes through**, against GreenMail: a 5xx refusal and a 4xx timeout
   reach the client as such.
4. **Real providers:** a send through the relay via Gmail (app password) and via a domain mailbox
   (privateemail.com) reaches the recipient. It is DKIM-signed by the provider, and the copy lands
   where expected.
5. **Thunderbird end to end:** one account with all addresses as identities, each sending through
   the one MailHub SMTP server.

## Open questions

- **587** as well as 465, for clients or networks that block 465?
- **Aliases** in v1, or only after the main addresses work?
- **Gmail OAuth:** today accounts use app passwords. If OAuth arrives later, the relay needs the
  token flow too, so it should not assume `pass` is a password forever.
