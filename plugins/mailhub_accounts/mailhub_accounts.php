<?php

/**
 * MailHub: the mail accounts MailHub mirrors, managed in Settings → "Mail accounts".
 *
 * One account is two things, written together after testing both logins:
 *   - its mirror: an account file (`<accounts dir>/<slug>.json`) that the
 *     mailhub-mirror container picks up within seconds. It keeps a local copy of the
 *     whole mailbox under Live/<address>/ (IMAP, pull only) and replays what is done
 *     there at the provider, which stays the source of truth;
 *   - sending as that address: a Roundcube identity with the provider's SMTP,
 *     stored the way the identity_smtp plugin reads it.
 *
 * Accounts are kept in the user's prefs (password encrypted with Roundcube's DES
 * key) and the account files are rendered from them. Account files this plugin did
 * not write are listed read-only, with their status.
 */
class mailhub_accounts extends rcube_plugin
{
    public $task = 'settings';

    private const PREF = 'mailhub_accounts';
    private const SECTION = 'mailhub_accounts';

    /** Provider presets: IMAP over SSL, SMTP as a Roundcube host URI. */
    private const PRESETS = [
        'gmail' => [
            'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_security' => 'ssl',
            'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_security' => 'ssl',
        ],
        'privateemail' => [
            'imap_host' => 'mail.privateemail.com', 'imap_port' => 993, 'imap_security' => 'ssl',
            'smtp_host' => 'mail.privateemail.com', 'smtp_port' => 465, 'smtp_security' => 'ssl',
        ],
    ];

    public function init()
    {
        $this->add_texts('localization/', false);

        $this->add_hook('preferences_sections_list', [$this, 'sections']);
        $this->add_hook('preferences_list', [$this, 'prefs_form']);
        $this->add_hook('preferences_save', [$this, 'prefs_save']);
    }

    public function sections($args)
    {
        $args['list'][self::SECTION] = [
            'id'      => self::SECTION,
            'section' => $this->gettext('section'),
        ];

        return $args;
    }

    // ---------------------------------------------------------------- form ---

    public function prefs_form($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $accounts = (array) $rcmail->config->get(self::PREF, []);

        if (!is_writable($this->accounts_dir())) {
            $args['blocks']['broken'] = [
                'name'    => rcube::Q($this->gettext('section')),
                'options' => [['title' => '', 'content' => rcube::Q($this->gettext('dirnotwritable'))]],
            ];
            return $args;
        }

        foreach ($accounts as $slug => $a) {
            $p = '_acc[' . $slug . ']';
            $id = 'mh_' . preg_replace('/[^a-z0-9]/', '_', $slug);
            $name = new html_inputfield(['name' => $p . '[name]', 'id' => $id . '_name', 'size' => 40]);
            $pass = new html_passwordfield(['name' => $p . '[pass]', 'id' => $id . '_pass', 'size' => 40,
                'autocomplete' => 'new-password', 'placeholder' => $this->gettext('passunchanged')]);
            $remove = new html_checkbox(['name' => $p . '[remove]', 'id' => $id . '_remove', 'value' => 1]);

            $args['blocks']['acc_' . $id] = [
                'name'    => rcube::Q($a['email']),
                'options' => [
                    'status'  => ['title' => rcube::Q($this->gettext('status')), 'content' => rcube::Q($this->status_text($slug))],
                    'servers' => ['title' => rcube::Q($this->gettext('servers')), 'content' => rcube::Q(
                        $this->gettext('receive') . ' ' . $a['imap_host'] . ':' . $a['imap_port']
                        . ' · ' . $this->gettext('send') . ' ' . $a['smtp_host'] . ':' . $a['smtp_port'])],
                    'name'    => ['title' => html::label($id . '_name', rcube::Q($this->gettext('name'))), 'content' => $name->show($a['name'] ?? '')],
                    'pass'    => ['title' => html::label($id . '_pass', rcube::Q($this->gettext('newpass'))), 'content' => $pass->show()],
                    'remove'  => ['title' => html::label($id . '_remove', rcube::Q($this->gettext('remove'))),
                        'content' => $remove->show('') . ' ' . html::span('hint', rcube::Q($this->gettext('removehint')))],
                ],
            ];
        }

        // Accounts saved before the mirror (getmail) get their mirror file here.
        foreach ($accounts as $slug => $a) {
            if (!is_file($this->accounts_dir() . "/$slug.json")) {
                $this->write_account($slug, $a, (string) $rcmail->decrypt($a['pass']));
                @touch($this->accounts_dir() . '/.sync-now');
            }
        }

        $others = array_diff($this->files_on_disk(), array_keys($accounts));
        if ($others) {
            $rows = [];
            foreach ($others as $slug) {
                $rows[$slug] = ['title' => rcube::Q($slug), 'content' => rcube::Q($this->status_text($slug))];
            }
            $rows['help'] = ['title' => '', 'content' => rcube::Q($this->gettext('otherfileshelp'))];
            $args['blocks']['otherfiles'] = ['name' => rcube::Q($this->gettext('otherfiles')), 'options' => $rows];
        }

        $args['blocks']['add'] = $this->add_block(empty($accounts) && empty($others));

        return $args;
    }

    private function add_block($first)
    {
        $preset = new html_select(['name' => '_new[preset]', 'id' => 'mh_preset']);
        $preset->add($this->gettext('preset_gmail'), 'gmail');
        $preset->add($this->gettext('preset_privateemail'), 'privateemail');
        $preset->add($this->gettext('preset_other'), 'other');

        $field = function ($key, $attrs = []) {
            return new html_inputfield($attrs + ['name' => "_new[$key]", 'id' => "mh_new_$key", 'size' => 40]);
        };
        $security = function ($key, $choices) {
            $s = new html_select(['name' => "_new[$key]", 'id' => "mh_new_$key", 'class' => 'mh-other']);
            foreach ($choices as $c) {
                $s->add($this->gettext('sec_' . $c), $c);
            }
            return $s;
        };
        $label = function ($key, $text) {
            return html::label("mh_new_$key", rcube::Q($this->gettext($text)));
        };
        $pass = new html_passwordfield(['name' => '_new[pass]', 'id' => 'mh_new_pass', 'size' => 40, 'autocomplete' => 'new-password']);

        $other = ['class' => 'mh-other'];
        $options = [
            'intro'    => ['title' => '', 'content' => rcube::Q($this->gettext($first ? 'introfirst' : 'intro'))],
            'preset'   => ['title' => html::label('mh_preset', rcube::Q($this->gettext('provider'))), 'content' => $preset->show('gmail')],
            'email'    => ['title' => $label('email', 'email'), 'content' => $field('email', ['placeholder' => 'you@example.com'])->show()],
            'name'     => ['title' => $label('name', 'name'), 'content' => $field('name')->show()],
            'pass'     => ['title' => $label('pass', 'apppass'), 'content' => $pass->show()],
            'passhelp' => ['title' => '', 'content' => html::span(['id' => 'mh_passhelp'], rcube::Q($this->gettext('passhelp_gmail')))],
            'user'     => ['title' => $label('user', 'user'), 'content' => $field('user', $other + ['placeholder' => $this->gettext('userplaceholder')])->show()],
            'imap_host' => ['title' => $label('imap_host', 'imap_host'), 'content' => $field('imap_host', $other + ['placeholder' => 'imap.example.com'])->show()],
            'imap_port' => ['title' => $label('imap_port', 'imap_port'), 'content' => $field('imap_port', $other + ['size' => 6])->show('993')
                . ' ' . $security('imap_security', ['ssl', 'none'])->show('ssl')],
            'smtp_host' => ['title' => $label('smtp_host', 'smtp_host'), 'content' => $field('smtp_host', $other + ['placeholder' => 'smtp.example.com'])->show()],
            'smtp_port' => ['title' => $label('smtp_port', 'smtp_port'), 'content' => $field('smtp_port', $other + ['size' => 6])->show('465')
                . ' ' . $security('smtp_security', ['ssl', 'tls', 'none'])->show('ssl')],
        ];

        // Show the server fields only for "Other provider", and the matching
        // app-password help for each preset.
        $checking = json_encode($this->gettext('checking'));
        $help = json_encode([
            'gmail' => $this->gettext('passhelp_gmail'),
            'privateemail' => $this->gettext('passhelp_privateemail'),
            'other' => $this->gettext('passhelp_other'),
        ]);
        rcmail::get_instance()->output->add_script(
            "var mh_help = $help, mh_toggle = function() {"
            . " var p = $('#mh_preset').val();"
            . " $('.mh-other').closest('tr, .form-group, .row').toggle(p == 'other');"
            . " $('#mh_passhelp').text(mh_help[p]); };"
            . " $('#mh_preset').on('change', mh_toggle); mh_toggle();"
            // Saving a new account (or a new password) logs in to the provider's
            // IMAP and SMTP first, which takes a few seconds: say so, and keep the
            // Save button from being pressed twice meanwhile.
            . " rcmail.addEventListener('beforesave', function() {"
            . "   var testing = $.trim($('#mh_new_email').val()) !== ''"
            . "     || $('input[type=password]').filter(function() { return this.value !== ''; }).length > 0;"
            . "   rcmail.display_message(testing ? $checking : rcmail.get_label('saving'), 'loading', 60000);"
            . "   $('button.mainaction, .formbuttons button').prop('disabled', true);"
            . " });",
            'docready'
        );

        return ['name' => rcube::Q($this->gettext('add')), 'options' => $options];
    }

    // ---------------------------------------------------------------- save ---

    public function prefs_save($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $accounts = (array) $rcmail->config->get(self::PREF, []);
        $smtp = (array) $rcmail->config->get('identity_smtp', []);
        $posted = (array) rcube_utils::get_input_value('_acc', rcube_utils::INPUT_POST, true);
        $new = (array) rcube_utils::get_input_value('_new', rcube_utils::INPUT_POST, true);

        if (!is_writable($this->accounts_dir())) {
            return $this->refuse($args, $this->gettext('dirnotwritable'));
        }

        // 1. Work out every change and test new logins, before writing anything.
        $removed = [];
        $touched = [];  // slug => plain password (null = unchanged)
        foreach ($accounts as $slug => $a) {
            $in = $posted[$slug] ?? null;
            if (!is_array($in)) {
                continue;
            }
            if (!empty($in['remove'])) {
                $removed[] = $slug;
                continue;
            }
            $accounts[$slug]['name'] = trim((string) ($in['name'] ?? ''));
            $pass = self::clean_pass((string) ($in['pass'] ?? ''), $a['preset'] ?? 'other');
            if ($pass !== '') {
                if ($error = $this->test_account($accounts[$slug], $pass)) {
                    return $this->refuse($args, $a['email'] . ': ' . $error);
                }
                $accounts[$slug]['pass'] = $rcmail->encrypt($pass);
            }
            $touched[$slug] = $pass !== '' ? $pass : null;
        }

        if (trim((string) ($new['email'] ?? '')) !== '') {
            $account = $this->new_account($new, $error);
            if (!$account) {
                return $this->refuse($args, $error);
            }
            $slug = self::slug($account['email']);
            if (isset($accounts[$slug]) && !in_array($slug, $removed)) {
                return $this->refuse($args, $this->gettext('exists'));
            }
            $pass = $account['pass'];
            if ($error = $this->test_account($account, $pass)) {
                return $this->refuse($args, $error);
            }
            $account['pass'] = $rcmail->encrypt($pass);
            $accounts[$slug] = $account;
            $removed = array_diff($removed, [$slug]);
            $touched[$slug] = $pass;
        }

        // 2. Identities with their SMTP (kept on removal: you can still reply
        //    from that address; delete the identity itself under Identities).
        foreach ($touched as $slug => $pass) {
            $a = $accounts[$slug];
            $iid = $this->ensure_identity($a['email'], $a['name']);
            if (!$iid) {
                return $this->refuse($args, $this->gettext('identityfailed'));
            }
            $accounts[$slug]['identity_id'] = $iid;
            $smtp[$iid] = [
                'smtp_standard' => false,
                'smtp_host'     => self::smtp_uri($a),
                'smtp_user'     => $a['user'],
                'smtp_pass'     => $pass !== null ? $rcmail->encrypt($pass) : ($smtp[$iid]['smtp_pass'] ?? $a['pass']),
            ];
        }

        // 3. Account files, then wake the mirror. A removed account's file goes:
        //    the mirror stops and deletes this server's copy (never the provider's).
        foreach ($removed as $slug) {
            unset($accounts[$slug]);
            @unlink($this->accounts_dir() . "/$slug.json");
            @unlink($this->accounts_dir() . "/$slug.getmailrc");  // from before the mirror
        }
        foreach ($accounts as $slug => $a) {
            if (!$this->write_account($slug, $a, (string) $rcmail->decrypt($a['pass']))) {
                return $this->refuse($args, $this->gettext('dirnotwritable'));
            }
        }
        @touch($this->accounts_dir() . '/.sync-now');

        $args['prefs'][self::PREF] = $accounts;
        $args['prefs']['identity_smtp'] = $smtp;

        return $args;
    }

    /**
     * Build an account from the "Add an account" fields. Returns null and sets
     * $error when something is missing or invalid.
     */
    private function new_account(array $in, &$error)
    {
        $preset = (string) ($in['preset'] ?? 'other');
        $email = strtolower(trim((string) $in['email']));
        $pass = self::clean_pass((string) ($in['pass'] ?? ''), $preset);

        if (!rcube_utils::check_email($email, false)) {
            $error = $this->gettext('bademail');
            return null;
        }
        if ($pass === '') {
            $error = $this->gettext('nopass');
            return null;
        }
        // The address names its folder tree, Live/<address>/: no slash or quote.
        if (preg_match('#[/"\\\\]#', $email)) {
            $error = $this->gettext('bademail');
            return null;
        }
        if (preg_match('/[\r\n]/', $pass)) {
            $error = $this->gettext('badpass');
            return null;
        }

        if (isset(self::PRESETS[$preset])) {
            $servers = self::PRESETS[$preset];
            $user = $email;
        } else {
            $preset = 'other';
            $servers = [
                'imap_host' => trim((string) ($in['imap_host'] ?? '')),
                'imap_port' => (int) ($in['imap_port'] ?? 0),
                'imap_security' => ($in['imap_security'] ?? '') === 'none' ? 'none' : 'ssl',
                'smtp_host' => trim((string) ($in['smtp_host'] ?? '')),
                'smtp_port' => (int) ($in['smtp_port'] ?? 0),
                'smtp_security' => in_array($in['smtp_security'] ?? '', ['tls', 'none']) ? $in['smtp_security'] : 'ssl',
            ];
            foreach (['imap', 'smtp'] as $k) {
                if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $servers[$k . '_host'])
                    || $servers[$k . '_port'] < 1 || $servers[$k . '_port'] > 65535) {
                    $error = $this->gettext('badserver');
                    return null;
                }
            }
            $user = trim((string) ($in['user'] ?? '')) ?: $email;
        }

        return $servers + [
            'preset' => $preset,
            'email'  => $email,
            'name'   => trim((string) ($in['name'] ?? '')),
            'user'   => $user,
            'pass'   => $pass,
        ];
    }

    private static function clean_pass($pass, $preset)
    {
        return $preset === 'gmail' ? str_replace(' ', '', $pass) : $pass;
    }

    private function refuse($args, $message)
    {
        $args['abort'] = true;
        $args['result'] = false;
        $args['message'] = $message;

        return $args;
    }

    // --------------------------------------------------------------- tests ---

    /**
     * Log in to the provider's IMAP and SMTP. Returns null when both accept the
     * login, a short reason otherwise.
     */
    private function test_account(array $a, $pass)
    {
        $imap = new rcube_imap_generic();
        $ok = $imap->connect($a['imap_host'], $a['user'], $pass, [
            'port'     => $a['imap_port'],
            'ssl_mode' => $a['imap_security'] === 'ssl' ? 'ssl' : null,
            'timeout'  => 15,
        ]);
        $imap_error = trim((string) $imap->error);
        $imap->closeConnection();
        if (!$ok) {
            if (preg_match('/could not connect|startup greeting|unable to negotiate/i', $imap_error)) {
                return $this->gettext('imapunreachable') . " ({$a['imap_host']}:{$a['imap_port']})";
            }
            return $this->gettext('imapbadlogin') . ($imap_error !== '' ? " ($imap_error)" : '');
        }

        $smtp = new rcube_smtp();
        $ok = $smtp->connect(self::smtp_uri($a), null, $a['user'], $pass);
        $smtp_error = $smtp->get_error();
        $smtp_response = trim(implode(' ', (array) $smtp->get_response()));
        $smtp->disconnect();
        if (!$ok) {
            $label = ($smtp_error['label'] ?? '') === 'smtpautherror' ? 'smtpbadlogin' : 'smtpunreachable';
            return $this->gettext($label) . " ({$a['smtp_host']}:{$a['smtp_port']})"
                . ($smtp_response !== '' ? ' — ' . mb_substr($smtp_response, 0, 200) : '');
        }

        return null;
    }

    /** SMTP server in the form Roundcube (and identity_smtp) expect. */
    private static function smtp_uri(array $a)
    {
        $scheme = ['ssl' => 'ssl://', 'tls' => 'tls://'][$a['smtp_security']] ?? '';

        return $scheme . $a['smtp_host'] . ':' . $a['smtp_port'];
    }

    // ---------------------------------------------------------- identities ---

    /**
     * The identity for this address: an existing one with the same email, or
     * Roundcube's placeholder (created at first login as <login>@<imap host>,
     * which is not a real address) turned into it, or a new one.
     */
    private function ensure_identity($email, $name)
    {
        $user = rcmail::get_instance()->user;
        $identities = $user->list_identities();

        foreach ($identities as $i) {
            if (strcasecmp($i['email'], $email) == 0) {
                if ($name !== '' && $name !== $i['name']) {
                    $user->update_identity($i['identity_id'], ['name' => $name]);
                }
                return (int) $i['identity_id'];
            }
        }

        foreach ($identities as $i) {
            if (!str_contains((string) substr(strrchr($i['email'], '@') ?: '', 1), '.')) {
                $user->update_identity($i['identity_id'], ['email' => $email, 'name' => $name, 'standard' => 1]);
                return (int) $i['identity_id'];
            }
        }

        return (int) $user->insert_identity(['email' => $email, 'name' => $name, 'standard' => 0]);
    }

    // -------------------------------------------------------------- mirror ---

    private function accounts_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_accounts_dir', '/var/mailhub/accounts'), '/');
    }

    private function state_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_mirror_state_dir', '/var/mailhub/mirror'), '/');
    }

    private static function slug($email)
    {
        return preg_replace('/[^a-z0-9._-]/', '_', str_replace('@', '_at_', strtolower($email)));
    }

    /** Account names of every account file in the folder. */
    private function files_on_disk()
    {
        $names = [];
        foreach (glob($this->accounts_dir() . '/*.json') ?: [] as $f) {
            $names[] = basename($f, '.json');
        }

        return $names;
    }

    /** The account file mailhub-mirror reads (private: it holds the password). */
    private function write_account($slug, array $a, $pass)
    {
        $data = [
            'email'         => $a['email'],
            'user'          => $a['user'],
            'pass'          => $pass,
            'imap_host'     => $a['imap_host'],
            'imap_port'     => (int) $a['imap_port'],
            'imap_security' => $a['imap_security'],
            'preset'        => $a['preset'] ?? 'other',
        ];
        $file = $this->accounts_dir() . "/$slug.json";
        $tmp = $this->accounts_dir() . "/.$slug.tmp";
        $old = umask(0077);
        $ok = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false
            && rename($tmp, $file);
        umask($old);

        return $ok;
    }

    /** One line about the account's mirror, from the status mailhub-mirror writes. */
    private function status_text($slug)
    {
        $file = $this->state_dir() . "/status/$slug.json";
        $s = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($s)) {
            return $this->gettext('waiting');
        }

        $when = rcmail::get_instance()->format_date($s['at'] ?? '');
        $last = trim((string) ($s['last'] ?? ''));
        if (!empty($s['running'])) {
            return $this->gettext(['name' => 'syncrunning', 'vars' => ['when' => $when, 'last' => $last]]);
        }
        if (!empty($s['ok'])) {
            return $this->gettext(['name' => 'fetchok', 'vars' => ['when' => $when, 'last' => $last]]);
        }
        if (preg_match('/AUTHENTICATIONFAILED|invalid credentials|authentication failed|login failed|application-specific password/i', $last)) {
            $last = $this->gettext('fetchbadlogin');
        }

        return $this->gettext(['name' => 'fetchfailed', 'vars' => ['when' => $when, 'last' => mb_substr($last, 0, 200)]]);
    }
}
