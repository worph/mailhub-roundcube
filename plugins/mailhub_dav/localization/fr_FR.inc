<?php

$labels = [];
$labels['section'] = 'Agenda et contacts';
$labels['server'] = 'Serveur d\'agenda';
$labels['builtin'] = 'Agenda et contacts intégrés à MailHub — rien à configurer. Votre téléphone s\'y synchronise aussi (voir les conseils de l\'app).';
$labels['status'] = 'État';
$labels['connected'] = 'Autre serveur utilisé :';
$labels['notconnected'] = 'Aucun serveur d\'agenda — connectez-en un pour avoir un agenda, les boutons d\'invitation et des contacts synchronisés.';
$labels['url'] = 'URL de l\'autre serveur';
$labels['user'] = 'Utilisateur';
$labels['pass'] = 'Mot de passe';
$labels['passunchanged'] = 'inchangé';
$labels['help'] = 'Facultatif : pour garder agenda et contacts sur un autre serveur CalDAV/CardDAV (Nextcloud, Radicale…), indiquez-le ici. Videz l\'URL pour revenir au serveur intégré.';

$messages = [];
$messages['invalid'] = 'Indiquez une URL commençant par https:// et un utilisateur.';
$messages['testfailed'] = 'Le serveur n\'a pas été enregistré :';
$messages['unreachable'] = 'le serveur est injoignable.';
$messages['badlogin'] = 'l\'utilisateur ou le mot de passe a été refusé.';
$messages['notdav'] = 'cette URL ne répond pas comme un serveur CalDAV/CardDAV (pour Baïkal elle se termine par /dav.php).';
$messages['clientrefused'] = 'le serveur répond mais refuse le client agenda. Si cette adresse passe par un proxy ou un CDN, utilisez l\'adresse directe du serveur (par ex. https://baikal.<votre domaine>/dav.php plutôt que https://baikal-<votre domaine>/dav.php).';
