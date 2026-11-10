/**
 * MailHub: Settings → AI assistant. Each switch is saved as soon as it changes (no
 * global Save); the MCP server reads the settings on its next request.
 */
window.rcmail && rcmail.addEventListener('init', function () {
    if (rcmail.env.action != 'plugin.mailhub_mcp') {
        return;
    }
    var form = $('#mhm-form');

    var save = function () {
        var data = {
            _enabled: form.find('[name=enabled]').prop('checked') ? '1' : '0',
            _write: form.find('[name=write]').prop('checked') ? '1' : '0',
            _disabled: []
        };
        form.find('input[data-tool]').each(function () {
            if (!this.checked) {
                data._disabled.push($(this).data('tool'));
            }
        });
        // tools greyed out while the MCP or write access is off (their own switch stays)
        $('#mhm-tools').toggleClass('mhm-off', data._enabled != '1').toggleClass('mhm-nowrite', data._write != '1');
        rcmail.http_post('plugin.mailhub_mcp-save', data, rcmail.set_busy(true, 'loading'));
    };
    form.on('change', 'input.mhm-switch', save);

    $('#mhm-clear').on('click', function () {
        var text = $('<p>').text(rcmail.get_label('clearconfirm', 'mailhub_mcp'));
        rcmail.show_popup_dialog(text, rcmail.get_label('cleartitle', 'mailhub_mcp'), [
            {text: rcmail.get_label('clearok', 'mailhub_mcp'), 'class': 'delete mainaction', click: function (e, ui, dlg) {
                rcmail.http_post('plugin.mailhub_mcp-save', {_op: 'clear'}, rcmail.set_busy(true, 'loading'));
                $(dlg || this).dialog('close');
            }},
            {text: rcmail.get_label('cancel'), 'class': 'cancel', click: function (e, ui, dlg) {
                $(dlg || this).dialog('close');
            }}
        ], {width: 480});
    });

    rcmail.addEventListener('plugin.mhm_reload', function () {
        setTimeout(function () { location.reload(); }, 600);
    });
});
