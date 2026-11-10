<?php

/**
 * MailHub: Archive, as on Gmail, next to Delete.
 *
 * A message is archived into the IMAP Archive folder of the account it belongs to:
 * Live/<address>/Archive (or Archives, whichever the account already has; created
 * if it has neither). mailhub-mirror replays the move at that provider, so the
 * message leaves its inbox there and lands in its Archive. A message of the local
 * mailbox (not an account's) goes into the local Archive.
 *
 * In Merged/All inboxes (a Dovecot virtual folder) the message's real folder is
 * asked of Dovecot (FETCH X-MAILBOX), so each one goes to its own account.
 *
 * Buttons: Archive in the toolbar (list and message view), and on each row of the
 * message list on hover, between Delete and the flag. Nothing is archived from inside an archive folder.
 */
class mailhub_archive extends rcube_plugin
{
    public $task = 'mail';

    private const NAMES = ['archive', 'archives'];
    private const LOCAL = 'Archive';

    private $folders = null;
    private $result;

    public function init()
    {
        $rcmail = rcmail::get_instance();

        if ($rcmail->action == '' || $rcmail->action == 'show') {
            $this->add_hook('startup', [$this, 'startup']);
            $this->add_texts('localization/', true);
            $this->include_script('mailhub_archive.js');
            $this->include_stylesheet('mailhub_archive.css');
            $this->add_button([
                'type' => 'link',
                'label' => 'buttontext',
                'command' => 'plugin.mailhub_archive',
                'class' => 'button buttonPas archive disabled',
                'classact' => 'button archive',
                'width' => 32,
                'height' => 32,
                'title' => 'buttontitle',
                'domain' => $this->ID,
                'innerclass' => 'inner',
            ], 'toolbar');
        } else {
            $this->register_action('plugin.mailhub_archive', [$this, 'archive']);
        }
    }

    /** The merged Archive (set by mailhub_folders) for skins that show an Archive button with it. */
    public function startup($args)
    {
        if ($folder = rcmail::get_instance()->config->get('archive_mbox')) {
            rcmail::get_instance()->output->set_env('archive_folder', $folder);
        }

        return $args;
    }

    /** Whether $mbox is an archive folder or inside one (Merged/Archive included). */
    public static function is_archive($mbox, $delim = '/')
    {
        $parts = explode($delim, (string) $mbox);
        if ($parts[0] === 'Live' && count($parts) >= 3) {
            $parts = array_slice($parts, 2);
        } elseif ($parts[0] === 'Merged' && count($parts) >= 2) {
            $parts = array_slice($parts, 1);
        }

        return in_array(strtolower($parts[0]), self::NAMES, true);
    }

    /** The archive folder of the account $mbox belongs to (created when missing). */
    private function target($mbox, $delim)
    {
        $storage = rcmail::get_instance()->get_storage();
        $parts = explode($delim, $mbox);
        $root = ($parts[0] === 'Live' && count($parts) >= 3) ? 'Live' . $delim . $parts[1] . $delim : '';

        if ($this->folders === null) {
            $this->folders = $storage->list_folders('', '*', null, null, true);
        }
        foreach (self::NAMES as $name) {
            foreach ($this->folders as $f) {
                if (str_starts_with($f, $root) && strtolower(substr($f, strlen($root))) === $name) {
                    return $f;
                }
            }
        }

        // the account has none: create it (the mirror creates it at the provider too)
        $folder = $root . self::LOCAL;
        if ($storage->create_folder($folder, true)) {
            $this->folders[] = $folder;
            return $folder;
        }

        return null;
    }

    /** uid => real folder of each message of a virtual folder, as Dovecot sees it. */
    private function real_folders($mbox, $uids)
    {
        $storage = rcmail::get_instance()->get_storage();
        $storage->check_connection();
        $conn = $storage->conn;
        $out = [];
        if (!$conn->select($mbox)) {
            return $out;
        }
        [$code, $resp] = $conn->execute('UID FETCH', [$conn->compressMessageSet($uids), '(UID X-MAILBOX)']);
        if ($code != rcube_imap_generic::ERROR_OK) {
            return $out;
        }
        foreach (preg_split('/\r?\n(?=\* )/', (string) $resp) as $line) {
            if (!preg_match('/^\* \d+ FETCH \((.*)\)\s*$/s', trim($line), $m)) {
                continue;
            }
            $str = $m[1];
            $uid = $box = null;
            while (($t = rcube_imap_generic::tokenizeResponse($str, 2)) && count($t) == 2) {
                if (strtoupper($t[0]) === 'UID') {
                    $uid = (int) $t[1];
                } elseif (strtoupper($t[0]) === 'X-MAILBOX') {
                    $box = $t[1];
                }
            }
            if ($uid && is_string($box) && $box !== '') {
                $out[$uid] = $box;
            }
        }

        return $out;
    }

    /** The AJAX action: archive the posted messages, answered like a move. */
    public function archive()
    {
        $rcmail = rcmail::get_instance();
        if (!$rcmail->output->ajax_call) {
            return;
        }
        $this->add_texts('localization/');

        $storage = $rcmail->get_storage();
        $delim = $storage->get_hierarchy_delimiter();
        $threading = (bool) $storage->get_threading();
        $search_request = rcube_utils::get_input_string('_search', rcube_utils::INPUT_GPC);
        $from_show = !empty($_POST['_from']) && $_POST['_from'] == 'show';
        $old_count = $from_show ? 0 : $storage->count(null, $threading ? 'THREADS' : 'ALL');
        $sort_col = rcmail_action_mail_index::sort_column();
        $sort_ord = rcmail_action_mail_index::sort_order();

        // the response handler of 'move' runs on the client
        $rcmail->action = 'move';
        $this->result = ['error' => false, 'sources' => [], 'destinations' => []];
        $count = 0;
        $all = false;

        foreach (rcmail_action::get_uids(null, null, $multifolder, rcube_utils::INPUT_POST) as $mbox => $uids) {
            if (self::is_archive($mbox, $delim)) {
                continue;
            }
            if ($uids === '*') {
                $all = true;
                $uids = $storage->index($mbox, $sort_col, $sort_ord)->get();
            }
            $uids = array_map('intval', (array) $uids);
            $real = str_starts_with($mbox, 'Merged' . $delim) ? $this->real_folders($mbox, $uids) : [];

            $by_target = [];
            foreach ($uids as $uid) {
                $from = $real[$uid] ?? $mbox;
                if (str_starts_with($mbox, 'Merged' . $delim) && !isset($real[$uid])) {
                    $this->result['error'] = true;  // its account unknown: left where it is
                    continue;
                }
                if (self::is_archive($from, $delim)) {
                    continue;
                }
                if ($target = $this->target($from, $delim)) {
                    $by_target[$target][] = $uid;
                } else {
                    $this->result['error'] = true;
                }
            }
            foreach ($by_target as $target => $list) {
                if ($storage->move_message($list, $target, $mbox)) {
                    $count += count($list);
                    $this->result['sources'][] = $mbox;
                    $this->result['destinations'][] = $target;
                } else {
                    $this->result['error'] = true;
                }
            }
        }
        $this->result['sources'] = array_unique($this->result['sources']);
        $this->result['destinations'] = array_unique($this->result['destinations']);

        if ($this->result['error'] && !$count) {
            if (!$from_show) {
                $rcmail->output->command('list_mailbox');
            }
            $rcmail->output->show_message($this->gettext('archiveerror'), 'warning');
            $rcmail->output->send();
        }

        if ($search_request && $storage->get_search_set()) {
            $_SESSION['search'] = $storage->refresh_search();
        }

        if ($from_show) {
            if ($next = rcube_utils::get_input_string('_next_uid', rcube_utils::INPUT_GPC)) {
                $rcmail->output->command('show_message', $next);
            } else {
                $rcmail->output->command('command', 'list');
            }
            $rcmail->output->send();
        }

        // as the archive plugin (and the core move) do: counts, and rows from the next page
        $mbox = $storage->get_folder();
        $msg_count = $storage->count(null, $threading ? 'THREADS' : 'ALL');
        $page_size = $storage->get_pagesize();
        $page = $storage->get_page();
        $remaining = $msg_count - $page_size * ($page - 1);
        $nextpage_count = $old_count - $page_size * $page;
        $jump_back = false;
        if ($page > 1 && $remaining == 0) {
            $page--;
            $storage->set_page($page);
            $_SESSION['page'] = $page;
            $jump_back = true;
        }

        foreach (array_merge($this->result['sources'], $this->result['destinations']) as $folder) {
            rcmail_action_mail_index::send_unread_count($folder, true);
        }
        $rcmail->output->set_env('messagecount', $msg_count);
        $rcmail->output->set_env('current_page', $page);
        $rcmail->output->set_env('pagecount', ceil($msg_count / $page_size));
        $rcmail->output->set_env('exists', $storage->count($mbox, 'EXISTS', true));
        $rcmail->output->command('set_rowcount', rcmail_action_mail_index::get_messagecount_text($msg_count), $mbox);

        if ($threading) {
            $count = rcube_utils::get_input_string('_count', rcube_utils::INPUT_POST);
        }
        if (!empty($_POST['_refresh'])) {
            $rcmail->output->command('refresh_list');
        } elseif ($count && !$all && ($jump_back || $nextpage_count > 0)) {
            $count = $jump_back ? null : min($nextpage_count, $count);
            rcmail_action_mail_index::js_message_list($storage->list_messages($mbox, null, $sort_col, $sort_ord, $count), false);
        }

        $rcmail->output->show_message($this->gettext($this->result['error'] ? 'archiveerror' : 'archived'),
            $this->result['error'] ? 'warning' : 'confirmation');
        $rcmail->output->send();
    }
}
