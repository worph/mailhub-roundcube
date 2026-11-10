<?php

/**
 * MailHub: Settings → "Notifications", push notifications for new mail on phones and
 * computers (Web Push), also with MailHub closed.
 *
 * mailhub-mirror (0.6.0+) is what sends them: it pulls the mail, so it is the one that
 * knows a message arrived. This page subscribes the browser and writes, in the user's
 * own account folder (mailhub_accounts_dir/<user>/, which the mirror reads):
 *   - .push.json: {devices: [{id, endpoint, p256dh, auth, label, added, updated}],
 *     show_content, muted: [account slugs], texts: {the notification texts in the
 *     user's language}};
 *   - .push-test.json: {nonce, device}, a test notification asked from the page.
 * and reads what the mirror writes (mailhub_mirror_state_dir):
 *   - .push/vapid-public.txt: the server key browsers subscribe with;
 *   - <user>/push-status.json: per device the last delivery or failure, `gone` once
 *     the push service said the subscription is no more; the test results by nonce.
 *
 * The service worker (sw.js) shows the notification and opens MailHub on a click.
 * Every page checks, once per session, that this browser's subscription is still the
 * one on file (browsers renew them), and sends the new one if not.
 */
class mailhub_push extends rcube_plugin
{
    private const ACTION = 'plugin.mailhub_push';
    private const MAX_DEVICES = 20;
    private const NAME_RE = '/^[a-z0-9][a-z0-9._-]{0,31}$/';

    public function init()
    {
        $rcmail = rcmail::get_instance();
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $this->add_texts('localization/');

        if ($rcmail->task == 'settings') {
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action(self::ACTION, [$this, 'page']);
            foreach (['subscribe', 'unsubscribe', 'prefs', 'test', 'result'] as $a) {
                $this->register_action(self::ACTION . '-' . $a, [$this, $a . '_action']);
            }
            $this->include_stylesheet('mailhub_push.css');
        }

        // every page: keep this browser's subscription the one on file
        $output = $rcmail->output;
        if ($output && $output->type == 'html' && empty($_REQUEST['_framed'])) {
            $this->include_script('mailhub_push.js');
            $this->set_env();
        }
    }

    /** "Notifications" in the Settings menu, after Mail accounts. */
    public function settings_actions($args)
    {
        $entry = [
            'action' => self::ACTION,
            'class'  => 'mailhub-push',
            'label'  => 'section',
            'title'  => 'sectiontitle',
            'domain' => 'mailhub_push',
        ];
        $pos = count($args['actions']);
        foreach ($args['actions'] as $i => $a) {
            if (in_array($a['action'] ?? '', ['identities', 'plugin.mailhub_accounts'])) {
                $pos = $i + 1;
            }
        }
        array_splice($args['actions'], $pos, 0, [$entry]);

        return $args;
    }

    private function set_env()
    {
        $rcmail = rcmail::get_instance();
        $status = $this->status();
        $devices = [];
        foreach ($this->config()['devices'] as $d) {
            $gone = $status['devices'][$d['id']]['gone'] ?? null;
            $devices[$d['id']] = $gone !== null && $gone === ($d['updated'] ?? $d['added'] ?? null);
        }
        $rcmail->output->set_env('mailhub_push', [
            'key' => $this->vapid_public(),
            'sw' => $rcmail->output->asset_url($this->urlbase . 'sw.js'),
            'devices' => (object) $devices,   // id => expired
        ]);
    }

    // ---------------------------------------------------------------- page ---

    public function page()
    {
        $rcmail = rcmail::get_instance();
        $this->register_handler('plugin.body', [$this, 'render']);
        $rcmail->output->add_label(
            'mailhub_push.unsupported', 'mailhub_push.unsupported_ios', 'mailhub_push.denied',
            'mailhub_push.enabled_here', 'mailhub_push.notenabled_here', 'mailhub_push.enabling',
            'mailhub_push.testsending', 'mailhub_push.testok', 'mailhub_push.testfail',
            'mailhub_push.testnone', 'mailhub_push.testtimeout', 'mailhub_push.subscribefailed',
            'mailhub_push.thisdevice_badge', 'mailhub_push.removeconfirm', 'mailhub_push.remove'
        );
        $rcmail->output->set_pagetitle($this->gettext('section'));
        $rcmail->output->send('plugin');
    }

    public function render()
    {
        $conf = $this->config();
        $notes = '';
        if (!$this->writable()) {
            $notes .= html::p(['class' => 'boxerror'], rcube::Q($this->gettext('dirnotwritable')));
        }
        if ($this->vapid_public() === null) {
            $notes .= html::p(['class' => 'boxwarning'], rcube::Q($this->gettext('nomirror')));
        }

        $here = html::div(['class' => 'mhp-card', 'id' => 'mhp-here'],
            html::div(['class' => 'mhp-state', 'id' => 'mhp-state'], rcube::Q($this->gettext('checking')))
            . html::div(['class' => 'mhp-buttons'],
                html::tag('button', ['type' => 'button', 'class' => 'button mainaction', 'id' => 'mhp-enable', 'hidden' => 'hidden'],
                    rcube::Q($this->gettext('enable')))
                . html::tag('button', ['type' => 'button', 'class' => 'button', 'id' => 'mhp-disable', 'hidden' => 'hidden'],
                    rcube::Q($this->gettext('disable')))
                . html::tag('button', ['type' => 'button', 'class' => 'button', 'id' => 'mhp-test', 'hidden' => 'hidden'],
                    rcube::Q($this->gettext('test')))
                . html::tag('button', ['type' => 'button', 'class' => 'button', 'id' => 'mhp-testall']
                    + (count($conf['devices']) ? [] : ['hidden' => 'hidden']),
                    rcube::Q($this->gettext('testall'))))
            . html::div(['class' => 'mhp-result', 'id' => 'mhp-result', 'role' => 'status'], ''));

        return html::div(['class' => 'mhp-page formcontent', 'id' => 'mhp-page'],
            html::tag('h2', ['class' => 'mhp-title'], rcube::Q($this->gettext('section')))
            . html::p(['class' => 'mhp-intro'], rcube::Q($this->gettext('intro')))
            . $notes
            . html::tag('h3', ['class' => 'mhp-sub'], rcube::Q($this->gettext('thisdevice')))
            . $here
            . $this->devices_list($conf)
            . $this->prefs_form($conf)
        );
    }

    private function devices_list(array $conf)
    {
        $rcmail = rcmail::get_instance();
        $status = $this->status()['devices'];
        $out = html::tag('h3', ['class' => 'mhp-sub'], rcube::Q($this->gettext('devices')));
        if (!$conf['devices']) {
            return $out . html::p(['class' => 'mhp-empty', 'id' => 'mhp-nodevices'], rcube::Q($this->gettext('nodevices')));
        }
        $rows = '';
        foreach ($conf['devices'] as $d) {
            $st = $status[$d['id']] ?? [];
            $gone = isset($st['gone']) && $st['gone'] === ($d['updated'] ?? $d['added'] ?? null);
            $added = strtotime($d['added'] ?? '') ?: null;
            $info = $added ? $this->gettext(['name' => 'added', 'vars' => ['date' => $rcmail->format_date($added)]]) : '';
            if ($gone) {
                $line = html::span(['class' => 'mhp-bad'], rcube::Q($this->gettext('expired')));
            } elseif (!empty($st['last_error']) && (empty($st['last_ok']) || $st['last_error'] > $st['last_ok'])) {
                $line = html::span(['class' => 'mhp-bad'], rcube::Q($this->gettext(['name' => 'lasterror', 'vars' => [
                    'date' => $rcmail->format_date(strtotime($st['last_error'])), 'error' => (string) ($st['error'] ?? '')]])));
            } elseif (!empty($st['last_ok'])) {
                $line = html::span(['class' => 'mhp-good'], rcube::Q($this->gettext(['name' => 'lastok', 'vars' => [
                    'date' => $rcmail->format_date(strtotime($st['last_ok']))]])));
            } else {
                $line = rcube::Q($this->gettext('nodelivery'));
            }
            $rows .= html::tag('li', ['class' => 'mhp-device' . ($gone ? ' mhp-gone' : ''), 'data-id' => $d['id']],
                html::div(['class' => 'mhp-devname'], rcube::Q($d['label'] ?: $this->gettext('unknowndevice'))
                    . html::tag('span', ['class' => 'mhp-badge', 'hidden' => 'hidden'], rcube::Q($this->gettext('thisdevice_badge'))))
                . html::div(['class' => 'mhp-hint'], rcube::Q($info) . ($info !== '' ? ' · ' : '') . $line)
                . html::tag('button', ['type' => 'button', 'class' => 'button mhp-remove', 'data-id' => $d['id']],
                    rcube::Q($this->gettext('remove'))));
        }

        return $out . html::tag('ul', ['class' => 'mhp-devices', 'id' => 'mhp-devices'], $rows);
    }

    private function prefs_form(array $conf)
    {
        $check = function ($name, $value, $checked, $label, $hint = '') {
            $id = 'mhp-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($name . '-' . $value));
            $input = html::tag('input', ['type' => 'checkbox', 'id' => $id, 'name' => $name, 'value' => $value,
                'class' => 'form-check-input mhp-switch'] + ($checked ? ['checked' => 'checked'] : []));

            return html::div(['class' => 'mhp-row'], $input . html::label(['for' => $id], rcube::Q($label))
                . ($hint !== '' ? html::div(['class' => 'mhp-hint'], rcube::Q($hint)) : ''));
        };

        $accounts = '';
        foreach ($this->accounts() as $slug => $a) {
            $on = is_array($conf['muted']) ? !in_array($slug, $conf['muted'], true) : ($a['in_views'] ?? true) !== false;
            $accounts .= $check('notify', $slug, $on, $a['email']);
        }

        return html::tag('h3', ['class' => 'mhp-sub'], rcube::Q($this->gettext('what')))
            . html::tag('form', ['id' => 'mhp-form', 'class' => 'mhp-card' . ($this->writable() ? '' : ' mhp-readonly'),
                    'onsubmit' => 'return false'],
                $check('show_content', '1', $conf['show_content'], $this->gettext('content'), $this->gettext('contenthint'))
                . html::div(['class' => 'mhp-subtitle'], rcube::Q($this->gettext('accounts')))
                . ($accounts !== '' ? html::p(['class' => 'mhp-hint'], rcube::Q($this->gettext('accountshint'))) . $accounts
                    : html::p(['class' => 'mhp-empty'], rcube::Q($this->gettext('noaccounts')))));
    }

    // ------------------------------------------------------------- actions ---

    public function subscribe_action()
    {
        $out = rcmail::get_instance()->output;
        $endpoint = rcube_utils::get_input_string('_endpoint', rcube_utils::INPUT_POST);
        $p256dh = rcube_utils::get_input_string('_p256dh', rcube_utils::INPUT_POST);
        $auth = rcube_utils::get_input_string('_auth', rcube_utils::INPUT_POST);
        $label = trim(mb_substr(rcube_utils::get_input_string('_label', rcube_utils::INPUT_POST), 0, 60));
        $replaces = rcube_utils::get_input_string('_replaces', rcube_utils::INPUT_POST);

        $url = parse_url($endpoint);
        if (strlen($endpoint) > 1024 || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || !preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{16,32}$/', $auth)) {
            $out->show_message($this->gettext('subscribefailed'), 'error');
            $out->send();
        }
        $id = substr(hash('sha256', $endpoint), 0, 16);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $conf = $this->config();
        $devices = [];
        $added = $now;
        foreach ($conf['devices'] as $d) {
            if ($d['id'] === $id || ($replaces !== '' && $d['id'] === $replaces)) {
                $added = $d['added'] ?? $now;
                $label = $label !== '' ? $label : ($d['label'] ?? '');
                continue;
            }
            $devices[] = $d;
        }
        $devices[] = ['id' => $id, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
            'label' => $label, 'added' => $added, 'updated' => $now];
        $conf['devices'] = array_slice($devices, -self::MAX_DEVICES);
        $this->save($conf);
        $out->command('plugin.mhp_subscribed', ['id' => $id]);
        $out->send();
    }

    public function unsubscribe_action()
    {
        $out = rcmail::get_instance()->output;
        $id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $conf = $this->config();
        $conf['devices'] = array_values(array_filter($conf['devices'], function ($d) use ($id) { return $d['id'] !== $id; }));
        $this->save($conf);
        $out->command('plugin.mhp_removed', ['id' => $id]);
        $out->send();
    }

    public function prefs_action()
    {
        $out = rcmail::get_instance()->output;
        $conf = $this->config();
        $conf['show_content'] = rcube_utils::get_input_string('_show_content', rcube_utils::INPUT_POST) === '1';
        $on = array_map('strval', (array) rcube_utils::get_input_value('_notify', rcube_utils::INPUT_POST));
        $conf['muted'] = array_values(array_diff(array_keys($this->accounts()), $on));
        $this->save($conf);
        $out->show_message($this->gettext('saved'), 'confirmation');
        $out->send();
    }

    /** A test notification, sent by the mirror; the page polls `result` with the nonce. */
    public function test_action()
    {
        $out = rcmail::get_instance()->output;
        $device = rcube_utils::get_input_string('_device', rcube_utils::INPUT_POST);
        if ($device !== 'all' && !preg_match('/^[0-9a-f]{16}$/', $device)) {
            $device = 'all';
        }
        $nonce = bin2hex(random_bytes(8));
        $this->save($this->config());  // the texts in the current language
        if (!$this->write_file('.push-test.json', ['nonce' => $nonce, 'device' => $device, 'at' => gmdate('Y-m-d\TH:i:s\Z')])) {
            $out->show_message($this->gettext('savefailed'), 'error');
            $out->send();
        }
        $out->command('plugin.mhp_testing', ['nonce' => $nonce]);
        $out->send();
    }

    public function result_action()
    {
        $out = rcmail::get_instance()->output;
        $nonce = rcube_utils::get_input_string('_nonce', rcube_utils::INPUT_POST);
        $test = $this->status()['test'];
        if (!$test || ($test['nonce'] ?? null) !== $nonce) {
            $out->command('plugin.mhp_result', ['done' => false, 'nonce' => $nonce]);
            $out->send();
        }
        $labels = [];
        foreach ($this->config()['devices'] as $d) {
            $labels[$d['id']] = $d['label'] ?: $this->gettext('unknowndevice');
        }
        $results = [];
        foreach ((array) ($test['results'] ?? []) as $id => $r) {
            $results[] = ['id' => $id, 'label' => $labels[$id] ?? $id, 'ok' => !empty($r['ok']),
                'error' => trim(($r['status'] ?? '') . ' ' . ($r['error'] ?? ''))];
        }
        $out->command('plugin.mhp_result', ['done' => true, 'nonce' => $nonce, 'results' => $results,
            'error' => (string) ($test['error'] ?? '')]);
        $out->send();
    }

    // --------------------------------------------------------------- files ---

    /** The mailbox's user name, as a folder name (as mailhub_accounts has it). */
    private static function user_dir_name()
    {
        $user = strtolower((string) ($_SESSION['username'] ?? ''));

        return preg_match(self::NAME_RE, $user) ? $user : '_invalid';
    }

    private function accounts_dir()
    {
        $base = rtrim(rcmail::get_instance()->config->get('mailhub_accounts_dir', '/var/mailhub/accounts'), '/');
        $dir = $base . '/' . self::user_dir_name();
        if (!is_dir($dir) && is_writable($base)) {
            @mkdir($dir, 0700);
        }

        return $dir;
    }

    private function state_base()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_mirror_state_dir', '/var/mailhub/mirror'), '/');
    }

    private function writable()
    {
        $dir = $this->accounts_dir();

        return is_dir($dir) && is_writable($dir);
    }

    private function read_json($file)
    {
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function vapid_public()
    {
        $key = trim((string) @file_get_contents($this->state_base() . '/.push/vapid-public.txt'));

        return preg_match('/^[A-Za-z0-9_-]{80,100}$/', $key) ? $key : null;
    }

    private function config()
    {
        $data = (array) $this->read_json($this->accounts_dir() . '/.push.json');
        $devices = array_values(array_filter((array) ($data['devices'] ?? []), function ($d) {
            return is_array($d) && is_string($d['id'] ?? null) && is_string($d['endpoint'] ?? null);
        }));

        return [
            'devices' => $devices,
            'show_content' => is_bool($data['show_content'] ?? null) ? $data['show_content'] : true,
            'muted' => is_array($data['muted'] ?? null) ? array_values(array_filter($data['muted'], 'is_string')) : null,
        ];
    }

    private function status()
    {
        $data = (array) $this->read_json($this->state_base() . '/' . self::user_dir_name() . '/push-status.json');

        return ['devices' => (array) ($data['devices'] ?? []), 'test' => is_array($data['test'] ?? null) ? $data['test'] : null];
    }

    /** This user's mirrored accounts: slug => account file (email, in_views). */
    private function accounts()
    {
        $out = [];
        foreach (glob($this->accounts_dir() . '/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');
            $a = $this->read_json($file);
            if ($a && strpos($slug, '.') !== 0 && !empty($a['email'])) {
                $out[$slug] = ['email' => (string) $a['email'], 'in_views' => $a['in_views'] ?? true];
            }
        }
        ksort($out);

        return $out;
    }

    /** The notification texts, in this user's language, for the mirror. */
    private function texts()
    {
        $t = [];
        foreach (['new_many', 'hidden_one', 'hidden_many', 'no_subject', 'test_title', 'test_body'] as $k) {
            // the mirror fills {n} and {account} (Python str.format)
            $t[$k] = str_replace(['$n', '$account'], ['{n}', '{account}'], $this->gettext('push_' . $k));
        }

        return $t;
    }

    private function save(array $conf)
    {
        $conf['texts'] = $this->texts();
        if ($conf['muted'] === null) {
            unset($conf['muted']);
        }
        if (!$this->write_file('.push.json', $conf)) {
            $out = rcmail::get_instance()->output;
            $out->show_message($this->gettext('savefailed'), 'error');
            $out->send();
        }
    }

    private function write_file($name, array $data)
    {
        $dir = $this->accounts_dir();
        if (!$this->writable()) {
            return false;
        }
        $tmp = "$dir/.$name.tmp";
        $old = umask(0077);
        $ok = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") !== false
            && rename($tmp, "$dir/$name");
        umask($old);

        return $ok;
    }
}
