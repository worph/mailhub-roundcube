# mailhub-roundcube

`ghcr.io/worph/mailhub-roundcube`: the webmail of the Yundera store's **MailHub** app. Roundcube + Kolab
calendar and address book plugins (CalDAV / CardDAV drivers) + identity_smtp (send through each identity's
own provider) + our `mailhub_dav`, `mailhub_accounts` and `mailhub_branding` plugins + 11 skins.

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
- **`plugins/mailhub_dav/`**: loads the calendar and contacts plugins against MailHub's built-in Baïkal,
  or against another CalDAV/CardDAV server set in *Settings → Calendar & contacts* (tested before saving,
  password stored encrypted).
- **`plugins/mailhub_accounts/`**: *Settings → Mail accounts*, its own entry in the Settings menu (after
  Identities). One card per account with its live status (refreshed every 15 s: first download count, up to
  date, or the provider's error) and buttons applied at once: *Sync now*, *Change password*, *Rename*, *Remove…*
  (confirmation; the mirror stops and deletes this server's copy, nothing is deleted at the provider, the
  identity stays). *Keep out of Merged* / *Show in Merged* leaves an account out of `Merged › All inboxes`,
  `Unread` and `Flagged` (`"in_views": false` in its file; mailhub-mirror 0.4.1+), for an address that gets
  mostly spam: still mirrored, read in its own `Live › <address>` folders. *Add an account* opens a dialog: Gmail, Namecheap Private Email or any IMAP/SMTP provider;
  the IMAP and SMTP logins are tested, then the mirror account file (`<slug>.json`, 0600) and the identity with
  the provider's SMTP (identity_smtp's format) are written. Settings: `mailhub_accounts_dir`
  (`/var/mailhub/accounts`), `mailhub_mirror_state_dir` (`/var/mailhub/mirror`).
- **`plugins/mailhub_branding/`**: the MailHub icon as logo (light, dark, small screens) and favicon in every
  skin, through Roundcube's `skin_logo` (left alone if the config sets one), plus a web app manifest, Apple
  touch icon and app name in every page's head, so *Install app* / *Add to Home screen* on a phone gives
  MailHub's icon. The PNGs and `favicon.ico` are rendered from `icons/icon.svg` (rounded square) and
  `icons/maskable.svg` (full bleed, for Android's adaptive shapes and iOS). The manifest is `manifest.json`
  because `static.php` doesn't serve `.webmanifest`; its URLs are relative (`../../../../` is the
  install root, seen from `static.php/plugins/mailhub_branding/icons/`). Elastic2022 appends its own
  manifest (Roundcube's icons) from `ui.js`; browsers use the first one in the page, ours.
- **`plugins/mailhub_folders/`**: the merged folders are the main ones. The mail view opens on
  `Merged/All inboxes`, listed first as *Inbox*; `Merged/Trash` and `Merged/Junk` are Roundcube's Trash and Junk
  (over the user's Special folders setting), so Delete moves a message there (into the local Trash: the mirror's
  views save there), deleting inside Trash or *Empty* deletes for good (at the provider too, for an account's own
  Trash). The local INBOX, Trash and Junk are not listed (their mail shows in the merged ones; still reachable as
  `?_mbox=INBOX`). Under `Live`, each account lists its INBOX first, and each account (and any folder in it with subfolders)
  starts collapsed the first time it is listed (`mailhub_folders_seen` pref); then Roundcube remembers what is
  opened or closed. A container folder (`Live`, an address) opens or closes when its name is clicked.
  Does nothing until the merged folders exist (mailhub-mirror 0.4.3+). Needs to be in the app's
  `ROUNDCUBEMAIL_PLUGINS`.
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
