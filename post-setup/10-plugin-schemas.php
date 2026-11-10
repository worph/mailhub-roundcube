#!/usr/bin/env php
<?php
// Create / upgrade the DB tables of the Kolab plugins and mailhub_collect. Roundcube's entrypoint
// only manages the core schema; plugins installed outside composer need this.
define('INSTALL_PATH', '/var/www/html/');
require_once INSTALL_PATH . 'program/include/clisetup.php';

$packages = [
    'libkolab'        => INSTALL_PATH . 'plugins/libkolab/SQL',
    'calendar-caldav' => INSTALL_PATH . 'plugins/calendar/drivers/caldav/SQL',
    'mailhub_collect' => INSTALL_PATH . 'plugins/mailhub_collect/SQL',
];

// Upstream bugs in the initial scripts, keyed by "<package>/<provider>".
$fixes = [
    // libkolab 3.6.1 sqlite: `ix_contact_type` is created on kolab_cache_contact
    // and again on kolab_cache_dav_contact; SQLite index names are global.
    'libkolab/sqlite' => [
        'CREATE INDEX ix_contact_type ON kolab_cache_dav_contact'
            => 'CREATE INDEX ix_dav_contact_type ON kolab_cache_dav_contact',
    ],
];

$db = rcmail_utils::db();
foreach ($packages as $package => $dir) {
    $row = $db->fetch_assoc($db->query(
        'SELECT value FROM ' . $db->table_name('system', true) . ' WHERE name = ?',
        $package . '-version'
    ));
    if (!empty($row['value'])) {
        // Some packages ship no upgrade scripts for every engine (calendar-caldav
        // has none for sqlite); db_update treats that as an error.
        if (is_dir("$dir/{$db->db_provider}")) {
            echo "Checking schema updates for $package (at {$row['value']})\n";
            rcmail_utils::db_update($dir, $package, null, ['errors' => true]);
        }
        continue;
    }

    echo "Initializing schema for $package\n";
    $provider = $db->db_provider;
    $sql = file_get_contents("$dir/$provider.initial.sql");
    if ($sql === false) {
        fwrite(STDERR, "No $provider.initial.sql in $dir\n");
        exit(1);
    }
    $sql = strtr($sql, $fixes["$package/$provider"] ?? []);
    if (!$db->exec_script($sql)) {
        fwrite(STDERR, "Schema init failed for $package: " . $db->is_error() . "\n");
        exit(1);
    }
}
