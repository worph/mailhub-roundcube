# Design note: mail kept on the server

Status: proposal, nothing built. Written 2026-11-10.

## Goal

Let a user move mail out of a provider mailbox (Gmail, a domain mailbox, any IMAP account) into
MailHub's own storage, to free space at the provider. That mail then lives only on the user's server.

Today the merged folders are the main view, so the server's own mailbox (the "local" mailbox) is
invisible. Once it holds mail the user put there on purpose, it has to be visible, and it has to be
clear what it means.

## What exists today

- Each user has one Dovecot mailbox. Provider mail is pulled into `Live/<address>/` (mailhub-mirror,
  pull only, the provider staying the source of truth). Everything outside `Live/` and `Merged/` is
  the local mailbox.
- The local `INBOX`, `Trash`, `Junk` and `Archive` back the merged views: `Merged/All inboxes`,
  `Trash`, `Junk` and `Archive` include them and save into them. `mailhub_folders` hides those four.
  Anything else at the top level is listed: the local `Sent` and `Drafts`, and any folder a user
  creates outside `Live/`.
- **Moving mail off a provider already works mechanically.** The Dovecot Lua hook records every
  action on a `Live/` folder and the mirror replays it at the provider. A message removed from
  `Live/<address>/…` becomes `UID EXPUNGE` of that one UID there. Moving a message from an account
  into a local folder is therefore "copy here, then delete there". It is how Delete works now:
  `Merged/Trash` saves into the local Trash.
- On wisera on 2026-11-10, the owner's local mailbox held 8 messages, all in `Trash`. `Sent`,
  `Drafts`, `Archive`, `Junk` and `INBOX` were empty. The provider copies (`live/`) took 2.0 GB.
- Backups: Maison snapshots the whole app folder, and nothing is excluded. Local mail
  (`mail/<user>/mail/`) is already backed up. Provider mail (`mail/<user>/live/`) is only a copy,
  and the next pull would rebuild it.

So the work is mostly to present what exists, to add a deliberate *Move to server* action, and to
handle the provider-specific cases. The storage mechanism itself is not new.

## Proposal

### 1. A visible "On this server" section

A container listed next to the accounts under `Live/`, shaped like an account. Name it for what it
is: *On this server*, or *Kept on MailHub*. Avoid "Local", which is jargon.

- It shows the user's own folders and an Archive that is clearly the server's.
- The local `INBOX`, `Trash` and `Junk` stay hidden. They are plumbing for the merged views.
- The local `Sent` and `Drafts` move under it, instead of sitting at the top level next to
  *Inbox*.
- Show it only once it holds something other than the hidden plumbing, or once the move feature
  ships. An empty tree with no way to fill it is noise.

Storage stays where it is. This only changes what `mailhub_folders` shows (a display name and
grouping), so there is no Dovecot rename and nothing for IMAP clients to resync.

### 2. Merged views keep covering it

`Merged/Archive` already includes the local `Archive`, and search over the merged views reaches local
mail. Keep that rule for any folder kept on the server, so moving old mail off a provider never
makes it disappear from Archive or from search. The user sees the same place with different
storage. This is what makes the move feel safe.

Open question: whether user folders on the server, beyond Archive, should feed a merged view too.
Probably not; a user folder is a place you go to on purpose.

### 3. Move semantics: copy, check, then delete at the provider

The replay already orders this correctly: Dovecot writes the destination before it expunges the
source, and the hook fires on commit. For a deliberate bulk move, add:

- **A disk check first.** Refuse a move that would leave the server's disk below a margin. A
  provider holds gigabytes; the PCS disk is the user's.
- **Progress and a failure report** for big moves. The replay goes one UID at a time, so a
  10 000-message move is a queue. The Mail accounts page should show it the way it shows the first
  download.
- **The expunge happens only after the local copy exists.** Keep that order if the move is ever done
  by the mirror itself (a rule, see 5) rather than through Dovecot.

### 4. Provider cases

- **Gmail.** Removing a message from a Gmail folder (a label) does not free space: by default the
  message stays in *All Mail*. To free space, the message must leave `[Gmail]/All Mail` (or go to
  Trash and be emptied), depending on the account's *Auto-Expunge* / *When a message is deleted*
  settings. *Move to server* on a Gmail account has to target All Mail, or say plainly what it
  frees. This needs a test against a real Gmail account before shipping.
- **Quotas.** Show what the provider reports (`GETQUOTAROOT`) when it supports the QUOTA extension,
  and nothing when it doesn't.

### 5. Showing why you'd do it

- On the Mail accounts page, each account's used space and quota (when known), next to the space
  used by mail kept on the server. That is what tells a user "Gmail is at 14.2 of 15 GB".
- Then, a rule: *move archived mail older than N months to the server*, run by the mirror. It is the
  natural step after the manual move, and it is what actually keeps a provider under its quota.

### 6. It is now the only copy

Once mail lives only on the server, the server's backup is what protects it:

- The section's page, and the first *Move to server*, say so in one line.
- The store's `rationale.md` and the app's backup comment get the same point. The backup already
  covers `mail/<user>/mail/`, so no config change is needed; it is a wording change.
- A restore that predates a move brings back a mailbox where the moved mail is missing at both ends.
  Worth a sentence in the restore notes.

### 7. IMAP clients

A phone's IMAP client, using the device login, already sees the whole Dovecot tree: `Live/`,
`Merged/`, and the local folders under their raw names. Option: make the local folders appear under
the same name there too, through a Dovecot namespace prefix or a rename. That is a migration with a
resync on every client, so it only makes sense together with the move feature, if at all.

## Phasing

1. `mailhub_folders`: the *On this server* section (display only, shown when non-empty). Small, and
   useful on its own: it explains where deleted mail goes and where `Sent`/`Drafts` sit.
2. *Move to server*: a button or folder action, with the disk check, progress, and the Gmail
   handling (tested on a real Gmail account).
3. Quotas on the Mail accounts page.
4. The age rule, run by the mirror.

## Open questions

- The name: *On this server* or *Kept on MailHub*.
- Should the local `Trash` count as "on the server" in the section? It holds deleted mail, which
  already left the provider.
- Gmail's *All Mail*: is it mirrored today, and if so, is every Gmail message held twice in `live/`?
  That affects both the space shown and what a move frees.
