<?php

/**
 * MailHub: the mail accounts MailHub mirrors, managed in Settings → "Mail accounts".
 *
 * One account is two things, written together after testing both logins:
 *   - its mirror: an account file (`<accounts dir>/<user>/<slug>.json`) that the
 *     mailhub-mirror container picks up within seconds. It keeps a local copy of the
 *     whole mailbox under Live/<address>/ (IMAP, pull only) and replays what is done
 *     there at the provider, which stays the source of truth;
 *   - sending as that address: a Roundcube identity with the provider's SMTP,
 *     stored the way the identity_smtp plugin reads it.
 *
 * An account can be kept out of Merged › All inboxes, Unread and Flagged
 * ("in_views": false in its file), e.g. an address that gets mostly spam: it is
 * still mirrored, and read in its own Live › <address> folders.
 *
 * Accounts are kept in the user's prefs (password encrypted with Roundcube's DES
 * key) and the account files are rendered from them. Account files this plugin did
 * not write (in this user's folder) are listed read-only, with their status. Each
 * MailHub user has their own folder (mailhub_sso: one mailbox per signed-in
 * identity), so no one sees or syncs another user's accounts.
 */
class mailhub_accounts extends rcube_plugin
{
    public $task = 'settings';

    private const PREF = 'mailhub_accounts';
    private const ACTION = 'plugin.mailhub_accounts';

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
        $this->add_texts('localization/', true);
        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action(self::ACTION, [$this, 'page']);
        $this->register_action(self::ACTION . '-do', [$this, 'do_action']);
        $this->register_action(self::ACTION . '-status', [$this, 'status_action']);
        // the Settings menu entry's icon, on every Settings page
        $this->include_stylesheet('mailhub_accounts.css');
    }

    /** "Mail accounts" in the Settings menu, right after Identities. */
    public function settings_actions($args)
    {
        $entry = [
            'action' => self::ACTION,
            'class'  => 'mailhub-accounts',
            'label'  => 'section',
            'title'  => 'sectiontitle',
            'domain' => 'mailhub_accounts',
        ];
        $pos = count($args['actions']);
        foreach ($args['actions'] as $i => $a) {
            if (($a['action'] ?? '') === 'identities') {
                $pos = $i + 1;
            }
        }
        array_splice($args['actions'], $pos, 0, [$entry]);

        return $args;
    }

    // ---------------------------------------------------------------- page ---

    public function page()
    {
        $rcmail = rcmail::get_instance();
        $accounts = $this->accounts();

        // Accounts saved before the mirror (getmail) get their mirror file here.
        if (is_writable($this->accounts_dir())) {
            foreach ($accounts as $slug => $a) {
                if (!is_file($this->accounts_dir() . "/$slug.json")) {
                    $this->write_account($slug, $a, (string) $rcmail->decrypt($a['pass']));
                    @touch($this->accounts_dir() . '/.sync-now');
                }
            }
        }

        $this->register_handler('plugin.body', [$this, 'render']);
        $this->include_script('mailhub_accounts.js');
        $rcmail->output->set_env('mh_help', [
            'gmail' => $this->gettext('passhelp_gmail'),
            'privateemail' => $this->gettext('passhelp_privateemail'),
            'other' => $this->gettext('passhelp_other'),
        ]);
        $rcmail->output->set_pagetitle($this->gettext('section'));
        $rcmail->output->send('plugin');
    }

    public function render()
    {
        $accounts = $this->accounts();
        if (!is_writable($this->accounts_dir())) {
            return html::div(['class' => 'mh-page formcontent'],
                html::p(['class' => 'boxerror'], rcube::Q($this->gettext('dirnotwritable'))));
        }

        $cards = '';
        foreach ($accounts as $slug => $a) {
            $cards .= $this->card($slug, $a, true);
        }
        foreach (array_diff($this->files_on_disk(), array_keys($accounts)) as $slug) {
            $cards .= $this->card($slug, ['email' => $slug], false);
        }

        $intro = html::p(['class' => 'mh-intro'], rcube::Q($this->gettext($accounts ? 'intro' : 'introfirst')));
        $add = html::tag('button', ['type' => 'button', 'class' => 'button mainaction mh-add', 'id' => 'mh-add-btn'],
            rcube::Q($this->gettext('addbutton')));

        return html::div(['class' => 'mh-page formcontent', 'id' => 'mh-page'],
            html::tag('h2', ['class' => 'mh-title'], rcube::Q($this->gettext('section')))
            . $intro
            . html::div(['id' => 'mh-cards'], $cards ?: html::p(['class' => 'mh-empty'], rcube::Q($this->gettext('noaccounts'))))
            . html::p(['class' => 'mh-addrow'], $add)
            . $this->add_form()
            . html::div(['id' => 'mh-dialog-pass', 'class' => 'mh-dialog', 'style' => 'display:none'],
                html::label('mh-pass-input', rcube::Q($this->gettext('newpass')))
                . html::tag('input', ['type' => 'password', 'id' => 'mh-pass-input', 'class' => 'form-control', 'autocomplete' => 'new-password'])
                . html::p(['class' => 'mh-hint', 'id' => 'mh-pass-help'], ''))
            . html::div(['id' => 'mh-dialog-name', 'class' => 'mh-dialog', 'style' => 'display:none'],
                html::label('mh-name-input', rcube::Q($this->gettext('name')))
                . html::tag('input', ['type' => 'text', 'id' => 'mh-name-input', 'class' => 'form-control'])
                . html::p(['class' => 'mh-hint'], rcube::Q($this->gettext('namehint'))))
        );
    }

    /** One account: who it is, its status, where its mail is, what can be done. */
    private function card($slug, array $a, $managed)
    {
        $st = $this->status($slug);
        $provider = $managed ? $this->provider_label($a) : $this->gettext('byfile');
        $where = $this->gettext(['name' => 'where', 'vars' => ['folder' => 'Live › ' . $a['email']]]);
        $merged = ($a['in_views'] ?? true) !== false;
        if ($managed && !$merged) {
            $where .= ' · ' . $this->gettext('notmerged');
        }
        if ($managed) {
            $where .= ' · ' . $this->gettext('receive') . ' ' . $a['imap_host'] . ':' . $a['imap_port']
                . ' · ' . $this->gettext('send') . ' ' . $a['smtp_host'] . ':' . $a['smtp_port'];
        }
        $btn = function ($op, $label, $class = '') {
            return html::tag('button', ['type' => 'button', 'class' => "button $class", 'data-op' => $op], rcube::Q($this->gettext($label)));
        };
        $actions = $btn('syncnow', 'syncnow');
        if ($managed) {
            $actions .= $btn($merged ? 'unmerge' : 'merge', $merged ? 'unmerge' : 'merge')
                . $btn('password', 'changepass') . $btn('rename', 'rename') . $btn('remove', 'removebutton', 'delete');
        }

        return html::div(['class' => 'mh-card', 'data-slug' => $slug, 'data-email' => $a['email'],
                'data-name' => $a['name'] ?? '', 'data-preset' => $a['preset'] ?? 'other'],
            html::div(['class' => 'mh-head'],
                html::span(['class' => 'mh-email'], rcube::Q($a['email']))
                . html::span(['class' => 'mh-provider'], rcube::Q($provider)))
            . (($a['name'] ?? '') !== '' ? html::div(['class' => 'mh-name'], rcube::Q($this->gettext('sendsas') . ' ' . $a['name'])) : '')
            . html::div(['class' => 'mh-status mh-' . $st['state']],
                html::span(['class' => 'mh-dot'], '') . html::span(['class' => 'mh-status-text'], rcube::Q($st['text'])))
            . html::div(['class' => 'mh-where'], rcube::Q($where))
            . html::div(['class' => 'mh-actions'], $actions)
        );
    }

    private function add_form()
    {
        $preset = new html_select(['name' => 'preset', 'id' => 'mh_preset', 'class' => 'custom-select']);
        $preset->add($this->gettext('preset_gmail'), 'gmail');
        $preset->add($this->gettext('preset_privateemail'), 'privateemail');
        $preset->add($this->gettext('preset_other'), 'other');
        $row = function ($key, $label, $input, $other = false) {
            return html::div(['class' => 'mh-row' . ($other ? ' mh-other' : '')],
                html::label("mh_new_$key", rcube::Q($this->gettext($label))) . $input);
        };
        $input = function ($key, $attrs = []) {
            return html::tag('input', $attrs + ['type' => 'text', 'name' => $key, 'id' => "mh_new_$key", 'class' => 'form-control']);
        };
        $sec = function ($key, $choices) {
            $s = new html_select(['name' => $key, 'id' => "mh_new_$key", 'class' => 'custom-select']);
            foreach ($choices as $c) {
                $s->add($this->gettext('sec_' . $c), $c);
            }
            return $s->show('ssl');
        };

        return html::div(['id' => 'mh-dialog-add', 'class' => 'mh-dialog', 'style' => 'display:none'],
            $row('preset', 'provider', $preset->show('gmail'))
            . $row('email', 'email', $input('email', ['placeholder' => 'you@example.com', 'autocomplete' => 'off']))
            . $row('name', 'name', $input('name'))
            . html::div(['class' => 'mh-row mh-check'],
                html::tag('input', ['type' => 'checkbox', 'name' => 'in_views', 'id' => 'mh_new_in_views', 'value' => '1', 'checked' => 'checked'])
                . html::label('mh_new_in_views', rcube::Q($this->gettext('inviews'))))
            . $row('pass', 'apppass', $input('pass', ['type' => 'password', 'autocomplete' => 'new-password']))
            . html::p(['class' => 'mh-hint mh-passhelp'], rcube::Q($this->gettext('passhelp_gmail')))
            . $row('user', 'user', $input('user', ['placeholder' => $this->gettext('userplaceholder')]), true)
            . $row('imap_host', 'imap_host', $input('imap_host', ['placeholder' => 'imap.example.com']), true)
            . $row('imap_port', 'imap_port', $input('imap_port', ['value' => '993', 'size' => 6]) . $sec('imap_security', ['ssl', 'none']), true)
            . $row('smtp_host', 'smtp_host', $input('smtp_host', ['placeholder' => 'smtp.example.com']), true)
            . $row('smtp_port', 'smtp_port', $input('smtp_port', ['value' => '465', 'size' => 6]) . $sec('smtp_security', ['ssl', 'tls', 'none']), true)
            . html::p(['class' => 'mh-hint'], rcube::Q($this->gettext('addhint')))
        );
    }

    private function provider_label(array $a)
    {
        $p = $a['preset'] ?? 'other';
        return isset(self::PRESETS[$p]) ? $this->gettext('preset_' . $p) : $a['imap_host'];
    }

    // ------------------------------------------------------------- actions ---

    /** Add, remove, rename, change password, sync now: each one applied at once. */
    public function do_action()
    {
        $rcmail = rcmail::get_instance();
        $out = $rcmail->output;
        $op = rcube_utils::get_input_string('_op', rcube_utils::INPUT_POST);
        $slug = rcube_utils::get_input_string('_slug', rcube_utils::INPUT_POST);
        $accounts = $this->accounts();
        $smtp = (array) $rcmail->config->get('identity_smtp', []);

        $fail = function ($msg) use ($out) {
            $out->show_message($msg, 'error');
            $out->send();
        };
        if (!is_writable($this->accounts_dir())) {
            $fail($this->gettext('dirnotwritable'));
        }
        if ($op !== 'add' && !isset($accounts[$slug]) && !($op === 'syncnow' && in_array($slug, $this->files_on_disk()))) {
            $fail($this->gettext('unknownaccount'));
        }

        switch ($op) {
            case 'add':
                $in = (array) rcube_utils::get_input_value('_new', rcube_utils::INPUT_POST, true);
                $account = $this->new_account($in, $error);
                if (!$account) {
                    $fail($error);
                }
                $slug = self::slug($account['email']);
                if (isset($accounts[$slug])) {
                    $fail($this->gettext('exists'));
                }
                $pass = $account['pass'];
                if ($error = $this->test_account($account, $pass)) {
                    $fail($error);
                }
                $account['pass'] = $rcmail->encrypt($pass);
                $accounts[$slug] = $account;
                if (!$this->set_identity($accounts, $smtp, $slug, $pass)) {
                    $fail($this->gettext('identityfailed'));
                }
                $msg = $this->gettext(['name' => 'added', 'vars' => ['email' => $account['email']]]);
                break;

            case 'password':
                $a = $accounts[$slug];
                $pass = self::clean_pass((string) rcube_utils::get_input_value('_pass', rcube_utils::INPUT_POST, true), $a['preset'] ?? 'other');
                if ($pass === '' || preg_match('/[\r\n]/', $pass)) {
                    $fail($this->gettext('nopass'));
                }
                if ($error = $this->test_account($a, $pass)) {
                    $fail($error);
                }
                $accounts[$slug]['pass'] = $rcmail->encrypt($pass);
                if (!$this->set_identity($accounts, $smtp, $slug, $pass)) {
                    $fail($this->gettext('identityfailed'));
                }
                $msg = $this->gettext('passchanged');
                break;

            case 'rename':
                $accounts[$slug]['name'] = trim((string) rcube_utils::get_input_value('_name', rcube_utils::INPUT_POST, true));
                if (!$this->set_identity($accounts, $smtp, $slug, null)) {
                    $fail($this->gettext('identityfailed'));
                }
                $msg = $this->gettext('renamed');
                break;

            case 'remove':
                // The identity stays (you can still reply from that address), but
                // no longer as the default sender while another account is left. The
                // mirror sees the file go, stops and deletes this server's copy only.
                $email = $accounts[$slug]['email'];
                $iid = (int) ($accounts[$slug]['identity_id'] ?? 0);
                unset($accounts[$slug]);
                $default = $rcmail->user->get_identity();
                if ($default && (int) $default['identity_id'] === $iid) {
                    foreach ($accounts as $a) {
                        if (!empty($a['identity_id'])) {
                            $rcmail->user->set_default((int) $a['identity_id']);
                            break;
                        }
                    }
                }
                @unlink($this->accounts_dir() . "/$slug.json");
                @unlink($this->accounts_dir() . "/$slug.getmailrc");  // from before the mirror
                $msg = $this->gettext(['name' => 'removed', 'vars' => ['email' => $email]]);
                break;

            case 'merge':
            case 'unmerge':
                // Only the views change: the mirror rewrites them, nothing is synced again.
                $accounts[$slug]['in_views'] = $op === 'merge';
                $msg = $this->gettext($op === 'merge' ? 'merged' : 'unmerged');
                break;

            case 'syncnow':
                @touch($this->accounts_dir() . '/.sync-now');
                $out->show_message($this->gettext('syncrequested'), 'confirmation');
                $out->send();
                return;

            default:
                $fail('unknown action');
        }

        foreach ($accounts as $s => $a) {
            if (!$this->write_account($s, $a, (string) $rcmail->decrypt($a['pass']))) {
                $fail($this->gettext('dirnotwritable'));
            }
        }
        @touch($this->accounts_dir() . '/.sync-now');
        $rcmail->user->save_prefs([self::PREF => $accounts, 'identity_smtp' => $smtp]);

        $out->show_message($msg, 'confirmation');
        $out->command('plugin.mh_reload', []);
        $out->send();
    }

    /** The live status of every account, for the page's refresh. */
    public function status_action()
    {
        $rcmail = rcmail::get_instance();
        $data = [];
        foreach (array_unique(array_merge(array_keys($this->accounts()), $this->files_on_disk())) as $slug) {
            $data[$slug] = $this->status($slug);
        }
        $rcmail->output->command('plugin.mh_status', $data);
        $rcmail->output->send();
    }

    private function accounts()
    {
        return (array) rcmail::get_instance()->config->get(self::PREF, []);
    }

    /**
     * Write the account's identity and its SMTP (identity_smtp's format). A null
     * password keeps the stored one.
     */
    private function set_identity(array &$accounts, array &$smtp, $slug, $pass)
    {
        $rcmail = rcmail::get_instance();
        $a = $accounts[$slug];
        $iid = $this->ensure_identity($a['email'], $a['name']);
        if (!$iid) {
            return false;
        }
        $accounts[$slug]['identity_id'] = $iid;
        $smtp[$iid] = [
            'smtp_standard' => false,
            'smtp_host'     => self::smtp_uri($a),
            'smtp_user'     => $a['user'],
            'smtp_pass'     => $pass !== null ? $rcmail->encrypt($pass) : ($smtp[$iid]['smtp_pass'] ?? $a['pass']),
        ];

        return true;
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
            'in_views' => ($in['in_views'] ?? '1') !== '0',
            'user'   => $user,
            'pass'   => $pass,
        ];
    }

    private static function clean_pass($pass, $preset)
    {
        return $preset === 'gmail' ? str_replace(' ', '', $pass) : $pass;
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

    /**
     * This user's folder of account files (`<accounts dir>/<user>/`): every MailHub
     * user has their own mailbox, mirrored from their own accounts.
     */
    private function accounts_dir()
    {
        $base = rtrim(rcmail::get_instance()->config->get('mailhub_accounts_dir', '/var/mailhub/accounts'), '/');
        $dir = $base . '/' . self::user_dir_name();
        if (!is_dir($dir) && is_writable($base)) {
            @mkdir($dir, 0700);
        }

        return $dir;
    }

    /** This user's mirror state (`<state dir>/<user>/`, written by mailhub-mirror). */
    private function state_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_mirror_state_dir', '/var/mailhub/mirror'), '/')
            . '/' . self::user_dir_name();
    }

    /** The mailbox's user name, as a folder name (the mirror only knows such names). */
    private static function user_dir_name()
    {
        $user = strtolower((string) ($_SESSION['username'] ?? ''));

        return preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', $user) ? $user : '_invalid';
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
            'in_views'      => ($a['in_views'] ?? true) !== false,
        ];
        $file = $this->accounts_dir() . "/$slug.json";
        $tmp = $this->accounts_dir() . "/.$slug.tmp";
        $old = umask(0077);
        $ok = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false
            && rename($tmp, $file);
        umask($old);

        return $ok;
    }

    /**
     * The account's mirror status for the page: state (ok, running, failed,
     * waiting), a sentence, and whether the provider refused the password.
     */
    private function status($slug)
    {
        $file = $this->state_dir() . "/status/$slug.json";
        $s = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($s)) {
            return ['state' => 'waiting', 'text' => $this->gettext('waiting'), 'badlogin' => false];
        }

        $when = rcmail::get_instance()->format_date($s['at'] ?? '');
        $last = trim((string) ($s['last'] ?? ''));
        $n = number_format((int) ($s['messages'] ?? 0));
        if (!empty($s['running'])) {
            $text = $this->gettext(['name' => !empty($s['first']) ? 'st_first' : 'st_syncing', 'vars' => ['n' => $n]]);
            return ['state' => 'running', 'text' => $text, 'badlogin' => false];
        }
        if (!empty($s['ok'])) {
            return ['state' => 'ok', 'text' => $this->gettext(['name' => 'st_ok', 'vars' => ['n' => $n, 'when' => $when]]), 'badlogin' => false];
        }
        $bad = (bool) preg_match('/AUTHENTICATIONFAILED|invalid credentials|authentication failed|login failed|application-specific password|LOGIN failed/i', $last);
        $text = $this->gettext(['name' => 'st_failed', 'vars' => ['when' => $when,
            'last' => $bad ? $this->gettext('fetchbadlogin') : mb_substr($last, 0, 200)]]);

        return ['state' => 'failed', 'text' => $text, 'badlogin' => $bad];
    }
}
