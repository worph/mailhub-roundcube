<?php

/**
 * MailHub: the server's sign-in is the only login.
 *
 * MailHub sits behind AppShield, which signs who is asking on every request
 * (`X-AppShield-Assertion`: an HS256 JWT carrying the identity's `sub`, user name,
 * email; shared secret MAILHUB_ASSERTION_SECRET). This plugin:
 *
 *   - logs that identity into Roundcube with no form: each identity has its own
 *     mailbox, a local user name chosen once (the first identity gets `admin`, the
 *     mailbox that existed before; the next ones their user name). Roundcube reaches
 *     Dovecot through its master login (SASL PLAIN, authzid = that user), so no user
 *     password is ever asked or stored;
 *   - keeps each session bound to the identity that opened it: another `sub` (a
 *     different person signed in on the same browser) or none ends it;
 *   - never shows Roundcube's login form: without a valid assertion, a page that
 *     says to go through the server's sign-in; logging out signs out of it too;
 *   - keeps the registry of users (`<users dir>/<name>.json`) that mailhub-mirror
 *     reads to give each one a mailbox, device login and calendar;
 *   - shows Settings → "Phones & computers": the IMAPS, SMTP (mailhub-submit: every
 *     address through its own provider) and CalDAV/CardDAV login of
 *     this mailbox (the owner's is the app's MAILHUB_PASSWORD secret; the others
 *     a generated device password that can be replaced here).
 *
 * The plain `Remote-User` header is never trusted: the other containers on the
 * app's network could send one.
 */
class mailhub_sso extends rcube_plugin
{
    private const OWNER = 'admin';
    private const MASTER_NAME = 'mailhub-webmail';
    private const DAV_REALM = 'BaikalDAV';
    private const ACTION = 'plugin.mailhub_devices';

    /** @var array|null the identity being logged in by this request */
    private $pending;

    /** @var array the query to land on once logged in */
    private $return = [];

    public function init()
    {
        $rcmail = rcmail::get_instance();

        // Every IMAP connection, at login and on each request after it.
        if ($master = getenv('MAILHUB_MASTER_PASSWORD')) {
            $rcmail->config->set('imap_auth_type', 'PLAIN');
            $rcmail->config->set('imap_auth_cid', self::MASTER_NAME);
            $rcmail->config->set('imap_auth_pw', $master);
        }

        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('logout_after', [$this, 'logout_after']);
        $this->add_hook('unauthenticated', [$this, 'unauthenticated']);
        $this->add_hook('user_create', [$this, 'user_create']);
        // The account name skins show (Outlook: atop the folders; Elastic: the
        // folder list's title), Roundcube's default sender address otherwise.
        $this->add_hook('template_object_username', [$this, 'username_object']);

        $this->add_texts('localization/', false);
        if ($rcmail->task == 'settings') {
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action(self::ACTION, [$this, 'devices_page']);
            $this->register_action(self::ACTION . '-do', [$this, 'devices_do']);
            $this->include_stylesheet('mailhub_sso.css');
        }
    }

    // ------------------------------------------------------------- login ---

    public function startup($args)
    {
        $rcmail = rcmail::get_instance();
        $id = self::identity();

        if (!empty($_SESSION['user_id'])) {
            if ($id && hash_equals((string) ($_SESSION['mailhub_sub'] ?? ''), $id['sub'])) {
                return $args;
            }
            // Someone else (or no one) behind the same session cookie.
            $rcmail->kill_session();
        }

        if ($args['task'] == 'logout') {
            self::server_logout();
        }
        if (!$id) {
            self::refuse();
        }
        // An AJAX call or a frame with no session: Roundcube answers "session
        // error" and reloads the page, whose request then logs in.
        if ($rcmail->output->ajax_call || !empty($_REQUEST['_framed'])) {
            return $args;
        }

        $this->pending = $id;
        $this->return = array_diff_key($_GET, ['_err' => 1, '_token' => 1, '_task' => 1, '_action' => 1]);
        if (!in_array($args['task'], ['login', 'logout', ''], true)) {
            $this->return['_task'] = $args['task'];
            if ($args['action'] !== '') {
                $this->return['_action'] = $args['action'];
            }
        }
        $args['task'] = 'login';
        $args['action'] = 'login';

        return $args;
    }

    public function authenticate($args)
    {
        if (!$this->pending) {
            // Not ours: Roundcube's own form is never accepted.
            $args['abort'] = true;
            $args['valid'] = false;
            return $args;
        }

        $user = self::claim($this->pending);
        if ($user === null) {
            $args['abort'] = true;
            $args['error'] = 'storageerror';
            return $args;
        }
        $args['user'] = $user['local'];
        // Unused: the master login replaces it (imap_auth_cid / imap_auth_pw).
        $args['pass'] = 'master';
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    public function login_after($args)
    {
        if ($this->pending) {
            $_SESSION['mailhub_sub'] = $this->pending['sub'];
            $_SESSION['mailhub_user'] = $_SESSION['username'];
            self::repair_default_identity();
            return array_merge($args, $this->return);
        }

        return $args;
    }

    /**
     * The server account signed in (AppShield's user name), shown where skins
     * show the account: it is who you are here, one MailHub mailbox per server
     * account. The addresses you send from are the identities.
     */
    public function username_object($args)
    {
        $id = self::identity();
        $local = (string) ($_SESSION['username'] ?? '');
        $name = $id ? ($id['user'] !== '' ? $id['user'] : ($id['email'] !== '' ? $id['email'] : $local)) : $local;
        if ($name !== '') {
            $args['content'] = html::span(['title' => $this->gettext(['name' => 'signedinas',
                'vars' => ['name' => $name, 'mailbox' => $local]])], rcube::Q($name));
        }

        return $args;
    }

    /**
     * Roundcube's first identity, `<user>@<IMAP host>`, is not an address: say so
     * until the first mail account replaces it (mailhub_accounts).
     */
    public function user_create($args)
    {
        $args['user_name'] = $this->gettext('placeholdername');

        return $args;
    }

    /**
     * The default sender must be one of the mailbox's mail accounts once it has
     * one: an identity without its account (its account removed, or Roundcube's
     * placeholder) has no SMTP and cannot send. Before 0.5.0 removing the default
     * account left its identity the default.
     */
    private static function repair_default_identity()
    {
        $rcmail = rcmail::get_instance();
        $accounts = (array) $rcmail->config->get('mailhub_accounts', []);
        $ids = array_values(array_filter(array_map(function ($a) {
            return (int) ($a['identity_id'] ?? 0);
        }, $accounts)));
        $default = $rcmail->user->get_identity();
        if ($ids && $default && !in_array((int) $default['identity_id'], $ids, true)) {
            $rcmail->user->set_default($ids[0]);
        }
    }

    public function logout_after($args)
    {
        self::server_logout();
    }

    /**
     * Roundcube would show its login form: the identity could not be logged in
     * (its user file could not be written, Dovecot refused the master login…).
     */
    public function unauthenticated($args)
    {
        rcube::write_log('errors', 'mailhub_sso: could not open the mailbox' . (self::identity() ? ' of ' . self::identity()['sub'] : ''));
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>MailHub</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#333}'
            . '@media(prefers-color-scheme:dark){body{background:#1b1b1b;color:#ddd}a{color:#8ab4f8}}</style></head><body>'
            . '<h1>MailHub</h1><p>Your mailbox could not be opened just now. <a href="./">Try again</a> in a moment; '
            . 'if it keeps failing, the app\'s logs say why.</p></body></html>';
        exit;
    }

    /** Sign out of the server too, or the next request would log straight back in. */
    private static function server_logout()
    {
        header('Location: /nhl-auth/logout', true, 302);
        exit;
    }

    /** No valid identity: never Roundcube's login form. */
    private static function refuse()
    {
        $from = rcube_utils::remote_addr();
        if (!in_array($from, ['127.0.0.1', '::1'], true)) {  // the healthcheck
            rcube::write_log('errors', "mailhub_sso: request without a valid AppShield assertion from $from");
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>MailHub</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#333}'
            . '@media(prefers-color-scheme:dark){body{background:#1b1b1b;color:#ddd}a{color:#8ab4f8}}</style></head><body>'
            . '<h1>MailHub</h1><p>MailHub opens through your server\'s sign-in. '
            . '<a href="/">Sign in</a> and it opens on its own — there is no separate mailbox login.</p></body></html>';
        exit;
    }

    // ---------------------------------------------------------- identity ---

    /**
     * The identity AppShield signed for this request, or null. HS256 only, the
     * signature compared in constant time, issuer, audience and expiry checked;
     * an OAuth caller (an app acting for a user) is not a person and opens no mailbox.
     */
    public static function identity()
    {
        static $id = false;
        if ($id !== false) {
            return $id;
        }
        $id = null;

        $secret = (string) getenv('MAILHUB_ASSERTION_SECRET');
        $token = (string) ($_SERVER['HTTP_X_APPSHIELD_ASSERTION'] ?? '');
        $parts = explode('.', $token);
        if ($secret === '' || count($parts) !== 3) {
            return $id;
        }
        $header = json_decode((string) self::b64url($parts[0]), true);
        $claims = json_decode((string) self::b64url($parts[1]), true);
        $sig = self::b64url($parts[2]);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($claims) || $sig === null
            || !hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true), $sig)) {
            return $id;
        }
        $now = time();
        $aud = (array) ($claims['aud'] ?? []);
        $want = strtolower((string) (getenv('MAILHUB_ASSERTION_AUDIENCE') ?: 'mailhub'));
        if (($claims['iss'] ?? '') !== 'appshield' || !in_array($want, array_map('strtolower', $aud), true)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] < $now - 5
            || (is_int($claims['iat'] ?? null) && $claims['iat'] > $now + 30)
            || ($claims['method'] ?? '') === 'oauth'
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            return $id;
        }

        return $id = [
            'sub'   => $claims['sub'],
            'user'  => is_string($claims['user'] ?? null) ? $claims['user'] : '',
            'email' => is_string($claims['email'] ?? null) ? $claims['email'] : '',
            'name'  => is_string($claims['name'] ?? null) ? $claims['name'] : '',
        ];
    }

    private static function b64url($s)
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return $d === false ? null : $d;
    }

    // ---------------------------------------------------------- registry ---

    private static function users_dir()
    {
        return rtrim((string) rcmail::get_instance()->config->get('mailhub_users_dir', '/var/mailhub/users'), '/');
    }

    /** The registry entry of a local user, or null. */
    public static function user($local)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', (string) $local)) {
            return null;
        }
        $file = self::users_dir() . "/$local.json";
        $u = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($u) ? $u : null;
    }

    /**
     * The local user of this identity, created on its first visit: the first identity
     * ever gets the owner's mailbox (`admin`), the next ones a name of their own and a
     * device password. Returns the registry entry, or null when it cannot be written.
     */
    private static function claim(array $id)
    {
        $dir = self::users_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            rcube::raise_error(['code' => 500, 'message' => "mailhub_sso: $dir is not writable"], true, false);
            return null;
        }
        $lock = fopen("$dir/.lock", 'c');
        flock($lock, LOCK_EX);
        try {
            $found = null;
            $taken = [];
            foreach (glob("$dir/*.json") ?: [] as $f) {
                $u = json_decode((string) file_get_contents($f), true);
                $taken[basename($f, '.json')] = true;
                if (is_array($u) && hash_equals((string) ($u['sub'] ?? ''), $id['sub'])) {
                    $found = $u;
                }
            }

            if ($found) {
                // Name and address follow the identity provider.
                if (($found['name'] ?? '') !== $id['name'] || ($found['email'] ?? '') !== $id['email']
                    || ($found['user'] ?? '') !== $id['user']) {
                    $found['user'] = $id['user'];
                    $found['name'] = $id['name'];
                    $found['email'] = $id['email'];
                    self::save($found);
                }
                return $found;
            }

            $local = empty($taken[self::OWNER]) ? self::OWNER : self::free_name($id, $taken);
            $u = [
                'local'   => $local,
                'sub'     => $id['sub'],
                'user'    => $id['user'],
                'name'    => $id['name'],
                'email'   => $id['email'],
                'created' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            if ($local !== self::OWNER) {
                $u = self::with_device_password($u, self::new_password());
            }
            if (!self::save($u)) {
                return null;
            }
            rcube::write_log('userlogins', "mailhub_sso: new mailbox $local for {$id['sub']}");

            return $u;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A local name from the identity's user name (or address), unique and not the owner's. */
    private static function free_name(array $id, array $taken)
    {
        $base = $id['user'] !== '' ? $id['user'] : strstr($id['email'] . '@', '@', true);
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower((string) $base));
        $base = substr(ltrim($base, '._-'), 0, 28) ?: 'user';
        $name = $base;
        for ($n = 2; isset($taken[$name]) || $name === self::OWNER; $n++) {
            $name = "$base-$n";
        }

        return $name;
    }

    private static function save(array $u)
    {
        $dir = self::users_dir();
        $tmp = "$dir/.{$u['local']}.tmp";
        $old = umask(0077);
        $ok = file_put_contents($tmp, json_encode($u, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false
            && rename($tmp, "$dir/{$u['local']}.json");
        umask($old);

        return $ok;
    }

    private static function new_password()
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 24; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $p;
    }

    /**
     * Its hash for Dovecot's passwd-file ({SSHA512}) and Baïkal's digest, both
     * applied by mailhub-mirror, and the password itself encrypted with Roundcube's
     * key, for this page and for the webmail's own calendar connection.
     */
    private static function with_device_password(array $u, $pass)
    {
        $salt = random_bytes(8);
        $u['device_hash'] = '{SSHA512}' . base64_encode(hash('sha512', $pass . $salt, true) . $salt);
        $u['dav_digest'] = md5("{$u['local']}:" . self::DAV_REALM . ":$pass");
        $u['device_pass'] = rcmail::get_instance()->encrypt($pass);
        $u['device_changed'] = gmdate('Y-m-d\TH:i:s\Z');

        return $u;
    }

    /**
     * The device login of a local user (IMAPS, CalDAV/CardDAV): [user, password].
     * The owner's is the app's MAILHUB_PASSWORD secret.
     */
    public static function device_login($local)
    {
        if ($local === self::OWNER) {
            return [$local, (string) getenv('MAILHUB_PASSWORD')];
        }
        $u = self::user($local);
        $pass = $u && !empty($u['device_pass']) ? rcmail::get_instance()->decrypt($u['device_pass']) : '';

        return [$local, (string) $pass];
    }

    /**
     * Whether the device login's current password is in effect at Baïkal yet:
     * mailhub-mirror applies it a few seconds after it is created or replaced, and
     * says so in `<mirror state>/<user>/device-applied`.
     */
    public static function dav_ready($local)
    {
        if ($local === self::OWNER) {
            return true;
        }
        $u = self::user($local);
        $state = rtrim((string) rcmail::get_instance()->config->get('mailhub_mirror_state_dir', '/var/mailhub/mirror'), '/');
        $file = "$state/$local/device-applied";

        return $u && !empty($u['dav_digest']) && is_readable($file)
            && trim((string) file_get_contents($file)) === $u['dav_digest'];
    }

    // ------------------------------------------------------------ devices ---

    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => self::ACTION,
            'class'  => 'mailhub-devices',
            'label'  => 'section',
            'title'  => 'sectiontitle',
            'domain' => 'mailhub_sso',
        ];

        return $args;
    }

    public function devices_page()
    {
        $rcmail = rcmail::get_instance();
        $this->register_handler('plugin.body', [$this, 'devices_body']);
        $rcmail->output->set_pagetitle($this->gettext('section'));
        $rcmail->output->send('plugin');
    }

    public function devices_body()
    {
        $rcmail = rcmail::get_instance();
        $local = (string) ($_SESSION['username'] ?? '');
        [, $pass] = self::device_login($local);
        $owner = $local === self::OWNER;
        $imap = (string) getenv('MAILHUB_IMAPS_HOST');
        $dav = (string) getenv('MAILHUB_DAV_URL');

        $row = function ($label, $value, $code = true) {
            return html::tag('tr', [],
                html::tag('th', [], rcube::Q($this->gettext($label)))
                . html::tag('td', [], $code ? html::tag('code', [], rcube::Q($value)) : $value));
        };
        $secret = $pass === ''
            ? rcube::Q($this->gettext('nopass'))
            : html::tag('details', ['class' => 'mh-secret'],
                html::tag('summary', [], rcube::Q($this->gettext('show'))) . html::tag('code', [], rcube::Q($pass)));

        $table = html::tag('table', ['class' => 'mh-devices propform'],
            $row('imapserver', $imap !== '' ? "$imap, 993, SSL/TLS" : $this->gettext('nohost'), $imap !== '')
            . $row('username', $local)
            . $row('password', $secret, false)
            . ($dav !== '' ? $row('davurl', $dav) : '')
            . $row('smtp', $imap !== '' ? "$imap, 465, SSL/TLS" : $this->gettext('nohost'), $imap !== '')
            . ($imap !== '' ? $row('smtpalt', "$imap, 587, STARTTLS") : ''));
        $table .= html::p(['class' => 'mh-hint'], rcube::Q($this->gettext('smtphint')));

        if ($owner) {
            $change = html::p(['class' => 'mh-hint'], rcube::Q($this->gettext('ownerpass')));
        } else {
            $form = html::tag('form', ['method' => 'post', 'action' => $rcmail->url(['action' => self::ACTION . '-do'])],
                html::tag('input', ['type' => 'hidden', 'name' => '_token', 'value' => $rcmail->get_request_token()])
                . html::tag('button', ['type' => 'submit', 'class' => 'button'], rcube::Q($this->gettext('regenerate'))));
            $change = html::p(['class' => 'mh-hint'], rcube::Q($this->gettext('regeneratehint'))) . $form;
        }

        return html::div(['class' => 'mh-page formcontent'],
            html::tag('h2', [], rcube::Q($this->gettext('section')))
            . html::p([], rcube::Q($this->gettext('intro')))
            . $table . $change);
    }

    public function devices_do()
    {
        $rcmail = rcmail::get_instance();
        $local = (string) ($_SESSION['username'] ?? '');
        if (!$rcmail->check_request(rcube_utils::INPUT_POST) || $local === self::OWNER || !($u = self::user($local))) {
            $rcmail->output->show_message('errorsaving', 'error');
        } elseif (self::save(self::with_device_password($u, self::new_password()))) {
            $rcmail->output->show_message($this->gettext('regenerated'), 'confirmation', null, true);
        } else {
            $rcmail->output->show_message('errorsaving', 'error');
        }
        $rcmail->output->redirect(['action' => self::ACTION]);
    }
}
