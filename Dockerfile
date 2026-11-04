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
COPY patches/ /patches/
RUN git clone --depth 1 --branch roundcubemail-plugins-kolab-${KOLAB_PLUGINS_VERSION} \
      https://git.kolab.org/diffusion/RPK/roundcubemail-plugins-kolab.git /rpk \
 && git clone --depth 1 --branch ${IDENTITY_SMTP_VERSION} \
      https://github.com/deflomu/Roundcube-SMTP-per-Identity-Plugin.git /out/identity_smtp \
 && for p in libcalendaring libkolab calendar kolab_addressbook; do cp -r /rpk/plugins/$p /out/; done \
 && rm -rf /out/identity_smtp/.git \
 && cd /out && for p in /patches/*.patch; do echo "applying $p" && git apply --verbose "$p"; done

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
COPY --from=less /rc/plugins/libkolab/skins/elastic/libkolab.css /usr/src/roundcubemail/plugins/libkolab/skins/elastic/libkolab.css
RUN cd /usr/src/roundcubemail \
 && COMPOSER_ALLOW_SUPERUSER=1 composer require --no-interaction --update-no-dev --no-progress \
      "sabre/vobject:~4.5.1" "pear/http_request2:~2.7" "caxy/php-htmldiff:~0.1" "lolli42/finediff:~1.0.3" \
 && rm -rf /root/.composer/cache
COPY plugins/ /usr/src/roundcubemail/plugins/
COPY post-setup/ /entrypoint-tasks/post-setup/
RUN chmod +x /entrypoint-tasks/post-setup/*
