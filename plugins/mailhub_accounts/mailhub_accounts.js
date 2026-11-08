/**
 * MailHub: Settings → Mail accounts. One card per account, each action applied at
 * once (no global Save); the status refreshes by itself while the page is open.
 */
window.rcmail && rcmail.addEventListener('init', function () {
    if (rcmail.env.action != 'plugin.mailhub_accounts') {
        return;
    }

    var L = function (k, vars) {
        var s = rcmail.get_label(k, 'mailhub_accounts');
        $.each(vars || {}, function (n, v) { s = s.split('$' + n).join(v); });
        return s;
    };

    // Post an action; the server answers with a message, and reloads the page when
    // something changed. A lock shows the message meanwhile (logins take a moment).
    var act = function (op, data, waitLabel) {
        var lock = rcmail.set_busy(true, waitLabel || 'loading');
        rcmail.http_post('plugin.mailhub_accounts-do', $.extend({_op: op}, data), lock);
    };

    var dialogCount = 0;

    // A form kept in the page, shown in a dialog; `ok` collects it and posts.
    var dialog = function (id, title, okLabel, ok, prepare) {
        var form = $('#' + id).clone(true).removeAttr('style').removeAttr('id');
        // the copy's fields get their own ids, so its labels point at them
        var suffix = '-' + (++dialogCount);
        form.find('[id]').each(function () { this.id += suffix; });
        form.find('label[for]').each(function () { $(this).attr('for', $(this).attr('for') + suffix); });
        prepare && prepare(form);
        var buttons = [
            {text: okLabel, 'class': 'mainaction save', click: function (e, ui, dlg) {
                if (ok(form) !== false) {
                    (dlg || this).dialog ? $(dlg || this).dialog('close') : null;
                }
            }},
            {text: rcmail.get_label('cancel'), 'class': 'cancel', click: function (e, ui, dlg) {
                $(dlg || this).dialog('close');
            }}
        ];
        rcmail.show_popup_dialog(form, title, buttons, {width: 520, modal: true});
        setTimeout(function () { form.find('input:visible').first().focus(); }, 100);
        return form;
    };

    // ---- Add an account
    var toggleOther = function (form) {
        var p = form.find('[name=preset]').val();
        form.find('.mh-other').toggle(p == 'other');
        form.find('.mh-passhelp').text(rcmail.env.mh_help[p] || '');
    };
    $('#mh-add-btn').on('click', function () {
        dialog('mh-dialog-add', L('addtitle'), L('addok'), function (form) {
            var data = {};
            form.find('input, select').each(function () { data['_new[' + this.name + ']'] = $(this).val(); });
            act('add', data, 'mailhub_accounts.checking');
        }, function (form) {
            form.find('[name=preset]').on('change', function () { toggleOther(form); });
            toggleOther(form);
        });
    });

    // ---- Per account
    $('#mh-cards').on('click', 'button[data-op]', function () {
        var card = $(this).closest('.mh-card'), slug = card.data('slug'), email = card.data('email');
        switch ($(this).data('op')) {
            case 'syncnow':
                act('syncnow', {_slug: slug});
                break;
            case 'password':
                dialog('mh-dialog-pass', L('changepasstitle', {email: email}), rcmail.get_label('save'), function (form) {
                    act('password', {_slug: slug, _pass: form.find('input').val()}, 'mailhub_accounts.checking');
                }, function (form) {
                    form.find('.mh-hint').text(rcmail.env.mh_help[card.data('preset')] || rcmail.env.mh_help.other);
                });
                break;
            case 'rename':
                dialog('mh-dialog-name', L('renametitle', {email: email}), rcmail.get_label('save'), function (form) {
                    act('rename', {_slug: slug, _name: form.find('input').val()});
                }, function (form) {
                    form.find('input').val(card.data('name') || '');
                });
                break;
            case 'remove':
                var text = $('<div>').append($('<p>').text(L('removeconfirm', {email: email})))
                    .append($('<p class="mh-hint">').text(L('removehint')));
                rcmail.show_popup_dialog(text, L('removetitle', {email: email}), [
                    {text: L('removeok'), 'class': 'delete mainaction', click: function (e, ui, dlg) {
                        act('remove', {_slug: slug});
                        $(dlg || this).dialog('close');
                    }},
                    {text: rcmail.get_label('cancel'), 'class': 'cancel', click: function (e, ui, dlg) {
                        $(dlg || this).dialog('close');
                    }}
                ], {width: 520});
                break;
        }
    });

    // ---- Answers from the server
    rcmail.addEventListener('plugin.mh_reload', function () {
        setTimeout(function () { location.reload(); }, 600);
    });
    rcmail.addEventListener('plugin.mh_status', function (data) {
        $.each(data || {}, function (slug, st) {
            var card = $('.mh-card').filter(function () { return $(this).data('slug') == slug; });
            card.find('.mh-status').attr('class', 'mh-status mh-' + st.state);
            card.find('.mh-status-text').text(st.text);
            card.toggleClass('mh-badlogin', !!st.badlogin);
        });
    });

    // The status refreshes while the page is open (first downloads count up).
    var refresh = function () {
        if ($('.mh-card').length) {
            rcmail.http_post('plugin.mailhub_accounts-status', {}, false);
        }
    };
    setInterval(refresh, 15000);
});
