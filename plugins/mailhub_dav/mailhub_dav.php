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
 *
 * The same page lists the calendar subscriptions: other calendars (Google, Outlook,
 * any iCal address, or a CalDAV calendar with its login) that mailhub-mirror keeps as
 * read-only calendars in the built-in Baïkal (calsync.py, mailhub-mirror 0.9.0+).
 * They are kept in the prefs (address and password encrypted) and written to
 * `<mailhub_accounts_dir>/<user>/.calendars.json` (0600) for the mirror, which reports
 * on each in `<mailhub_mirror_state_dir>/<user>/calendars.json`.
 */
class mailhub_dav extends rcube_plugin
{
    private const PREF = 'mailhub_dav';
    private const SECTION = 'mailhub_dav';
    private const SUBS = 'mailhub_calsubs';
    private const COLORS = ['#0b8043', '#d50000', '#3f51b5', '#f4511e', '#8e24aa', '#039be5', '#e67c73', '#616161'];

    public function init()
    {
        $this->add_texts('localization/', false);

        $this->add_hook('preferences_sections_list', [$this, 'sections']);
        $this->add_hook('preferences_list', [$this, 'prefs_form']);
        $this->add_hook('preferences_save', [$this, 'prefs_save']);

        $this->connect_plugins();

        $task = rcmail::get_instance()->task;
        if ($task == 'mail' || $task == 'settings') {
            $this->backfill_subs();
        }
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

        $this->subs_form($args, $connected);

        return $args;
    }

    // ------------------------------------------------------ subscriptions ---

    /** The subscriptions in the prefs: [id => [name, color, url (encrypted), user, pass (encrypted)]]. */
    private function subs()
    {
        $subs = rcmail::get_instance()->config->get(self::SUBS, []);

        return is_array($subs) ? $subs : [];
    }

    private function subs_form(&$args, $external)
    {
        $rcmail = rcmail::get_instance();
        $subs = $this->subs();
        $status = json_decode((string) @file_get_contents($this->state_dir() . '/calendars.json'), true) ?: [];
        $options = [];

        if ($external) {
            $options['external'] = ['title' => '', 'content' => html::span(['style' => 'opacity:.75'], rcube::Q($this->gettext('subsexternal')))];
        }

        foreach ($subs as $id => $sub) {
            $name = new html_inputfield(['name' => "_calsub_name[$id]", 'id' => "calsub_name_$id", 'size' => 30]);
            $color = new html_inputfield(['name' => "_calsub_color[$id]", 'type' => 'color', 'style' => 'width:3em;min-width:3em;max-width:3em;height:2.4em;padding:2px;display:inline-block;vertical-align:middle']);
            $remove = new html_checkbox(['name' => "_calsub_remove[$id]", 'id' => "calsub_remove_$id", 'value' => '1']);
            $host = parse_url((string) $rcmail->decrypt($sub['url']), PHP_URL_HOST) ?: '';
            $options["sub_$id"] = [
                'title'   => html::label("calsub_name_$id", rcube::Q($sub['name'])),
                'content' => $name->show($sub['name']) . ' ' . $color->show($sub['color'])
                    . html::div(['style' => 'opacity:.75;font-size:90%;margin:.3em 0'], rcube::Q($host . ' · ' . $this->sub_status($status[$id] ?? null)))
                    . html::label("calsub_remove_$id", $remove->show() . ' ' . rcube::Q($this->gettext('subsremove'))),
            ];
        }

        if (!$subs) {
            $options['none'] = ['title' => '', 'content' => rcube::Q($this->gettext('subsnone'))];
        }

        $args['blocks']['subscriptions'] = [
            'name'    => rcube::Q($this->gettext('subs')),
            'options' => $options,
        ];

        $url  = new html_inputfield(['name' => '_calsub_new_url', 'id' => 'calsub_new_url', 'size' => 60, 'autocomplete' => 'off',
            'placeholder' => 'https://calendar.google.com/calendar/ical/…/basic.ics']);
        $name = new html_inputfield(['name' => '_calsub_new_name', 'id' => 'calsub_new_name', 'size' => 30,
            'placeholder' => $this->gettext('subsnameph')]);
        $color = new html_inputfield(['name' => '_calsub_new_color', 'type' => 'color', 'style' => 'width:3em;min-width:3em;max-width:3em;height:2.4em;padding:2px;display:inline-block;vertical-align:middle']);
        $user = new html_inputfield(['name' => '_calsub_new_user', 'id' => 'calsub_new_user', 'size' => 30, 'autocomplete' => 'off']);
        $pass = new html_passwordfield(['name' => '_calsub_new_pass', 'id' => 'calsub_new_pass', 'size' => 30, 'autocomplete' => 'new-password']);

        $args['blocks']['subscription_new'] = [
            'name'    => rcube::Q($this->gettext('subsadd')),
            'options' => [
                'url'  => ['title' => html::label('calsub_new_url', rcube::Q($this->gettext('subsurl'))), 'content' => $url->show()],
                'name' => ['title' => html::label('calsub_new_name', rcube::Q($this->gettext('subsname'))),
                    'content' => $name->show() . ' ' . $color->show(self::COLORS[count($subs) % count(self::COLORS)])],
                'user' => ['title' => html::label('calsub_new_user', rcube::Q($this->gettext('subsuser'))), 'content' => $user->show()],
                'pass' => ['title' => html::label('calsub_new_pass', rcube::Q($this->gettext('pass'))), 'content' => $pass->show()],
                'help' => ['title' => '', 'content' => rcube::Q($this->gettext('subshelp'))],
            ],
        ];
    }

    private function sub_status($st)
    {
        if (!is_array($st)) {
            return $this->gettext('subswaiting');
        }
        if (empty($st['ok'])) {
            return $this->gettext('subserror') . ' ' . ($st['error'] ?? '');
        }
        $ago = max(0, time() - (int) ($st['last_ok'] ?? 0));
        $when = $ago < 90 ? $this->gettext('subsjustnow')
            : ($ago < 5400 ? $this->gettext(['name' => 'subsminutes', 'vars' => ['n' => round($ago / 60)]])
            : $this->gettext(['name' => 'subshours', 'vars' => ['n' => round($ago / 3600)]]));

        return $this->gettext(['name' => 'subsok', 'vars' => ['when' => $when, 'n' => (int) ($st['events'] ?? 0)]]);
    }

    /**
     * The subscriptions from the form: renamed, recoloured, removed, and the new one
     * (tested first). Returns the new list, or a string: why it was refused.
     */
    private function subs_from_post()
    {
        $rcmail = rcmail::get_instance();
        $subs = $this->subs();
        $names = (array) rcube_utils::get_input_value('_calsub_name', rcube_utils::INPUT_POST);
        $colors = (array) rcube_utils::get_input_value('_calsub_color', rcube_utils::INPUT_POST);
        $remove = (array) rcube_utils::get_input_value('_calsub_remove', rcube_utils::INPUT_POST);

        foreach ($subs as $id => &$sub) {
            if (!empty($remove[$id])) {
                unset($subs[$id]);
                continue;
            }
            $name = trim((string) ($names[$id] ?? ''));
            if ($name !== '') {
                $sub['name'] = mb_substr($name, 0, 100);
            }
            $color = (string) ($colors[$id] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $sub['color'] = strtolower($color);
            }
        }
        unset($sub);

        $url = trim((string) rcube_utils::get_input_string('_calsub_new_url', rcube_utils::INPUT_POST));
        if ($url === '') {
            return $subs;
        }

        $user = trim((string) rcube_utils::get_input_string('_calsub_new_user', rcube_utils::INPUT_POST));
        $pass = (string) rcube_utils::get_input_string('_calsub_new_pass', rcube_utils::INPUT_POST, true);
        if (!preg_match('#^(https?|webcals?)://[^/\s]+#i', $url)) {
            return $this->gettext('subsinvalid');
        }

        $result = $user === '' ? $this->test_ics($url) : $this->resolve_caldav($url, $user, $pass);
        if (!is_array($result)) {
            return $this->gettext('subsfailed') . ' ' . $result;
        }

        $name = trim((string) rcube_utils::get_input_string('_calsub_new_name', rcube_utils::INPUT_POST));
        $color = (string) rcube_utils::get_input_string('_calsub_new_color', rcube_utils::INPUT_POST);
        $id = bin2hex(random_bytes(4));
        $subs[$id] = [
            'name'  => mb_substr($name !== '' ? $name : ($result['name'] ?: (parse_url($url, PHP_URL_HOST) ?: 'Calendar')), 0, 100),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : self::COLORS[count($subs) % count(self::COLORS)],
            'url'   => $rcmail->encrypt($result['url']),
            'user'  => $user,
            'pass'  => $user !== '' ? $rcmail->encrypt($pass) : '',
        ];

        return $subs;
    }

    /** An iCal address answers with a calendar: [url, name] or a reason. */
    private function test_ics($url)
    {
        $get = preg_replace('#^webcals?://#i', 'https://', $url);
        try {
            $client = new GuzzleHttp\Client(['timeout' => 20, 'http_errors' => false]);
            $response = $client->request('GET', $get, ['stream' => true, 'headers' => [
                'User-Agent' => 'MailHub calendar subscriptions', 'Accept' => 'text/calendar, */*;q=0.5']]);
        } catch (Exception $e) {
            return $this->gettext('unreachable');
        }
        $code = $response->getStatusCode();
        if ($code == 401 || $code == 403 || $code == 404 || $code == 410) {
            return $this->gettext('subsgone') . " (HTTP $code)";
        }
        $head = $code == 200 ? (string) $response->getBody()->read(8192) : '';
        $response->getBody()->close();
        if (stripos($head, 'BEGIN:VCALENDAR') === false) {
            return $this->gettext('subsnotical') . ($code != 200 ? " (HTTP $code)" : '');
        }
        $name = preg_match('/^X-WR-CALNAME:(.+)$/mi', $head, $m) ? trim($m[1]) : '';

        return ['url' => $url, 'name' => $name];
    }

    /**
     * A CalDAV address: the calendar itself, or an account whose calendars are listed
     * (one: taken; several: the user is asked to pick one). [url, name] or a reason.
     */
    private function resolve_caldav($url, $user, $pass)
    {
        $props = '<d:resourcetype/><d:displayname/><d:current-user-principal/><c:calendar-home-set/><c:supported-calendar-component-set/>';
        $find = function ($href, $depth) use ($user, $pass, $props, $url) {
            $abs = preg_match('#^https?://#i', $href) ? $href : preg_replace('#^(https?://[^/]+).*$#i', '$1', $url) . $href;
            try {
                $client = new GuzzleHttp\Client(['timeout' => 20, 'http_errors' => false]);
                $response = $client->request('PROPFIND', $abs, [
                    'auth'    => [$user, $pass],
                    'headers' => ['Depth' => (string) $depth, 'Content-Type' => 'application/xml; charset=utf-8'],
                    'body'    => '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" '
                        . 'xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>' . $props . '</d:prop></d:propfind>',
                ]);
            } catch (Exception $e) {
                return $this->gettext('unreachable');
            }
            $code = $response->getStatusCode();
            if ($code == 401 || $code == 403) {
                return $this->gettext('badlogin');
            }
            $doc = new DOMDocument();
            if ($code != 207 || !@$doc->loadXML((string) $response->getBody())) {
                return $this->gettext('subsnotcaldav') . " (HTTP $code)";
            }
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('d', 'DAV:');
            $xp->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            $out = [];
            foreach ($xp->query('//d:response') as $r) {
                $h = $xp->evaluate('string(d:href)', $r);
                $out[] = [
                    'url'       => preg_match('#^https?://#i', $h) ? $h : preg_replace('#^(https?://[^/]+).*$#i', '$1', $abs) . $h,
                    'href'      => $h,
                    'name'      => $xp->evaluate('string(.//d:displayname)', $r),
                    'calendar'  => $xp->query('.//d:resourcetype/c:calendar', $r)->length > 0,
                    'events'    => $xp->query('.//c:supported-calendar-component-set/c:comp[@name="VEVENT"]', $r)->length > 0
                        || $xp->query('.//c:supported-calendar-component-set', $r)->length == 0,
                    'principal' => $xp->evaluate('string(.//d:current-user-principal/d:href)', $r),
                    'home'      => $xp->evaluate('string(.//c:calendar-home-set/d:href)', $r),
                ];
            }

            return $out;
        };

        $self = $find($url, 0);
        if (!is_array($self)) {
            return $self;
        }
        $self = $self[0] ?? [];
        if (!empty($self['calendar'])) {
            return ['url' => $url, 'name' => $self['name']];
        }

        // an account: its principal, its calendar home, the calendars there
        $home = $self['home'] ?? '';
        if ($home === '' && !empty($self['principal'])) {
            $p = $find($self['principal'], 0);
            if (!is_array($p)) {
                return $p;
            }
            $home = $p[0]['home'] ?? '';
        }
        if ($home === '') {
            return $this->gettext('subsnotcaldav');
        }
        $list = $find($home, 1);
        if (!is_array($list)) {
            return $list;
        }
        $cals = array_values(array_filter($list, function ($c) { return $c['calendar'] && $c['events']; }));
        if (count($cals) == 1) {
            return ['url' => $cals[0]['url'], 'name' => $cals[0]['name']];
        }
        if (!$cals) {
            return $this->gettext('subsnocalendar');
        }
        $names = array_map(function ($c) { return ($c['name'] ?: basename(rtrim($c['href'], '/'))) . ': ' . $c['url']; }, $cals);

        return $this->gettext('subspick') . ' ' . implode(' — ', $names);
    }

    /** `<accounts dir>/<user>/`, where the mirror reads this user's files. */
    private function accounts_dir()
    {
        $base = rtrim(rcmail::get_instance()->config->get('mailhub_accounts_dir', '/var/mailhub/accounts'), '/');
        $dir = $base . '/' . self::user_dir_name();
        if (!is_dir($dir) && is_writable($base)) {
            @mkdir($dir, 0700);
        }

        return $dir;
    }

    private function state_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_mirror_state_dir', '/var/mailhub/mirror'), '/')
            . '/' . self::user_dir_name();
    }

    private static function user_dir_name()
    {
        $user = strtolower((string) ($_SESSION['username'] ?? ''));

        return preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', $user) ? $user : '_invalid';
    }

    /** The mirror's file (private: secret addresses and passwords in the clear). */
    private function write_subs(array $subs)
    {
        $rcmail = rcmail::get_instance();
        $dir = $this->accounts_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $list = [];
        foreach ($subs as $id => $s) {
            $list[] = [
                'id'    => (string) $id,
                'name'  => $s['name'],
                'color' => $s['color'],
                'url'   => (string) $rcmail->decrypt($s['url']),
                'user'  => (string) ($s['user'] ?? ''),
                'pass'  => !empty($s['pass']) ? (string) $rcmail->decrypt($s['pass']) : '',
            ];
        }
        $old = umask(0077);
        $tmp = "$dir/.calendars.json.tmp";
        $ok = file_put_contents($tmp, json_encode(['subscriptions' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") !== false
            && rename($tmp, "$dir/.calendars.json");
        umask($old);
        @touch("$dir/.calendars-sync-now");

        return $ok;
    }

    /** The mirror's file written again if it went missing (a restored or moved install). */
    private function backfill_subs()
    {
        if (empty($_SESSION['username']) || !($subs = $this->subs())) {
            return;
        }
        if (!is_file($this->accounts_dir() . '/.calendars.json')) {
            $this->write_subs($subs);
        }
    }

    public function prefs_save($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        $args = $this->save_server($args);
        if (!empty($args['abort'])) {
            return $args;
        }

        $subs = $this->subs_from_post();
        if (!is_array($subs)) {
            return $this->refuse($args, $subs);
        }
        if ($subs != $this->subs() || !is_file($this->accounts_dir() . '/.calendars.json')) {
            if (!$this->write_subs($subs)) {
                return $this->refuse($args, $this->gettext('subsnotsaved'));
            }
        } else {
            @touch($this->accounts_dir() . '/.calendars-sync-now');  // saving also syncs them now
        }
        $args['prefs'][self::SUBS] = $subs;

        return $args;
    }

    private function save_server($args)
    {
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
