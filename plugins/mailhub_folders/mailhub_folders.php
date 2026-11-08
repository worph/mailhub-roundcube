<?php

/**
 * MailHub: the merged folders are the mailbox's main ones in the webmail.
 *
 * mailhub-mirror keeps Merged/All inboxes, Trash and Junk (Dovecot virtual folders)
 * over every account's folder of that kind plus the local one, and saves what is
 * moved into them in the local folder. So here:
 *   - the mail view opens on Merged/All inboxes, shown first as "Inbox";
 *   - Merged/Trash and Merged/Junk are Roundcube's Trash and Junk: Delete moves a
 *     message there (into the local Trash, as before), deleting inside it or Empty
 *     deletes for good, at the provider too for an account's own Trash;
 *   - the local INBOX, Trash and Junk are not listed: what they hold shows in the
 *     merged ones. They stay reachable by URL (?_mbox=INBOX).
 *
 * Nothing changes while the merged folders do not exist yet (an older mirror).
 */
class mailhub_folders extends rcube_plugin
{
    public $task = 'mail';

    private const INBOX = 'Merged/All inboxes';
    private const SPECIAL = ['trash' => 'Merged/Trash', 'junk' => 'Merged/Junk'];
    private const HIDDEN = ['INBOX', 'Trash', 'Junk'];

    private $active = null;

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('render_mailboxlist', [$this, 'render_mailboxlist']);
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

    /** Merged/All inboxes first, as "Inbox"; the local INBOX, Trash and Junk left out. */
    public function render_mailboxlist($args)
    {
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
        $inbox['class'] = 'inbox';
        $inbox['folders'] = [];
        $args['list'] = [$child => $inbox] + $list;

        return $args;
    }
}
