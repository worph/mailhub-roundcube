<?php

/**
 * MailHub: Settings → "AI assistant", the MCP server (mailhub-mcp) that gives AI
 * assistants the mailbox, calendar and contacts through Beacon.
 *
 * Two folders, each written by one side only:
 *   - the settings folder (mailhub_mcp_settings_dir, written here): settings.json,
 *     {"enabled", "write", "disabled_tools", "cleared_at"}, which the MCP server
 *     reads on every request. It lists only the tools allowed and refuses the others;
 *   - the log folder (mailhub_mcp_log_dir, written by the MCP server): tools.json,
 *     every tool and whether it writes, and calls.jsonl, one line per call (time,
 *     tool, shortened arguments, duration, outcome; never the result).
 *
 * Shown to the owner's mailbox only (mailhub_mcp_owner, `admin`), the one the MCP
 * server serves: for any other user there is no menu entry and no action.
 *
 * Each switch is saved as soon as it is changed. Clearing the history sets
 * cleared_at: the page shows only later calls, and the server drops older ones.
 */
class mailhub_mcp extends rcube_plugin
{
    public $task = 'settings';

    private const ACTION = 'plugin.mailhub_mcp';
    private const RECENT = 100;

    public function init()
    {
        // The MCP server serves the owner's mailbox only (mailhub_mcp_owner, `admin`):
        // nobody else sees this page, and its actions are not registered for them,
        // so Roundcube refuses them.
        if (!$this->is_owner()) {
            return;
        }
        $this->add_texts('localization/', true);
        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action(self::ACTION, [$this, 'page']);
        $this->register_action(self::ACTION . '-save', [$this, 'save_action']);
        // the Settings menu entry's icon, on every Settings page
        $this->include_stylesheet('mailhub_mcp.css');
    }

    /** "AI assistant" in the Settings menu, after Mail accounts (or Identities). */
    public function settings_actions($args)
    {
        $entry = [
            'action' => self::ACTION,
            'class'  => 'mailhub-mcp',
            'label'  => 'section',
            'title'  => 'sectiontitle',
            'domain' => 'mailhub_mcp',
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

    // ---------------------------------------------------------------- page ---

    public function page()
    {
        $rcmail = rcmail::get_instance();
        $this->register_handler('plugin.body', [$this, 'render']);
        $this->include_script('mailhub_mcp.js');
        $rcmail->output->add_label('mailhub_mcp.clearconfirm', 'mailhub_mcp.cleartitle', 'mailhub_mcp.clearok', 'mailhub_mcp.saved');
        $rcmail->output->set_pagetitle($this->gettext('section'));
        $rcmail->output->send('plugin');
    }

    public function render()
    {
        $conf = $this->settings();
        $catalog = $this->catalog();
        $calls = $this->calls($conf['cleared_at']);

        $notes = '';
        if (!$this->settings_writable()) {
            $notes .= html::p(['class' => 'boxerror'], rcube::Q($this->gettext('dirnotwritable')));
        }
        if ($catalog === null) {
            $notes .= html::p(['class' => 'boxwarning'], rcube::Q($this->gettext('noserver')));
        }

        return html::div(['class' => 'mhm-page formcontent', 'id' => 'mhm-page'],
            html::tag('h2', ['class' => 'mhm-title'], rcube::Q($this->gettext('section')))
            . html::p(['class' => 'mhm-intro'], rcube::Q($this->gettext('intro')))
            . $notes
            . $this->controls($conf, $catalog)
            . $this->usage($calls, $catalog)
            . $this->history($calls)
        );
    }

    /** The two switches, then one per tool. */
    private function controls(array $conf, $catalog)
    {
        $switch = function ($name, $checked, $label, $hint, $attrs = []) {
            $id = 'mhm-' . str_replace('_', '-', $name);
            $input = html::tag('input', $attrs + ['type' => 'checkbox', 'id' => $id, 'name' => $name, 'value' => '1',
                'class' => 'form-check-input mhm-switch'] + ($checked ? ['checked' => 'checked'] : []));

            return html::div(['class' => 'mhm-row'],
                $input . html::label(['for' => $id], rcube::Q($label))
                . ($hint !== '' ? html::div(['class' => 'mhm-hint'], rcube::Q($hint)) : ''));
        };

        $main = $switch('enabled', $conf['enabled'], $this->gettext('enabled'), $this->gettext('enabledhint'))
            . $switch('write', $conf['write'], $this->gettext('write'), $this->gettext('writehint'));

        $tools = '';
        foreach ((array) $catalog as $t) {
            $name = (string) ($t['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $label = $name . (!empty($t['write']) ? ' · ' . $this->gettext('writes') : '');
            $tools .= html::div(['class' => 'mhm-tool' . (!empty($t['write']) ? ' mhm-writes' : '')],
                $switch('tool_' . $name, !in_array($name, $conf['disabled_tools']), $label,
                    (string) ($t['description'] ?? ''), ['data-tool' => $name]));
        }
        if ($tools !== '') {
            $tools = html::tag('h3', ['class' => 'mhm-sub'], rcube::Q($this->gettext('tools')))
                . html::p(['class' => 'mhm-hint'], rcube::Q($this->gettext('toolshint')))
                . html::div(['class' => 'mhm-tools' . ($conf['write'] ? '' : ' mhm-nowrite')
                    . ($conf['enabled'] ? '' : ' mhm-off'), 'id' => 'mhm-tools'], $tools);
        }

        return html::tag('form', ['id' => 'mhm-form', 'class' => 'mhm-card' . ($this->settings_writable() ? '' : ' mhm-readonly'),
                'onsubmit' => 'return false'], $main . $tools);
    }

    /** Calls per period, then per tool. */
    private function usage(array $calls, $catalog)
    {
        $now = time();
        $periods = ['day' => 86400, 'week' => 7 * 86400, 'month' => 30 * 86400];
        $tiles = '';
        foreach ($periods as $key => $secs) {
            $n = $err = 0;
            foreach ($calls as $c) {
                if ($c['time'] >= $now - $secs) {
                    $n++;
                    $err += $c['ok'] ? 0 : 1;
                }
            }
            $tiles .= html::div(['class' => 'mhm-tile'],
                html::div(['class' => 'mhm-num'], (string) $n)
                . html::div(['class' => 'mhm-tilelabel'], rcube::Q($this->gettext('period_' . $key)))
                . html::div(['class' => 'mhm-tilesub' . ($err ? ' mhm-bad' : '')],
                    rcube::Q($this->gettext(['name' => 'nfailed', 'vars' => ['n' => $err]]))));
        }

        $by = [];
        foreach ($calls as $c) {
            $t = &$by[$c['tool']];
            $t['n'] = ($t['n'] ?? 0) + 1;
            $t['err'] = ($t['err'] ?? 0) + ($c['ok'] ? 0 : 1);
            $t['ms'] = ($t['ms'] ?? 0) + $c['ms'];
            $t['last'] = max($t['last'] ?? 0, $c['time']);
            unset($t);
        }
        uasort($by, function ($a, $b) { return $b['n'] <=> $a['n']; });

        $rcmail = rcmail::get_instance();
        $table = new html_table(['class' => 'records-table mhm-table', 'cols' => 5]);
        foreach (['tool', 'calls', 'failed', 'avgms', 'lastused'] as $h) {
            $table->add_header(['class' => 'mhm-' . $h], rcube::Q($this->gettext('col_' . $h)));
        }
        foreach ($by as $tool => $t) {
            $table->add(['class' => 'mhm-tool'], rcube::Q($tool));
            $table->add(['class' => 'mhm-n'], (string) $t['n']);
            $table->add(['class' => 'mhm-n' . ($t['err'] ? ' mhm-bad' : '')], (string) $t['err']);
            $table->add(['class' => 'mhm-n'], (string) round($t['ms'] / $t['n']));
            $table->add(['class' => 'mhm-date'], rcube::Q($rcmail->format_date($t['last'])));
        }

        return html::tag('h3', ['class' => 'mhm-sub'], rcube::Q($this->gettext('usage')))
            . html::div(['class' => 'mhm-tiles'], $tiles)
            . ($by ? html::div(['class' => 'mhm-scroll'], $table->show())
                : html::p(['class' => 'mhm-empty'], rcube::Q($this->gettext('nocalls'))));
    }

    /** The latest calls, newest first. */
    private function history(array $calls)
    {
        $rcmail = rcmail::get_instance();
        $recent = array_slice(array_reverse($calls), 0, self::RECENT);
        $out = html::tag('h3', ['class' => 'mhm-sub'], rcube::Q($this->gettext('history'))
            . ($calls ? html::tag('button', ['type' => 'button', 'class' => 'button mhm-clear', 'id' => 'mhm-clear']
                + ($this->settings_writable() ? [] : ['disabled' => 'disabled']), rcube::Q($this->gettext('clear'))) : ''));
        if (!$recent) {
            return $out;
        }
        $out .= html::p(['class' => 'mhm-hint'], rcube::Q($this->gettext(['name' => 'historyhint',
            'vars' => ['n' => count($recent), 'total' => count($calls)]])));

        $table = new html_table(['class' => 'records-table mhm-table mhm-history', 'cols' => 5]);
        foreach (['time', 'tool', 'args', 'result', 'ms'] as $h) {
            $table->add_header(['class' => 'mhm-' . $h], rcube::Q($this->gettext('col_' . $h)));
        }
        foreach ($recent as $c) {
            if (!empty($c['refused'])) {
                $result = $this->gettext('refused');
            } else {
                $result = $c['ok'] ? $this->gettext('ok') : $this->gettext('failed');
            }
            $table->add(['class' => 'mhm-date'], rcube::Q($rcmail->format_date($c['time'])));
            $table->add(['class' => 'mhm-tool'], rcube::Q($c['tool']));
            $table->add(['class' => 'mhm-args'], rcube::Q($c['args']));
            $table->add(['class' => 'mhm-result ' . ($c['ok'] ? 'mhm-good' : 'mhm-bad'), 'title' => $c['error']], rcube::Q($result));
            $table->add(['class' => 'mhm-n'], (string) $c['ms']);
        }

        return $out . html::div(['class' => 'mhm-scroll'], $table->show());
    }

    // -------------------------------------------------------------- saving ---

    public function save_action()
    {
        $out = rcmail::get_instance()->output;
        if (!$this->settings_writable()) {
            $out->show_message($this->gettext('dirnotwritable'), 'error');
            $out->send();
        }
        $conf = $this->settings();
        if (rcube_utils::get_input_string('_op', rcube_utils::INPUT_POST) === 'clear') {
            $conf['cleared_at'] = gmdate('Y-m-d\TH:i:s+00:00');
            $reload = true;
        } else {
            $conf['enabled'] = rcube_utils::get_input_string('_enabled', rcube_utils::INPUT_POST) === '1';
            $conf['write'] = rcube_utils::get_input_string('_write', rcube_utils::INPUT_POST) === '1';
            $off = (array) rcube_utils::get_input_value('_disabled', rcube_utils::INPUT_POST);
            $conf['disabled_tools'] = array_values(array_unique(array_filter(array_map('strval', $off),
                function ($t) { return preg_match('/^[a-z0-9_]{1,64}$/', $t); })));
            $reload = false;
        }
        if (!$this->write_settings($conf)) {
            $out->show_message($this->gettext('savefailed'), 'error');
            $out->send();
        }
        $out->show_message($this->gettext('saved'), 'confirmation');
        if ($reload) {
            $out->command('plugin.mhm_reload', []);
        }
        $out->send();
    }

    // --------------------------------------------------------------- files ---

    private function is_owner()
    {
        $owner = (string) rcmail::get_instance()->config->get('mailhub_mcp_owner', 'admin');

        return isset($_SESSION['username']) && $_SESSION['username'] === $owner;
    }

    private function settings_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_mcp_settings_dir', '/var/mailhub/mcp-settings'), '/');
    }

    private function log_dir()
    {
        return rtrim(rcmail::get_instance()->config->get('mailhub_mcp_log_dir', '/var/mailhub/mcp-log'), '/');
    }

    private function settings_writable()
    {
        return is_dir($this->settings_dir()) && is_writable($this->settings_dir());
    }

    private function read_json($file)
    {
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** settings.json, with the server's defaults where it says nothing. */
    private function settings()
    {
        $data = (array) $this->read_json($this->settings_dir() . '/settings.json');
        $catalog = $this->read_json($this->log_dir() . '/tools.json');

        return [
            'enabled' => is_bool($data['enabled'] ?? null) ? $data['enabled'] : true,
            'write' => is_bool($data['write'] ?? null) ? $data['write'] : !empty($catalog['write_default']),
            'disabled_tools' => array_values(array_filter((array) ($data['disabled_tools'] ?? []), 'is_string')),
            'cleared_at' => is_string($data['cleared_at'] ?? null) ? $data['cleared_at'] : null,
        ];
    }

    private function write_settings(array $conf)
    {
        $file = $this->settings_dir() . '/settings.json';
        $tmp = $this->settings_dir() . '/.settings.tmp';
        $old = umask(0022);
        $ok = file_put_contents($tmp, json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false
            && rename($tmp, $file);
        umask($old);

        return $ok;
    }

    /** The tools the server offers, or null when it has not written tools.json. */
    private function catalog()
    {
        $data = $this->read_json($this->log_dir() . '/tools.json');

        return isset($data['tools']) && is_array($data['tools']) ? $data['tools'] : null;
    }

    /** The recorded calls, oldest first, from after the history was cleared. */
    private function calls($cleared_at)
    {
        $lines = @file($this->log_dir() . '/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $since = $cleared_at ? strtotime($cleared_at) : 0;
        $out = [];
        foreach ($lines as $line) {
            $c = json_decode($line, true);  // a line being written is skipped
            if (!is_array($c) || !isset($c['ts'], $c['tool']) || ($time = strtotime($c['ts'])) === false || $time < $since) {
                continue;
            }
            $args = [];
            foreach ((array) ($c['args'] ?? []) as $k => $v) {
                $args[] = $k . '=' . (is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $out[] = [
                'time' => $time,
                'tool' => (string) $c['tool'],
                'args' => implode(', ', $args),
                'ok' => !empty($c['ok']),
                'refused' => !empty($c['refused']),
                'error' => (string) ($c['error'] ?? ''),
                'ms' => (int) ($c['ms'] ?? 0),
            ];
        }

        return $out;
    }
}
