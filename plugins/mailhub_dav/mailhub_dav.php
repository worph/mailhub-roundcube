<?php

/**
 * MailHub: calendar & contacts, from MailHub's built-in DAV server or another one.
 *
 * Loads the Kolab `calendar` and `kolab_addressbook` plugins with their CalDAV /
 * CardDAV drivers pointed at:
 *   - by default, MailHub's built-in Baïkal (`mailhub_dav_default_url`), with the
 *     mailbox's device login (mailhub_sso: the one phones use), so nothing has to
 *     be set up;
 *   - or another CalDAV/CardDAV server the user connects in Settings → "Calendar
 *     & contacts" (Nextcloud, Radicale, a standalone Baïkal…). That connection is
 *     tested before it is saved, and its password stored encrypted.
 */
class mailhub_dav extends rcube_plugin
{
    private const PREF = 'mailhub_dav';
    private const SECTION = 'mailhub_dav';

    public function init()
    {
        $this->add_texts('localization/', false);

        $this->add_hook('preferences_sections_list', [$this, 'sections']);
        $this->add_hook('preferences_list', [$this, 'prefs_form']);
        $this->add_hook('preferences_save', [$this, 'prefs_save']);

        $this->connect_plugins();
    }

    /**
     * Point the Kolab DAV drivers at the user's server and load them.
     */
    private function connect_plugins()
    {
        $rcmail = rcmail::get_instance();
        if (empty($rcmail->user->ID)) {
            return;
        }

        $dav = $rcmail->config->get(self::PREF);
        if (!empty($dav['url']) && !empty($dav['user'])) {
            $url = self::url_with_credentials($dav['url'], $dav['user'], $rcmail->decrypt($dav['pass'] ?? ''));
            // The DAV account is "me" for libkolab's personal/shared split (patched
            // in the image): its calendars are the user's own, not shared ones.
            $rcmail->config->set('kolab_dav_owner', $dav['user']);
        } elseif ($url = $rcmail->config->get('mailhub_dav_default_url')) {
            // Built-in server: this mailbox's device login (mailhub_sso). The
            // session holds no password to reuse (Roundcube logs in to Dovecot
            // with its master login).
            if (class_exists('mailhub_sso')) {
                [$user, $pass] = mailhub_sso::device_login((string) $_SESSION['username']);
                if ($pass === '' || !mailhub_sso::dav_ready($user)) {
                    return;  // not in effect at Baïkal yet: no calendar rather than a failing one
                }
                $url = self::url_with_credentials($url, $user, $pass);
                $rcmail->config->set('kolab_dav_owner', $user);
            }
        } else {
            return;
        }

        $rcmail->config->set('calendar_driver', 'caldav');
        $rcmail->config->set('calendar_caldav_server', $url);
        $rcmail->config->set('kolab_addressbook_driver', 'carddav');
        $rcmail->config->set('kolab_addressbook_carddav_server', $url);

        $this->require_plugin('calendar');
        $this->require_plugin('kolab_addressbook');
    }

    /**
     * libkolab's DAV client takes credentials from the URL's userinfo when
     * present (otherwise it would reuse the IMAP login).
     */
    private static function url_with_credentials($url, $user, $pass)
    {
        $parts = parse_url($url);
        $auth = rawurlencode($user) . ':' . rawurlencode((string) $pass) . '@';

        return $parts['scheme'] . '://' . $auth . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }

    public function sections($args)
    {
        $args['list'][self::SECTION] = [
            'id'      => self::SECTION,
            'section' => $this->gettext('section'),
        ];

        return $args;
    }

    public function prefs_form($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $dav = (array) $rcmail->config->get(self::PREF, []);
        $connected = !empty($dav['url']);
        $builtin = !$connected && $rcmail->config->get('mailhub_dav_default_url');
        $status = $connected ? $this->gettext('connected') . ' ' . $dav['url']
            : $this->gettext($builtin ? 'builtin' : 'notconnected');

        $url  = new html_inputfield(['name' => '_dav_url', 'id' => 'dav_url', 'size' => 60,
            'placeholder' => 'https://cloud.example.com/remote.php/dav']);
        $user = new html_inputfield(['name' => '_dav_user', 'id' => 'dav_user', 'size' => 30, 'autocomplete' => 'off']);
        $pass = new html_passwordfield(['name' => '_dav_pass', 'id' => 'dav_pass', 'size' => 30, 'autocomplete' => 'new-password',
            'placeholder' => $connected ? $this->gettext('passunchanged') : '']);

        $args['blocks']['server'] = [
            'name'    => rcube::Q($this->gettext('server')),
            'options' => [
                'status' => [
                    'title'   => rcube::Q($this->gettext('status')),
                    'content' => rcube::Q($status),
                ],
                'url'  => ['title' => html::label('dav_url', rcube::Q($this->gettext('url'))), 'content' => $url->show($dav['url'] ?? '')],
                'user' => ['title' => html::label('dav_user', rcube::Q($this->gettext('user'))), 'content' => $user->show($dav['user'] ?? '')],
                'pass' => ['title' => html::label('dav_pass', rcube::Q($this->gettext('pass'))), 'content' => $pass->show()],
                'help' => ['title' => '', 'content' => rcube::Q($this->gettext('help'))],
            ],
        ];

        return $args;
    }

    public function prefs_save($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $old  = (array) $rcmail->config->get(self::PREF, []);
        $url  = trim((string) rcube_utils::get_input_string('_dav_url', rcube_utils::INPUT_POST));
        $user = trim((string) rcube_utils::get_input_string('_dav_user', rcube_utils::INPUT_POST));
        $pass = (string) rcube_utils::get_input_string('_dav_pass', rcube_utils::INPUT_POST, true);

        // An empty URL goes back to the built-in server (or, without one, to no
        // calendar at all).
        if ($url === '') {
            $args['prefs'][self::PREF] = null;
            return $args;
        }

        if (!preg_match('#^https?://[^/\s]+#i', $url) || $user === '') {
            return $this->refuse($args, $this->gettext('invalid'));
        }

        // Keep the stored password when the field is left empty.
        if ($pass === '' && !empty($old['pass']) && ($old['url'] ?? '') !== '') {
            $pass = (string) $rcmail->decrypt($old['pass']);
        }

        $error = $this->test($url, $user, $pass);
        if ($error !== null) {
            return $this->refuse($args, $this->gettext('testfailed') . ' ' . $error);
        }

        $args['prefs'][self::PREF] = [
            'url'  => rtrim($url, '/'),
            'user' => $user,
            'pass' => $rcmail->encrypt($pass),
        ];

        return $args;
    }

    private function refuse($args, $message)
    {
        $args['abort'] = true;
        $args['result'] = false;
        $args['message'] = $message;

        return $args;
    }

    /**
     * Ask the server who we are: a DAV server answers 207 with the user's
     * principal. Returns null on success, a short reason otherwise.
     */
    private function test($url, $user, $pass)
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>';

        try {
            $client = new GuzzleHttp\Client(['timeout' => 15, 'http_errors' => false, 'allow_redirects' => ['strict' => true]]);
            $response = $client->request('PROPFIND', $url, [
                'auth'    => [$user, $pass],
                'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'],
                'body'    => $body,
            ]);
        } catch (Exception $e) {
            return $this->gettext('unreachable');
        }

        $code = $response->getStatusCode();
        if ($code == 401 || $code == 403) {
            return $this->gettext('badlogin');
        }
        if ($code != 207 || stripos((string) $response->getBody(), 'current-user-principal') === false) {
            return $this->gettext('notdav') . " (HTTP $code)";
        }

        // Then the way the calendar itself will connect: libkolab's DAV client
        // (HTTP_Request2) is refused by some front proxies that let the probe
        // above through — seen with Cloudflare in front of the PCS gateway.
        $this->require_plugin('libkolab');
        $client = new kolab_dav_client(self::url_with_credentials($url, $user, $pass));
        $homes = $client->discover();
        if (empty($homes)) {
            return $this->gettext('clientrefused');
        }

        return null;
    }
}
