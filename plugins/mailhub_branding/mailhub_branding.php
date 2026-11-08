<?php

/**
 * MailHub: the MailHub icon in every skin, and a web app manifest so that
 * "Install app" / "Add to Home screen" on a phone gives a proper app icon.
 *
 * - Logo and favicon: sets Roundcube's `skin_logo` (all skins, light and dark,
 *   small screens) unless the admin set one, so it works whichever skin is picked.
 * - PWA: adds the manifest (icons/manifest.json), the Apple touch icon and
 *   the app name to the head of every page, login page included.
 *
 * The icons are rendered from icons/icon.svg (any purpose: rounded square, transparent
 * corners) and icons/maskable.svg (full bleed, the launcher applies its own shape).
 */
class mailhub_branding extends rcube_plugin
{
    public $noajax = true;

    private const ICONS = 'plugins/mailhub_branding/icons/';

    public function init()
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->config->get('skin_logo')) {
            $logo = self::ICONS . 'icon.svg';
            $rcmail->config->set('skin_logo', [
                '*'             => $logo,
                '*[dark]'       => $logo,
                '*[small]'      => $logo,
                '*[small-dark]' => $logo,
                '[favicon]'     => self::ICONS . 'favicon.ico',
            ]);
        }

        $output = $rcmail->output;
        if (!$output || $output->type != 'html') {
            return;
        }

        $name = rcube::Q($rcmail->config->get('product_name') ?: 'MailHub');
        // with credentials: the manifest must load behind an auth proxy too
        $output->add_header(implode("\n", [
            '<link rel="manifest" href="' . self::ICONS . 'manifest.json" crossorigin="use-credentials">',
            '<link rel="icon" type="image/svg+xml" href="' . self::ICONS . 'icon.svg">',
            '<link rel="apple-touch-icon" href="' . self::ICONS . 'apple-touch-icon.png">',
            '<meta name="application-name" content="' . $name . '">',
            '<meta name="apple-mobile-web-app-title" content="' . $name . '">',
            '<meta name="mobile-web-app-capable" content="yes">',
        ]));
    }
}
