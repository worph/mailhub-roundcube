<?php

$labels = [];
$labels['section'] = 'Phones & computers';
$labels['sectiontitle'] = 'The login of this mailbox for mail apps and calendar sync';
$labels['intro'] = 'The webmail opens with your server sign-in. Mail apps (Thunderbird, iPhone Mail, K-9) and calendar & contacts sync (DAVx⁵, iPhone) cannot use it: they log in with the details below.';
$labels['setupaddress'] = 'Email address to enter at setup';
$labels['setupaddresshint'] = 'Mail apps ask for an email address before showing their server settings: enter the one above, then choose the manual setup. It only names this account in the app and is never sent from. Don\'t enter one of your own addresses: the app would look up that provider\'s servers instead of MailHub\'s.';
$labels['imapserver'] = 'IMAP server';
$labels['username'] = 'User name';
$labels['password'] = 'Password';
$labels['davurl'] = 'Calendar & contacts (CalDAV / CardDAV)';
$labels['smtp'] = 'Sending (SMTP)';
$labels['smtpalt'] = 'or, if port 465 is blocked';
$labels['smtphint'] = 'Sending uses the same username and password as IMAP. Add each of your mail accounts\' addresses as an identity of this one account: each address sends through its own provider. Keep the app\'s default Sent folder.';
$labels['show'] = 'Show';
$labels['nopass'] = 'Not set yet';
$labels['nohost'] = 'Not available yet: the server has no trusted certificate for it. Try again in a few minutes.';
$labels['ownerpass'] = 'This password is one of the app\'s settings on your server (MAILHUB_PASSWORD), so it is not replaced here.';
$labels['regenerate'] = 'Replace the password';
$labels['regeneratehint'] = 'Replacing it logs out every phone and computer that uses the old one: enter the new one there.';
$labels['regenerated'] = 'New password set. It takes effect within a few seconds.';
$labels['signedinas'] = 'Signed in as $name with your server account (mailbox $mailbox). The addresses you send from are under Settings → Identities.';
$labels['placeholdername'] = 'Add a mail account to send';
