<?php

/**
 * MailHub: the merged folders are the mailbox's main ones in the webmail.
 *
 * mailhub-mirror keeps Merged/All inboxes, Trash and Junk (Dovecot virtual folders)
 * over every account's folder of that kind plus the local one, and saves what is
 * moved into them in the local folder. So here:
 *   - the mail view opens on Merged/All inboxes, shown first as "Inbox";
 *   - Merged/Trash and Merged/Junk are Roundcube's Trash and Junk: Delete moves a
 *     message there (mailhub_archive sends it into its own account's Trash, or the
 *     local Trash for local mail), deleting inside it or Empty deletes for good, at
 *     the provider too for an account's own Trash;
 *   - Merged/Archive (mailhub-mirror 0.4.5+: every account's Archive folder plus the
 *     local one) is the Archive special folder, listed with Trash and Junk; the
 *     mailhub_archive plugin archives into each account's own Archive folder;
 *   - the local INBOX, Trash, Junk and Archive are not listed: what they hold shows
 *     in the merged ones. They stay reachable by URL (?_mbox=INBOX).
 *
 * And under Live/: each account lists its INBOX first; each account and every
 * folder in it with subfolders starts collapsed (the first time it is listed,
 * remembered in `mailhub_folders_seen`; then Roundcube keeps what the user opens
 * or closes); a folder that is only a container (Live, an address) opens or
 * closes when its name is clicked, not only its arrow.
 *
 * Nothing changes while the merged folders do not exist yet (an older mirror).
 */
class mailhub_folders extends rcube_plugin
{
    public $task = 'mail';

    private const INBOX = 'Merged/All inboxes';
    private const SPECIAL = ['trash' => 'Merged/Trash', 'junk' => 'Merged/Junk'];
    private const HIDDEN = ['INBOX', 'Trash', 'Junk'];
    private const ARCHIVE = 'Merged/Archive';

    private $active = null;

    public function init()
    {
        // Archive as a special folder type (the archive plugin does the same)
        if (!in_array('archive', rcube_storage::$folder_types)) {
            rcube_storage::$folder_types[] = 'archive';
        }
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('render_mailboxlist', [$this, 'render_mailboxlist']);
        $this->include_script('mailhub_folders.js');
    }

    /**
     * Whether the mirror has made the merged folders. Asked of the server until
     * they exist, then remembered for the session.
     */
    private function active()
    {
        if ($this->active === null) {
            if (!empty($_SESSION['mailhub_folders'])) {
                return $this->active = true;
            }
            $storage = rcmail::get_instance()->get_storage();
            $this->active = $storage->folder_exists(self::INBOX)
                && $storage->folder_exists(self::SPECIAL['trash'])
                && $storage->folder_exists(self::SPECIAL['junk']);
            if ($this->active) {
                $_SESSION['mailhub_folders'] = true;
            }
        }

        return $this->active;
    }

    /** Whether the mirror makes Merged/Archive (0.4.5+); remembered once it does. */
    private function archive_view()
    {
        if (empty($_SESSION['mailhub_archive_view'])
            && rcmail::get_instance()->get_storage()->folder_exists(self::ARCHIVE)
        ) {
            $_SESSION['mailhub_archive_view'] = true;
        }

        return !empty($_SESSION['mailhub_archive_view']);
    }

    public function startup($args)
    {
        $rcmail = rcmail::get_instance();
        if (empty($_SESSION['user_id']) || !$this->active()) {
            return $args;
        }

        // Over the user's Special folders setting: deleting must land in a folder
        // that shows every account's Trash.
        foreach (self::SPECIAL as $type => $mbox) {
            $rcmail->config->set($type . '_mbox', $mbox);
        }
        if ($this->archive_view()) {
            $rcmail->config->set('archive_mbox', self::ARCHIVE);
        }

        // Opening the mail view without a folder: the merged inbox, unless another
        // folder was open in this session.
        if ($args['task'] == 'mail' && empty($args['action'])
            && !strlen((string) rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GPC))
            && (empty($_SESSION['mbox']) || $_SESSION['mbox'] === 'INBOX')
        ) {
            $_GET['_mbox'] = self::INBOX;
        }

        return $args;
    }

    /**
     * Merged/All inboxes first, as "Inbox"; the local INBOX, Trash and Junk left
     * out; under Live, INBOX first and new folders with subfolders collapsed.
     */
    public function render_mailboxlist($args)
    {
        if (isset($args['list']['Live']['folders'])) {
            $this->live_accounts($args['list']['Live']['folders']);
        }
        if (!$this->active()) {
            return $args;
        }
        $list = $args['list'];
        $delim = $args['delimiter'];
        [$parent, $child] = explode('/', self::INBOX, 2);
        $parent = str_replace('/', $delim, $parent);

        $inbox = $list[$parent]['folders'][$child] ?? null;
        if (!$inbox) {
            return $args;
        }
        unset($list[$parent]['folders'][$child]);
        foreach (self::HIDDEN as $f) {
            unset($list[$f]);
        }
        if ($this->archive_view()) {
            unset($list['Archive']);
            $this->set_class($list, self::ARCHIVE, 'archive');
        }
        $inbox['class'] = 'inbox';
        $inbox['folders'] = [];
        $args['list'] = [$child => $inbox] + $list;

        return $args;
    }

    /** Each account: INBOX first; it and its folders with subfolders collapsed the first time. */
    private function live_accounts(array &$accounts)
    {
        $parents = [];
        foreach ($accounts as &$account) {
            if (empty($account['folders'])) {
                continue;
            }
            if (isset($account['folders']['INBOX'])) {
                $account['folders'] = ['INBOX' => $account['folders']['INBOX']] + $account['folders'];
            }
            $parents[] = $account['id'];
            $this->parents($account['folders'], $parents);
        }
        unset($account);

        $rcmail = rcmail::get_instance();
        $seen = (array) $rcmail->config->get('mailhub_folders_seen', []);
        $new = array_diff($parents, $seen);
        if (!$new) {
            return;
        }
        $collapsed = (string) $rcmail->config->get('collapsed_folders');
        foreach ($new as $id) {
            $mark = '&' . rawurlencode($id) . '&';
            if (!str_contains($collapsed, $mark)) {
                $collapsed .= $mark;
            }
        }
        // only folders that still exist: a removed account is forgotten
        $prefs = ['collapsed_folders' => $collapsed, 'mailhub_folders_seen' => array_values(array_intersect(array_merge($seen, $new), $parents))];
        $rcmail->user->save_prefs($prefs);
        foreach ($prefs as $k => $v) {
            $rcmail->config->set($k, $v);
        }
    }

    /** The folder's icon (Roundcube gives one to Inbox, Drafts, Sent, Junk and Trash only). */
    private function set_class(array &$list, $id, $class)
    {
        foreach ($list as &$f) {
            if (($f['id'] ?? null) === $id) {
                $f['class'] = trim(($f['class'] ?? '') . ' ' . $class);
                return true;
            }
            if (!empty($f['folders']) && $this->set_class($f['folders'], $id, $class)) {
                return true;
            }
        }

        return false;
    }

    private function parents(array $folders, array &$out)
    {
        foreach ($folders as $f) {
            if (!empty($f['folders'])) {
                $out[] = $f['id'];
                $this->parents($f['folders'], $out);
            }
        }
    }
}
