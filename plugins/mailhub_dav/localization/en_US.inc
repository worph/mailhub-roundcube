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

$labels['subs'] = 'Calendar subscriptions';
$labels['subsnone'] = 'None yet. Add Google, Outlook or any other calendar below: it is shown here, on your phone and to the AI assistant, kept up to date (every 15 minutes), read-only.';
$labels['subsremove'] = 'Remove (only MailHub\'s copy)';
$labels['subsadd'] = 'Add a calendar subscription';
$labels['subsurl'] = 'Calendar address';
$labels['subsname'] = 'Name and colour';
$labels['subsnameph'] = 'e.g. Work';
$labels['subsuser'] = 'Username (CalDAV only)';
$labels['subshelp'] = 'Google Calendar: Settings → your calendar → Integrate calendar → "Secret address in iCal format" (keep it private: anyone with it can read the calendar). Outlook: Settings → Calendar → Shared calendars → Publish a calendar → ICS link. For a CalDAV server, give its address with your username and password: an account address is enough when it has one calendar. Saving also updates every subscription now.';
$labels['subsexternal'] = 'Subscriptions are kept in MailHub\'s built-in calendar: they are not shown while you use another calendar server.';
$labels['subswaiting'] = 'waiting for the first update…';
$labels['subserror'] = 'not updated:';
$labels['subsok'] = 'updated $when · $n events';
$labels['subsjustnow'] = 'just now';
$labels['subsminutes'] = '$n min ago';
$labels['subshours'] = '$n h ago';

$messages = [];
$messages['invalid'] = 'Enter a server URL starting with https:// and a username.';
$messages['testfailed'] = 'The server was not saved:';
$messages['unreachable'] = 'the server could not be reached.';
$messages['badlogin'] = 'the username or password was refused.';
$messages['notdav'] = 'this URL does not answer as a CalDAV/CardDAV server (for Baïkal it ends with /dav.php).';
$messages['clientrefused'] = 'the server answered, but refused the calendar client. If this address goes through a proxy or CDN, use the server\'s direct address (e.g. https://baikal.<your domain>/dav.php rather than https://baikal-<your domain>/dav.php).';
$messages['subsinvalid'] = 'Enter a calendar address starting with https:// (or webcal://).';
$messages['subsfailed'] = 'The subscription was not added:';
$messages['subsgone'] = 'nothing at this address, or access refused: for Google, copy the "Secret address in iCal format" again';
$messages['subsnotical'] = 'this address does not give an iCal calendar. For Google, use the "Secret address in iCal format" (ending in .ics), not the public or embed link';
$messages['subsnotcaldav'] = 'this address does not answer as a CalDAV calendar or account';
$messages['subsnocalendar'] = 'this account has no event calendar';
$messages['subspick'] = 'this account has several calendars; add the address of the one you want:';
$messages['subsnotsaved'] = 'The subscriptions could not be saved for the mirror (its folder is not writable).';
