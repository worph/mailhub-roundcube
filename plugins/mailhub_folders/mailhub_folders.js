/**
 * MailHub: a folder that is only a container (Live, an address: grey, no messages
 * of its own) opens or closes when its name is clicked, like its arrow does.
 * On this server (Local) says what it means when hovered.
 */
window.rcmail && rcmail.addEventListener('init', function () {
    $('#mailboxlist li.mailbox.mailhub-local > a').attr('title', rcmail.get_label('localhint', 'mailhub_folders'));
    $('#mailboxlist').on('click', 'li.mailbox.virtual > a', function (e) {
        var toggle = $(this).siblings('div.treetoggle');
        if (toggle.length) {
            e.preventDefault();
            toggle.trigger('click');
        }
    });
});
