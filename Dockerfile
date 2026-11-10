# MailHub webmail: Roundcube + Kolab calendar (CalDAV driver) + Kolab addressbook
# (CardDAV driver) + per-identity SMTP + the mailhub_dav settings plugin.
ARG ROUNDCUBE_VERSION=1.7.5

FROM roundcube/roundcubemail:${ROUNDCUBE_VERSION}-apache AS rc

# --- Fetch plugins and apply our patches ---------------------------------------
# `git apply` fails the build if a patch stops applying after a version bump,
# instead of silently shipping unpatched code.
FROM alpine/git:v2.49.1 AS plugins
ARG KOLAB_PLUGINS_VERSION=3.6.1
ARG IDENTITY_SMTP_VERSION=1.7.0
# Community skins, selectable in Settings → User interface; the default is set by
# ROUNDCUBEMAIL_SKIN in the app. All free (CC BY-SA 3.0 like Elastic, or GPL-3.0),
# pinned by tag or commit, each checked with dev/skin-shots.js (mail, calendar,
# settings) on Roundcube 1.7.5 with the Kolab plugins.
ARG WORKBENCH_VERSION=v1.2.1
ARG ELASTIC2022_VERSION=1.7.3
ARG STRETCHEDELASTIC_VERSION=0.2
ARG HELLOMAIL_VERSION=1.0.0
# no tags upstream: commits
ARG ELASTIC2025_COMMIT=e54548a6feb339849288ee4bbe456c459112f70d
ARG OUTLOOK_COMMIT=cdf7bff61b98ae972f8cb1a37e0f62cbbb32c8a4
ARG AIR_COMMIT=b2662e4ff2dc2b1aeb09da0b4571fb2b4c568b1a
ARG ELASTICGOOGLE_COMMIT=d323a72c29cd98c4ab88a1c339f0b82d896f2b65
ARG ACCESSIBILITY_COMMIT=2fec5b04a50cc559e041d46722c10cb87124c83b
# MailHub plugins kept in their own repo (commit)
ARG MAILHUB_COLLECT_COMMIT=58e88223fae76b4146d762dcfd151649cbc0b5bf
COPY patches/ /patches/
RUN git clone --depth 1 --branch roundcubemail-plugins-kolab-${KOLAB_PLUGINS_VERSION} \
      https://git.kolab.org/diffusion/RPK/roundcubemail-plugins-kolab.git /rpk \
 && git clone --depth 1 --branch ${IDENTITY_SMTP_VERSION} \
      https://github.com/deflomu/Roundcube-SMTP-per-Identity-Plugin.git /out/identity_smtp \
 && for p in libcalendaring libkolab calendar kolab_addressbook; do cp -r /rpk/plugins/$p /out/; done \
 && rm -rf /out/identity_smtp/.git \
 && cd /out && for p in /patches/*.patch; do echo "applying $p" && git apply --verbose "$p"; done \
 && git clone --depth 1 --branch ${WORKBENCH_VERSION} \
      https://github.com/AR-Sebastian/roundcube-skin-workbench.git /skins/workbench \
 && git clone --depth 1 --branch ${ELASTIC2022_VERSION} \
      https://github.com/seb1k/Elastic2022.git /skins/elastic2022 \
 && git clone --depth 1 --branch ${STRETCHEDELASTIC_VERSION} \
      https://github.com/johndoh/roundcube-stretchedelastic.git /skins/stretchedelastic \
 && git clone --depth 1 --branch ${HELLOMAIL_VERSION} https://github.com/dcwq/hellomail.git /src/hellomail \
 && at() { mkdir -p "$2" && git -C "$2" init -q && git -C "$2" fetch -q --depth 1 "https://github.com/$1.git" "$3" \
           && git -C "$2" checkout -q FETCH_HEAD; } \
 && at bijanbina/Elastic2025 /skins/elastic2025 ${ELASTIC2025_COMMIT} \
 && at theaustinayers/Outlook-Skin-for-RoundCube /src/outlook ${OUTLOOK_COMMIT} \
 && at skive/roundcube-air-skin /src/air ${AIR_COMMIT} \
 && at nyouaku/elastic-google /skins/elasticgoogle ${ELASTICGOOGLE_COMMIT} \
 && at nicolas-joubert/roundcube-skins-elastic-accessibility /skins/accessibility ${ACCESSIBILITY_COMMIT} \
 && at worph/mailhub_collect /out/mailhub_collect ${MAILHUB_COLLECT_COMMIT} && rm -rf /out/mailhub_collect/.git \
 && cp -r /src/hellomail/hellomail /skins/hellomail \
 && cp -r /src/outlook/skins/outlook /skins/outlook \
 && cp -r /src/air/skins/air /src/air/skins/airblue /skins/ \
 && cp /src/hellomail/LICENSE /skins/hellomail/LICENSE && cp /src/air/LICENSE /skins/air/LICENSE \
 && rm -rf /src /skins/*/.git /skins/*/.github

# --- Compile libkolab's Elastic stylesheet (ships as .less only) -------------
FROM node:22-alpine AS less
RUN npm install -g less@4.2.2 less-plugin-clean-css@1.6.0
COPY --from=rc /usr/src/roundcubemail/skins /rc/skins
COPY --from=plugins /out /rc/plugins
RUN cd /rc && lessc --clean-css="--s1 --advanced" --rewrite-urls=all \
      plugins/libkolab/skins/elastic/libkolab.less > plugins/libkolab/skins/elastic/libkolab.css

# --- Final image ---------------------------------------------------------------
FROM rc
COPY --from=plugins /out/ /usr/src/roundcubemail/plugins/
COPY --from=plugins /skins/ /usr/src/roundcubemail/skins/
COPY --from=less /rc/plugins/libkolab/skins/elastic/libkolab.css /usr/src/roundcubemail/plugins/libkolab/skins/elastic/libkolab.css
RUN cd /usr/src/roundcubemail \
 && COMPOSER_ALLOW_SUPERUSER=1 composer require --no-interaction --update-no-dev --no-progress \
      "sabre/vobject:~4.5.1" "pear/http_request2:~2.7" "caxy/php-htmldiff:~0.1" "lolli42/finediff:~1.0.3" \
 && rm -rf /root/.composer/cache
COPY plugins/ /usr/src/roundcubemail/plugins/
# Outlook and Air are full copies of Elastic, not `extends: elastic`, so plugins
# (calendar, contacts, ours) have no templates for them ("File not found" on the
# calendar): point each plugin's skin folder for them at its Elastic one. Air's
# empty-message placeholder is hard-coded in Russian: use Elastic's.
RUN cd /usr/src/roundcubemail \
 && for d in plugins/*/skins/elastic; do for s in outlook air airblue; do \
      [ -e "${d%/elastic}/$s" ] || ln -s elastic "${d%/elastic}/$s"; done; done \
 && cp skins/elastic/watermark.html skins/air/watermark.html \
 && test -L plugins/calendar/skins/outlook
COPY post-setup/ /entrypoint-tasks/post-setup/
RUN chmod +x /entrypoint-tasks/post-setup/*
