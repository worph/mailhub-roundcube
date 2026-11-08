<?php

$labels = [];
$labels['section'] = 'Calendar & contacts';
$labels['server'] = 'Calendar server';
$labels['builtin'] = 'Using MailHub\'s built-in calendar & contacts — nothing to set up. Your phone syncs with it too (see the app\'s tips).';
$labels['status'] = 'Status';
$labels['connected'] = 'Using another server:';
$labels['notconnected'] = 'No calendar server — connect one to get a calendar, invitation buttons and synced contacts.';
$labels['url'] = 'Other server URL';
$labels['user'] = 'Username';
$labels['pass'] = 'Password';
$labels['passunchanged'] = 'unchanged';
$labels['help'] = 'Optional: to keep your calendar and contacts on another CalDAV/CardDAV server (Nextcloud, Radicale…), enter it here. Clear the URL to go back to the built-in one.';

$messages = [];
$messages['invalid'] = 'Enter a server URL starting with https:// and a username.';
$messages['testfailed'] = 'The server was not saved:';
$messages['unreachable'] = 'the server could not be reached.';
$messages['badlogin'] = 'the username or password was refused.';
$messages['notdav'] = 'this URL does not answer as a CalDAV/CardDAV server (for Baïkal it ends with /dav.php).';
$messages['clientrefused'] = 'the server answered, but refused the calendar client. If this address goes through a proxy or CDN, use the server\'s direct address (e.g. https://baikal.<your domain>/dav.php rather than https://baikal-<your domain>/dav.php).';
