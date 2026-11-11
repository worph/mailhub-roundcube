# mailhub-roundcube

`ghcr.io/worph/mailhub-roundcube`: the webmail of the Yundera store's **MailHub** app. Roundcube + Kolab
calendar and address book plugins (CalDAV / CardDAV drivers) + identity_smtp (send through each identity's
own provider) + our `mailhub_*` plugins (SSO, accounts, folders, archive, DAV, MCP, branding, push) + 11 skins.

The app itself (compose, seeded config, setup scripts, rationale) lives in the store: `Yundera/AppStore` →
`Apps/MailHub`. The other MailHub images (mirror, MCP server) and the test kit (`dev/`, including
`skin-shots.js` and `accounts-test.py`) are in [worph/mailhub](https://github.com/worph/mailhub), where this
image was built up to 0.4.0 (history kept here).

## Contents

- **Upstream versions** are build args at the top of `Dockerfile` (Roundcube, Kolab plugins,
  identity_smtp, skins).
- **`patches/`**: our changes to upstream plugins, applied with `git apply`. A patch that no longer
  applies after a version bump fails the build.
  - `identity_smtp-calendar-task.patch`: invitation replies (calendar task) go through the identity's SMTP.
  - `libkolab-dav-owner.patch`: a DAV account whose name differs from the mail login still counts as
    "my calendars" (`kolab_dav_owner`, set by `mailhub_dav`).

  Both are worth proposing upstream.
- **`plugins/mailhub_sso/`**: the server's sign-in is the only login. AppShield signs who is asking on every
  request (`X-AppShield-Assertion`, HS256, secret `MAILHUB_ASSERTION_SECRET`, audience
  `MAILHUB_ASSERTION_AUDIENCE`, default `mailhub`); the plugin verifies it and logs that identity in, with no
  form, to its own mailbox. Each identity (its `sub`) gets a local user name once: the first one `admin` (the
  mailbox that existed before), the next ones their user name. Roundcube reaches Dovecot through its master
  login (SASL PLAIN, authzid = the user; `MAILHUB_MASTER_PASSWORD`), so it never holds a user's password. A
  session stays bound to the identity that opened it (another `sub`, or none, ends it); without a valid
  assertion a page says to sign in through the server, never Roundcube's form; *Logout* also signs out of the
  server (`/nhl-auth/logout`). The plain `Remote-User` header is never trusted. Keeps the registry of users
  (`mailhub_users_dir`, `/var/mailhub/users/<name>.json`: `sub`, name, address, and for every user but the owner
  a generated device password: its `{SSHA512}` hash for Dovecot, its Baïkal digest, and itself encrypted with
  Roundcube's key), which mailhub-mirror 0.5.0+ applies. *Settings → Phones & computers* shows the mailbox's
  IMAPS and CalDAV/CardDAV login (`MAILHUB_IMAPS_HOST`, `MAILHUB_DAV_URL`); the owner's is the app's credential
  (`MAILHUB_PASSWORD`, changed in Maison), the others can be replaced there. Where skins show the account
  (Outlook above the folders, Elastic's folder-list title) it shows the server account signed in (AppShield's
  user name), not Roundcube's default sender; Roundcube's first identity (`<user>@<IMAP host>`, not an
  address) is named "Add a mail account to send" until the first mail account replaces it; and at login a
  default sender that is none of the mailbox's accounts gives way to the first account's. Must come first in
  the app's `ROUNDCUBEMAIL_PLUGINS`.
- **`plugins/mailhub_dav/`**: loads the calendar and contacts plugins against MailHub's built-in Baïkal
  (with the mailbox's device login, from `mailhub_sso`), or against another CalDAV/CardDAV server set in
  *Settings → Calendar & contacts* (tested before saving, password stored encrypted).
- **`plugins/mailhub_accounts/`**: *Settings → Mail accounts*, its own entry in the Settings menu (after
  Identities). One card per account with its live status (refreshed every 15 s: first download count, up to
  date, or the provider's error) and buttons applied at once: *Sync now*, *Change password*, *Rename*, *Remove…*
  (confirmation; the mirror stops and deletes this server's copy, nothing is deleted at the provider, the
  identity stays). *Keep out of Merged* / *Show in Merged* leaves an account out of `Merged › All inboxes`,
  `Unread` and `Flagged` (`"in_views": false` in its file; mailhub-mirror 0.4.1+), for an address that gets
  mostly spam: still mirrored, read in its own `Live › <address>` folders. *Add an account* opens a dialog: Gmail, Namecheap Private Email or any IMAP/SMTP provider;
  the IMAP and SMTP logins are tested, then the mirror account file (`<user>/<slug>.json`, 0600: each user's
  own folder) and the identity with the provider's SMTP (identity_smtp's format) are written. Settings:
  `mailhub_accounts_dir` (`/var/mailhub/accounts`), `mailhub_mirror_state_dir` (`/var/mailhub/mirror`), both
  with one folder per user since 0.5.0 (mailhub-mirror 0.5.0+).
- **`plugins/mailhub_branding/`**: the MailHub icon as logo (light, dark, small screens) and favicon in every
  skin, through Roundcube's `skin_logo` (left alone if the config sets one), plus a web app manifest, Apple
  touch icon and app name in every page's head, so *Install app* / *Add to Home screen* on a phone gives
  MailHub's icon. The PNGs and `favicon.ico` are rendered from `icons/icon.svg` (rounded square) and
  `icons/maskable.svg` (full bleed, for Android's adaptive shapes and iOS). The manifest is `manifest.json`
  because `static.php` doesn't serve `.webmanifest`; its URLs are relative (`../../../../` is the
  install root, seen from `static.php/plugins/mailhub_branding/icons/`). Elastic2022 appends its own
  manifest (Roundcube's icons) from `ui.js`; browsers use the first one in the page, ours. Needs to be in the
  app's `ROUNDCUBEMAIL_PLUGINS` (it was not before 0.6.0, so installed MailHubs got Roundcube's icon).
- **`plugins/mailhub_folders/`**: the merged folders are the main ones. The mail view opens on
  `Merged/All inboxes`, listed first as *Inbox*; `Merged/Trash` and `Merged/Junk` are Roundcube's Trash and Junk
  (over the user's Special folders setting), so Delete moves a message there (`mailhub_archive` sends it into its
  own account's Trash; local mail, or an account without a Trash folder, into the local Trash, where the mirror's
  views save), deleting inside Trash or *Empty* deletes for good (at the provider too, for an account's own
  Trash). The local INBOX, Trash and Junk are not listed (their mail shows in the merged ones; still reachable as
  `?_mbox=INBOX`). Under `Live`, each account lists its INBOX first, and each account (and any folder in it with subfolders)
  starts collapsed the first time it is listed (`mailhub_folders_seen` pref); then Roundcube remembers what is
  opened or closed. A container folder (`Live`, an address) opens or closes when its name is clicked.
  Does nothing until the merged folders exist (mailhub-mirror 0.4.3+). Needs to be in the app's
  `ROUNDCUBEMAIL_PLUGINS`.
- **`plugins/mailhub_archive/`**: *Archive*, as on Gmail, next to Delete: in the toolbar (Elastic2022 shows it beside
  Delete when messages are selected, and in the message header), in the full-page message view, and on each row of
  the list on hover, between Delete and the flag (right after Elastic2022's row Delete icon, styled like it; next to
  the flag in skins without one). A message goes
  into the IMAP Archive folder of its own account, `Live/<address>/Archive` (or `Archives`, whichever the account
  already has; created when it has neither, and the mirror creates it at the provider too), so the provider sees it
  archived. In `Merged/All inboxes` each message's real folder is asked of Dovecot (`FETCH X-MAILBOX`); a message of
  the local mailbox goes to the local `Archive`. Disabled inside an archive folder. Replaces Roundcube's `archive`
  plugin, which archives into a single folder: leave that one out of `ROUNDCUBEMAIL_PLUGINS`. Also registers
  `plugin.archive` and sets `env.archive_folder`, which is what skins look for. Delete goes the same way: a move
  into `Merged/Trash` (Delete, a row's Delete icon, a drag onto Trash) puts each message into its own account's
  Trash (last path part `Trash`, `Deleted Items`, `Deleted Messages` or `Bin`: Gmail's `[Gmail]/Trash`), so the
  provider has it in its Trash. On Gmail that is the only real delete (out of a folder is only a label off; the
  message stays in All Mail); it needs mailhub-mirror 0.7.0+, which replays a move inside one account as a
  provider MOVE. Local mail, an account without a Trash folder, or a message whose account is unknown goes into
  the local Trash, as before. `mailhub_folders` makes
  `Merged/Archive` (mailhub-mirror 0.4.5+) the Archive special folder: listed with Trash and Junk, the local
  `Archive` hidden.
- **`plugins/mailhub_mcp/`**: *Settings → AI assistant*, its own entry in the Settings menu (after Mail accounts):
  the MCP server ([worph/mailhub-mcp](https://github.com/worph/mailhub-mcp)) on or off, its write access, each tool
  on or off (each switch saved at once), the calls per 24 h / 7 / 30 days and per tool, and the latest 100 calls
  (time, tool, shortened arguments, result, duration) with *Clear history*. Only for the mailbox the MCP server
  serves, `mailhub_mcp_owner` (`admin`): any other user has no menu entry and no action. Writes `settings.json` in
  `mailhub_mcp_settings_dir` (`/var/mailhub/mcp-settings`), which the server reads on every request; reads the
  server's `tools.json` and `calls.jsonl` from `mailhub_mcp_log_dir` (`/var/mailhub/mcp-log`, read-only). Needs
  mailhub-mcp 1.1.0+ and to be in the app's `ROUNDCUBEMAIL_PLUGINS`.
- **`plugins/mailhub_push/`**: *Settings → Notifications* (after Mail accounts, every user): Web Push
  notifications for new mail on phones and computers, also with MailHub closed. *Turn on notifications here*
  subscribes this browser (service worker `sw.js`, no fetch handler, nothing cached) with the server key the
  mirror publishes (`mailhub_mirror_state_dir/.push/vapid-public.txt`); *Send test notification* (this device,
  or all) has the mirror send one and shows each device's answer; the devices list (label, added, last delivery or
  failure, *expired* once the push service drops a subscription, *Remove*); *Show sender and subject* and which
  accounts notify (default: those in the Merged views). Writes `.push.json` and `.push-test.json` in the user's
  `mailhub_accounts_dir/<user>/`; reads `<user>/push-status.json` from the mirror's state. Every page re-sends
  this browser's subscription once per session if the browser renewed it. The notification texts are written in
  the user's language for the mirror. On iPhone / iPad, Web Push needs MailHub added to the Home Screen
  (`mailhub_branding`'s manifest). Needs mailhub-mirror 0.6.0+ (the sender) and to be in the app's
  `ROUNDCUBEMAIL_PLUGINS`; `badge-96.png` is the monochrome envelope Android shows in the status bar.
- **`mailhub_collect`** ([worph/mailhub_collect](https://github.com/worph/mailhub_collect), pinned by
  commit, `MAILHUB_COLLECT_COMMIT`): *Contacts → Collect* fills Collected recipients from every Sent folder and
  the senders you replied to, incrementally, and shows "Collected from" in the contact view. Its table is
  created by `post-setup/10-plugin-schemas.php`. Needs to be in the app's `ROUNDCUBEMAIL_PLUGINS`.
- **Skins**: 11 to choose from in *Settings → User interface*, the app's default being
  [Elastic2022](https://github.com/seb1k/Elastic2022): Roundcube's Elastic,
  [Workbench](https://github.com/AR-Sebastian/roundcube-skin-workbench),
  [Elastic2025](https://github.com/bijanbina/Elastic2025),
  [Stretched Elastic](https://github.com/johndoh/roundcube-stretchedelastic),
  [Outlook](https://github.com/theaustinayers/Outlook-Skin-for-RoundCube),
  [Air and Air Blue](https://github.com/skive/roundcube-air-skin),
  [Elastic Google](https://github.com/nyouaku/elastic-google), [hellomail](https://github.com/dcwq/hellomail)
  and [Elastic Accessibility](https://github.com/nicolas-joubert/roundcube-skins-elastic-accessibility).
  All CC BY-SA 3.0 or GPL-3.0, pinned by tag or commit in the Dockerfile. Outlook and Air are full Elastic
  copies rather than `extends: elastic`, so the Dockerfile links each plugin's Elastic templates for them
  (without it the calendar says "File not found"). Tried and left out: Ultimate Gmail (ships unbuilt, renders
  unstyled) and Larry (not responsive). worph/mailhub's `dev/skin-shots.js` screenshots each skin (mail,
  calendar, settings).
- **`post-setup/10-plugin-schemas.php`**: creates and upgrades the plugins' database tables (the official
  image only handles Roundcube's own), with a fix for libkolab's duplicate SQLite index name.

## Building and releasing

```sh
docker build -t mailhub-roundcube .
```

CI (`.github/workflows/build.yml`) builds amd64 + arm64 on every push and PR. Pushing a tag `vX.Y.Z`
publishes `ghcr.io/worph/mailhub-roundcube:X.Y.Z` (and `X.Y`); then bump the two `mailhub-roundcube` tags in
the store's `Apps/MailHub/docker-compose.yml` (the `mailhub-webmail` service and the `tls-fallback` hook).
Versions continue from worph/mailhub: 0.4.0 was the last one built there.
