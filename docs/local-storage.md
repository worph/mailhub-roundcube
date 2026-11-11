# Design note: mail kept on the server

Status: proposal. Written 2026-11-10, revised 2026-11-11: the first step is **Copy to server**
(the provider untouched); *Move to server* (freeing space at the provider) comes after it.
The Delete fix it depends on is built: mailhub-roundcube's `mailhub_archive` + mailhub-mirror 0.7.0.

## Goal

Let a user keep mail in MailHub's own storage, the server's mailbox (the "local" mailbox),
on purpose:

1. **Copy to server** (this note): a copy of chosen mail kept on the user's server, the provider
   keeping its own. Nothing changes at the provider, so nothing can be lost there. It is a
   backup the user controls, and the base the move builds on.
2. **Move to server** (later): the same, then deleted at the provider, to free space there.

Today the merged folders are the main view, so the local mailbox is invisible. Once it holds mail
the user put there, it has to be visible, and it has to be clear what it is.

## What exists today

- Each user has one Dovecot mailbox. Provider mail is pulled into `Live/<address>/` (mailhub-mirror,
  pull only, the provider staying the source of truth). Everything outside `Live/` and `Merged/` is
  the local mailbox.
- The local `INBOX`, `Trash`, `Junk` and `Archive` back the merged views: `Merged/All inboxes`,
  `Trash`, `Junk` and `Archive` include them. `mailhub_folders` hides those four. Anything else at
  the top level is listed: the local `Sent` and `Drafts`, and any folder a user creates outside `Live/`.
- **A copy out of `Live/` already leaves the provider alone.** The Dovecot Lua hook records every
  action, and the mirror replays only those on `Live/` folders. A message added to a local folder
  is not replayed; only an expunge from `Live/` is. So a copy (Roundcube's *More → Copy to…*, or a
  drag with the copy modifier) into a local folder is already a Copy to server, mechanically.
- **Gmail**: `[Gmail]/All Mail`, Important and Starred are not mirrored (`GMAIL_SKIP`). A message
  is held once per label in `live/`, and mail with no label (archived on Gmail) is not mirrored at
  all, so MailHub cannot copy it.
- Backups: Maison snapshots the whole app folder, nothing excluded. Local mail
  (`mail/<user>/mail/`) is already backed up; provider mail (`mail/<user>/live/`) is a copy the next
  pull would rebuild.

### Fixed alongside (2026-11-11)

- **Delete** sends a message to its own account's Trash (`Live/<address>/Trash`, `[Gmail]/Trash`, …),
  no longer to the local Trash. On Gmail, taking a message out of a folder only removes a label (it
  stays in All Mail), so the old Delete archived it there.
- **A move inside one account** is replayed as one provider `UID MOVE` (paired by Message-ID with
  the expunge that follows), not `APPEND` + `EXPUNGE`: on Gmail, archiving changes the label instead
  of making a second copy, and a move to `[Gmail]/Trash` is a real delete.
- **Data loss in mailhub-mirror ≤ 0.6.0**: a move of several messages into a `Live/` folder
  appended only the first one at the provider and expunged all of them there (a pull in the middle
  of the batch renamed the others). The rest survive only as unpaired files in `live/`, and
  removing the account deletes them. Found on wisera: 79 in `pierre.henri@aptero.co/Archive`.
  0.7.0 needs a recovery step for installs that ran ≤ 0.6.0 (see Open questions).

## Proposal: Copy to server

### 1. A visible "On this server" folder (built, 2026-11-11)

A real Dovecot folder, `Local`, shown in the webmail as *On this server* (`mailhub_folders`): created
at login when missing, listed right after the accounts even while empty, with a hover hint ("Kept on
this server only…"). A real folder rather than a display grouping, so *Copy to… / Move to…*, drag and
drop, creating subfolders and mail apps on the device login all reach it with no patching; phones see
it as `Local`.

- Outside `Live/`: the mirror replays nothing for it, so a copy there never changes the provider.
- Outside the merged views: a copy never shows twice in Inbox or Archive.
- The local `INBOX`, `Trash`, `Junk` and `Archive` stay hidden: plumbing for the merged views.
- The local `Sent` and `Drafts` stay where they are for now; moving them under `Local/` means a
  Dovecot rename and every identity's Sent/Drafts setting, a migration of its own.

### 2. The action

*Copy to server* on a message, a selection, or a whole folder of an account:

- Into `Local/<address>/<folder>` (*On this server › address › folder*), created when missing, so a
  copied account keeps its shape. A message already copied (same
  Message-ID in the target) is skipped, so running it twice is safe.
- A copy, so the source stays where it is: nothing is replayed, the provider is untouched.
- **Disk check first**: refuse a copy that would leave the server's disk below a margin. A provider
  holds gigabytes; the PCS disk is the user's.
- **Progress** for a folder or a big selection, run in the background (a few thousand messages is
  a queue, not one request), shown the way the first download is.

### 3. Merged views and search

Copies sit outside `Live/`, so the merged views would show a message twice (the account's and the
server's copy) for any local folder they cover. Rule: the merged views cover only the local
`INBOX`, `Trash`, `Junk` and `Archive` as today, and Copy to server puts an account's Archive into
*On this server › <address> › Archive*, not into the local `Archive`. Search over *On this server*
reaches the copies; search over the merged views does not show them twice.

When Move comes, the moved mail is the only copy and should then show in `Merged/Archive`: that is
the point where the local `Archive` gets used.

### 4. Gmail

Copy to server works on what is mirrored: every labelled message. Gmail mail with no label (archived
there) is not mirrored, so it cannot be copied; the action says so for a Gmail account. Mirroring
All Mail would double `live/` for every Gmail account; for Move, the age rule (below) reads All Mail
at the provider directly instead.

### 5. It is a second copy

Copied mail is in the server's backup (`mail/<user>/mail/`, already covered). The section's page
says in one line that the provider keeps its own copy, and that removing an account from MailHub
keeps what was copied to the server.

## Later: Move to server

Built on Copy: copy, check the copy exists, then delete at the provider.

- **The delete at the provider**: the copy, then the account's Trash move (as Delete now does), then
  expunging it from the provider's Trash. On Gmail only leaving `[Gmail]/Trash` (or expunging there)
  frees space; to test against a real Gmail account before shipping.
- **Order**: the expunge only after the local copy exists, also when the mirror does it itself.
- **Failures** retried and counted on the Mail accounts page (today a failed replay line goes to
  `failed.jsonl` and is not retried).
- **Speed**: the replay goes one message at a time; batch UID sets per folder for bulk moves.
- **Quotas**: each account's used space and quota (`GETQUOTAROOT`, when the provider has QUOTA) on
  the Mail accounts page, next to the space used on the server.
- **The age rule**: *move archived mail older than N months to the server*, run by the mirror at the
  provider directly (on Gmail: search All Mail, fetch, write locally, then Trash). It is the real
  answer for Gmail, where most mail is archived and unmirrored.
- **Only copy**: once moved, the server's backup is what protects it; say so on the first move, in
  the store's `rationale.md` and in the restore notes (a restore that predates a move loses the
  moved mail at both ends).

## Phasing

1. `mailhub_folders`: the *On this server* folder (`Local`). Built.
2. *Copy to server*: the action, target folders, de-duplication, disk check, progress.
3. Quotas on the Mail accounts page.
4. *Move to server*, then the age rule.

## Open questions

- Recovery for installs that ran mailhub-mirror ≤ 0.6.0: APPEND the unpaired files of each `live/`
  folder back to the provider (checking by Message-ID that the provider lacks them), or move them
  into *On this server*. On wisera: 79 messages, to settle with the owner first.
- Copy of a whole account: one action per account, or per folder only?
- An account's Trash on Gmail in another language (`[Gmail]/Corbeille`): Delete and the Trash view
  match folder names, not the provider's `\Trash` special-use. Worth reading `LIST (SPECIAL-USE)`
  in the mirror.
- IMAP clients (device login) see the local folders under their raw names; a Dovecot namespace for
  *On this server* would mean a resync on every client. Probably not worth it.
