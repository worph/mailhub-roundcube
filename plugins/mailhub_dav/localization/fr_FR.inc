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

$labels['subs'] = 'Agendas abonnés';
$labels['subsnone'] = 'Aucun pour l\'instant. Ajoutez Google, Outlook ou tout autre agenda ci-dessous : il s\'affiche ici, sur votre téléphone et pour l\'assistant IA, tenu à jour (toutes les 15 minutes), en lecture seule.';
$labels['subsremove'] = 'Retirer (seulement la copie de MailHub)';
$labels['subsadd'] = 'S\'abonner à un agenda';
$labels['subsurl'] = 'Adresse de l\'agenda';
$labels['subsname'] = 'Nom et couleur';
$labels['subsnameph'] = 'par ex. Travail';
$labels['subsuser'] = 'Utilisateur (CalDAV seulement)';
$labels['subshelp'] = 'Google Agenda : Paramètres → votre agenda → Intégrer l\'agenda → « Adresse secrète au format iCal » (gardez-la privée : elle suffit pour lire l\'agenda). Outlook : Paramètres → Calendrier → Calendriers partagés → Publier un calendrier → lien ICS. Pour un serveur CalDAV, indiquez son adresse avec votre utilisateur et mot de passe : l\'adresse du compte suffit s\'il n\'a qu\'un agenda. Enregistrer met aussi à jour tous les abonnements.';
$labels['subsexternal'] = 'Les abonnements sont gardés dans l\'agenda intégré de MailHub : ils ne s\'affichent pas tant que vous utilisez un autre serveur d\'agenda.';
$labels['subswaiting'] = 'en attente de la première mise à jour…';
$labels['subserror'] = 'pas mis à jour :';
$labels['subsok'] = 'mis à jour $when · $n événements';
$labels['subsjustnow'] = 'à l\'instant';
$labels['subsminutes'] = 'il y a $n min';
$labels['subshours'] = 'il y a $n h';

$messages = [];
$messages['invalid'] = 'Indiquez une URL commençant par https:// et un utilisateur.';
$messages['testfailed'] = 'Le serveur n\'a pas été enregistré :';
$messages['unreachable'] = 'le serveur est injoignable.';
$messages['badlogin'] = 'l\'utilisateur ou le mot de passe a été refusé.';
$messages['notdav'] = 'cette URL ne répond pas comme un serveur CalDAV/CardDAV (pour Baïkal elle se termine par /dav.php).';
$messages['clientrefused'] = 'le serveur répond mais refuse le client agenda. Si cette adresse passe par un proxy ou un CDN, utilisez l\'adresse directe du serveur (par ex. https://baikal.<votre domaine>/dav.php plutôt que https://baikal-<votre domaine>/dav.php).';
$messages['subsinvalid'] = 'Indiquez une adresse d\'agenda commençant par https:// (ou webcal://).';
$messages['subsfailed'] = 'L\'abonnement n\'a pas été ajouté :';
$messages['subsgone'] = 'rien à cette adresse, ou accès refusé : pour Google, recopiez l\'« Adresse secrète au format iCal »';
$messages['subsnotical'] = 'cette adresse ne donne pas un agenda iCal. Pour Google, utilisez l\'« Adresse secrète au format iCal » (qui finit par .ics), pas le lien public ou d\'intégration';
$messages['subsnotcaldav'] = 'cette adresse ne répond pas comme un agenda ou un compte CalDAV';
$messages['subsnocalendar'] = 'ce compte n\'a pas d\'agenda d\'événements';
$messages['subspick'] = 'ce compte a plusieurs agendas ; ajoutez l\'adresse de celui que vous voulez :';
$messages['subsnotsaved'] = 'Les abonnements n\'ont pas pu être transmis au miroir (son dossier n\'est pas accessible en écriture).';
