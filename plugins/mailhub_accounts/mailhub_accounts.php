<?php

/**
 * MailHub: the mail accounts MailHub fetches, managed in Settings → "Mail accounts".
 *
 * One account is two things, written together after testing both logins:
 *   - fetching: a getmail config file (`<accounts dir>/<slug>.getmailrc`), which the
 *     mailhub-fetch container picks up within seconds (it watches the folder);
 *   - sending as that address: a Roundcube identity with the provider's SMTP,
 *     stored the way the identity_smtp plugin reads it.
 *
 * Accounts are kept in the user's prefs (password encrypted with Roundcube's DES
 * key) and the getmail files are rendered from them. getmail files this plugin
 * did not write (hand-written ones) are listed read-only, with their status.
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
            $folder = new html_inputfield(['name' => $p . '[folder]', 'id' => $id . '_folder', 'size' => 20,
                'placeholder' => $this->gettext('folderinbox')]);
            $delete = new html_checkbox(['name' => $p . '[delete]', 'id' => $id . '_delete', 'value' => 1]);
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
                    'folder'  => ['title' => html::label($id . '_folder', rcube::Q($this->gettext('folder'))),
                        'content' => $folder->show($a['folder'] ?? '') . ' ' . html::span('hint', rcube::Q($this->gettext('folderhint')))],
                    'delete'  => ['title' => html::label($id . '_delete', rcube::Q($this->gettext('delete'))),
                        'content' => $delete->show(!empty($a['delete']) ? 1 : '') . ' ' . html::span('hint', rcube::Q($this->gettext('deletehint')))],
                    'remove'  => ['title' => html::label($id . '_remove', rcube::Q($this->gettext('remove'))),
                        'content' => $remove->show('') . ' ' . html::span('hint', rcube::Q($this->gettext('removehint')))],
                ],
            ];
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
        $delete = new html_checkbox(['name' => '_new[delete]', 'id' => 'mh_new_delete', 'value' => 1]);
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
            'folder'   => ['title' => $label('folder', 'folder'),
                'content' => $field('folder', ['size' => 20, 'placeholder' => $this->gettext('folderinbox')])->show()
                    . ' ' . html::span('hint', rcube::Q($this->gettext('folderhint')))],
            'delete'   => ['title' => $label('delete', 'delete'),
                'content' => $delete->show('') . ' ' . html::span('hint', rcube::Q($this->gettext('deletehint')))],
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
            // New accounts default to their own folder named after the address:
            // the Folder field follows the email as it is typed, until edited.
            . " var mh_folder_touched = false;"
            . " $('#mh_new_folder').on('input', function() { mh_folder_touched = true; });"
            . " $('#mh_new_email').on('input', function() {"
            . "   if (!mh_folder_touched) $('#mh_new_folder').val($.trim(this.value).toLowerCase()); });"
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
            $accounts[$slug]['delete'] = !empty($in['delete']);
            $folder = self::clean_folder((string) ($in['folder'] ?? ''));
            if ($folder === null) {
                return $this->refuse($args, $a['email'] . ': ' . $this->gettext('badfolder'));
            }
            $accounts[$slug]['folder'] = $folder;
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

        // 3. getmail files, then wake the fetcher.
        foreach ($removed as $slug) {
            unset($accounts[$slug]);
            @unlink($this->accounts_dir() . "/$slug.getmailrc");
        }
        foreach ($accounts as $slug => $a) {
            if (!$this->write_getmailrc($slug, $a, (string) $rcmail->decrypt($a['pass']))) {
                return $this->refuse($args, $this->gettext('dirnotwritable'));
            }
        }
        @touch($this->accounts_dir() . '/.fetch-now');

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
        // Its own folder named after the address by default (the form fills it in
        // as the address is typed); a Folder sent empty means the Inbox.
        $folder = self::clean_folder(array_key_exists('folder', $in) ? (string) $in['folder'] : $email);
        if ($folder === null) {
            $error = $this->gettext('badfolder');
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
            'delete' => !empty($in['delete']),
            'folder' => $folder,
        ];
    }

    /**
     * The folder an account's mail is delivered to: '' for the Inbox, else a
     * name Dovecot creates on first delivery — by default the address itself
     * (pierre.henri@aptero.co). It travels in the LMTP recipient's quoted local
     * part (see lmtp_recipient), which takes anything but a quote, a backslash or
     * a control character; "/" would nest folders, and the LMTP address is ASCII.
     * Null when invalid.
     */
    private static function clean_folder($folder)
    {
        $folder = trim($folder);
        if ($folder === '' || strcasecmp($folder, 'INBOX') == 0) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._@+-]{0,63}$/', $folder) ? $folder : null;
    }

    /**
     * The address getmail delivers an account's mail to over LMTP. With a folder
     * it is "admin+<folder>"@mailhub: Dovecot files mail for user+detail into the
     * detail's folder (lmtp_save_to_detail_mailbox), the quotes let the folder
     * carry an @, and the domain — which Dovecot drops (auth_username_format) —
     * is what the split happens on.
     */
    private static function lmtp_recipient($user, $folder)
    {
        return $folder === '' ? $user : '"' . $user . '+' . $folder . '"@mailhub';
    }

    /**
     * Gmail shows app passwords in groups of four ("abcd efgh ijkl mnop"); the
     * spaces are not part of them.
     */
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

    // ------------------------------------------------------------- getmail ---

    private function accounts_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_accounts_dir', '/var/mailhub/accounts'), '/');
    }

    private function state_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_fetch_state_dir', '/var/mailhub/fetch'), '/');
    }

    private static function slug($email)
    {
        return preg_replace('/[^a-z0-9._-]/', '_', str_replace('@', '_at_', strtolower($email)));
    }

    /** Account names of every getmail file in the folder. */
    private function files_on_disk()
    {
        $names = [];
        foreach (glob($this->accounts_dir() . '/*.getmailrc') ?: [] as $f) {
            $names[] = basename($f, '.getmailrc');
        }

        return $names;
    }

    private function write_getmailrc($slug, array $a, $pass)
    {
        $rcmail = rcmail::get_instance();
        $lmtp_user = $rcmail->config->get('mailhub_lmtp_user') ?: $rcmail->user->get_username();
        $lines = [
            '# Written by MailHub (Settings → Mail accounts). Changes made here are overwritten.',
            '[retriever]',
            'type = ' . ($a['imap_security'] === 'ssl' ? 'SimpleIMAPSSLRetriever' : 'SimpleIMAPRetriever'),
            'server = ' . $a['imap_host'],
            'port = ' . (int) $a['imap_port'],
            'username = ' . $a['user'],
            'password = ' . $pass,
            'mailboxes = ("INBOX",)',
            '',
            '[destination]',
            'type = MDA_lmtp',
            'host = ' . $rcmail->config->get('mailhub_lmtp_host', 'mailhub-imap'),
            'port = ' . (int) $rcmail->config->get('mailhub_lmtp_port', 2424),
            'override = ' . self::lmtp_recipient($lmtp_user, $a['folder'] ?? ''),
            '',
            '[options]',
            'read_all = false',
            'delete = ' . (!empty($a['delete']) ? 'true' : 'false'),
            'received = false',
            'delivered_to = false',
            '',
        ];

        $file = $this->accounts_dir() . "/$slug.getmailrc";
        $tmp = $this->accounts_dir() . "/.$slug.tmp";
        $old = umask(0077);
        $ok = file_put_contents($tmp, implode("\n", $lines)) !== false && rename($tmp, $file);
        umask($old);

        return $ok;
    }

    /** One line about the account's last fetch, from the status getmail writes. */
    private function status_text($slug)
    {
        $file = $this->state_dir() . "/status/$slug.json";
        $s = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($s)) {
            return $this->gettext('waiting');
        }

        $when = rcmail::get_instance()->format_date($s['at'] ?? '');
        $last = trim((string) ($s['last'] ?? ''));
        if (!empty($s['ok'])) {
            return $this->gettext(['name' => 'fetchok', 'vars' => ['when' => $when, 'last' => $last]]);
        }
        if (preg_match('/AUTHENTICATIONFAILED|invalid credentials|authentication failed|login failed|application-specific password/i', $last)) {
            $last = $this->gettext('fetchbadlogin');
        }

        return $this->gettext(['name' => 'fetchfailed', 'vars' => ['when' => $when, 'last' => mb_substr($last, 0, 200)]]);
    }
}
