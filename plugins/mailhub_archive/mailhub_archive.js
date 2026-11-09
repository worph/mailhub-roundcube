/**
 * MailHub: Archive (toolbar, and on each message row in place of the flag), the
 * server sending each message to its own account's Archive folder.
 */
(function () {
    function in_archive() {
        var parts = String(rcmail.env.mailbox || '').split(rcmail.env.delimiter || '/');
        if (parts[0] == 'Live' && parts.length >= 3) {
            parts = parts.slice(2);
        } else if (parts[0] == 'Merged' && parts.length >= 2) {
            parts = parts.slice(1);
        }
        return /^archives?$/i.test(parts[0]);
    }

    function archive(uid) {
        if (in_archive()) {
            return;
        }
        var post_data = rcmail.selection_post_data(uid ? { _uid: uid } : undefined),
            open = !uid || (rcmail.message_list && rcmail.message_list.in_selection(uid));
        if (!post_data._uid) {
            return;
        }
        rcmail.enable_command(rcmail.env.message_commands, false);
        rcmail.enable_command('plugin.mailhub_archive', 'plugin.archive', false);
        // removes the rows at once, as Delete does; the server answers like a move
        rcmail.with_selected_messages('move', post_data, null, 'plugin.mailhub_archive');
        if (open) {
            // the message shown in the preview is gone
            rcmail.show_contentframe(false);
        }
    }

    function add_row_button(row) {
        if (!row || !row.obj || in_archive() || $('span.mh-archive', row.obj).length) {
            return;
        }
        var flag = row.flagicon || $('[id^="flagicn"]', row.obj).get(0);
        if (!flag) {
            return;
        }
        var label = rcmail.get_label('mailhub_archive.buttontitle');
        $(row.obj).addClass('mh-archivable');
        $('<span class="mh-archive" role="button">').attr({ title: label, 'aria-label': label })
            .insertAfter(flag)
            .on('mousedown mouseup touchstart touchend', function (e) {
                // not a row click: the row is neither selected nor opened
                e.stopPropagation();
            })
            .on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                archive(row.uid);
            });
    }

    window.rcmail && rcmail.addEventListener('init', function () {
        // plugin.archive too: the name skins call (Elastic2022's Archive button in the
        // message header, shown when env.archive_folder is set)
        var commands = ['plugin.mailhub_archive', 'plugin.archive'];
        rcmail.labels['archive.buttontext'] = rcmail.get_label('mailhub_archive.buttontext');
        $.each(commands, function (i, c) {
            rcmail.register_command(c, function () { archive(); }, !!rcmail.env.uid && !in_archive());
        });

        if (rcmail.message_list) {
            rcmail.message_list.addEventListener('select', function (list) {
                rcmail.enable_command(commands, list.get_selection().length > 0 && !in_archive());
            });
            $.each(rcmail.message_list.rows, function (uid, row) { add_row_button(row); });
        }
        rcmail.addEventListener('insertrow', function (e) { add_row_button(e.row); });

        // a message opened full page: Elastic2022 adds Archive to the message header
        // in the preview pane only; add it here too, before Reply as it does
        var header = $('#message-header');
        if (rcmail.env.action == 'show' && header.length && !$('a.archive', header).length && !in_archive()) {
            var before = $('a.reply', header).first();
            if (before.length) {
                $('<a href="#" class="button archive hide_small">')
                    .attr('title', rcmail.get_label('mailhub_archive.buttontitle'))
                    .append($('<span class="inner">').text(rcmail.get_label('mailhub_archive.buttontext')))
                    .on('click', function (e) { e.preventDefault(); rcmail.command('plugin.mailhub_archive', '', this, e); })
                    .insertBefore(before);
            }
        }
    });
})();
