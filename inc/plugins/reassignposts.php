<?php
// Sicherheitsüberprüfung, um direkten Zugriff zu verhindern.
if(!defined('IN_MYBB')) {
    die('Ein 500 Error ist allein dein eigener!');
}

// ### Hooks ###
$plugins->add_hook("admin_config_menu", "reassignposts_admin_menu");
$plugins->add_hook("admin_config_action_handler", "reassignposts_admin_action_handler");
$plugins->add_hook("admin_config_permissions", "reassignposts_admin_permissions");
$plugins->add_hook("admin_load", "reassignposts_admin_load");


function reassignposts_info()
{
    return [
        'name'          => 'Posts reassignen',
        'description'   => 'Ein einfaches Plugin, um alle Posts eines alten Benutzers einem neuen zuzuweisen.',
        'website'       => 'https://shadow.or.at/index.php',
        'author'        => 'Dani',
        'authorsite'    => 'https://github.com/ShadowOfDestiny',
        'version'       => '1.0',
        'guid'          => '',
        'codename'      => 'reassignposts',
        'compatibility' => '18*'
    ];
}

function reassignposts_install()
{
    global $db;
    
    $query = $db->simple_select("settinggroups", "gid", "name = 'reassignposts'");
    if($db->num_rows($query) == 0) {
        $setting_group = [
            'name' => 'reassignposts',
            'title' => 'Posts reassignen',
            'description' => 'Einstellungen und Tools für das Posts reassignen Plugin.',
            'disporder' => 5,
            'isdefault' => 0
        ];
        $gid = $db->insert_query("settinggroups", $setting_group);
        
        $settings = [
            'reassignposts_version' => [
                'title' => 'Plugin Version',
                'description' => 'Interne Version des Plugins',
                'optionscode' => 'text',
                'value' => '1.0',
                'disporder' => 1
            ]
        ];
        foreach($settings as $name => $setting) {
            $setting['name'] = $name;
            $setting['gid'] = $gid;
            $db->insert_query('settings', $setting);
        }

        rebuild_settings();
    }
}

function reassignposts_is_installed()
{
    global $db;
    $query = $db->simple_select("settinggroups", "gid", "name = 'reassignposts'");
    return $db->num_rows($query) > 0;
}

function reassignposts_uninstall()
{
    global $db;
    
    $db->delete_query('settings', "name LIKE 'reassignposts_%'");
    rebuild_settings();
    $db->delete_query('settinggroups', "name = 'reassignposts'");
}

function reassignposts_activate()
{
    // Keine Hooks, die aktiviert werden müssen
}

function reassignposts_deactivate()
{
    // Keine Hooks, die deaktiviert werden müssen
}

// ### ADMIN-CP SEITENSTRUKTUR ###

function reassignposts_admin_menu(&$sub_menu)
{
    $sub_menu[] = [
        'id' => 'reassignposts',
        'title' => 'Posts reassignen',
        'link' => 'index.php?module=config-reassignposts'
    ];
}

function reassignposts_admin_action_handler(&$actions)
{
    $actions['reassignposts'] = [
        'active' => 'reassignposts',
        'file' => 'reassignposts_admin_page'
    ];
}

function reassignposts_admin_permissions(&$admin_permissions)
{
    $admin_permissions['reassignposts'] = 'Darf Posts zuweisen?';
}

function reassignposts_admin_load()
{
    global $page, $mybb;
    if ($page->active_action == 'reassignposts') {
        reassignposts_admin_page();
    }
}

function reassignposts_admin_page()
{
    global $mybb, $db, $page, $lang, $form, $form_container;

    $lang->load('reassignposts');

    $page->add_breadcrumb_item('Posts reassignen', 'index.php?module=config-reassignposts');
    
    // Verarbeitet das Formular nach dem Absenden
    if ($mybb->request_method == 'post') {
        verify_post_check($mybb->input['my_post_key']);
        
        $old_username = $mybb->input['old_username'];
        $new_uid = (int) $mybb->input['new_uid'];
        
        if (empty($old_username) || $new_uid <= 0) {
            flash_message("Ungültige Angaben. Bitte gib einen alten Benutzernamen und eine neue Benutzer-ID ein.", "error");
            admin_redirect("index.php?module=config-reassignposts");
        }
        
        $new_user = get_user($new_uid);
        if (!$new_user) {
            flash_message("Der neue Benutzer mit der ID {$new_uid} existiert nicht.", "error");
            admin_redirect("index.php?module=config-reassignposts");
        }
        
        // Zuweisung aller Posts in der Datenbank
        $db->update_query('posts', ['uid' => $new_uid, 'username' => $db->escape_string($new_user['username'])], "username = '{$db->escape_string($old_username)}'");
        $posts_updated_count = $db->affected_rows();
        
        if ($posts_updated_count > 0) {
            // Aktualisierung der Postanzahl des neuen Benutzers
            $new_post_count = $db->fetch_field($db->simple_select('posts', 'COUNT(*) AS count', "uid = {$new_uid}"), 'count');
            $db->update_query('users', ['postnum' => $new_post_count], "uid = {$new_uid}");
            
            // Aktualisiere letzte Beitrag-UID in den Threads
            $db->update_query('threads', ['lastposteruid' => $new_uid, 'lastposter' => $db->escape_string($new_user['username'])], "lastposter = '{$db->escape_string($old_username)}'");
            
            // Aktualisiere partners_username und partners in der inplayscenes-Tabelle
            $inplayscenes_updated_count = 0;
            $inplayscenes_query = $db->simple_select('inplayscenes', 'tid, partners, partners_username', "partners_username LIKE '%" . $db->escape_string($old_username) . "%'");
            
            while ($row = $db->fetch_array($inplayscenes_query)) {
                $usernames = array_map('trim', explode(',', $row['partners_username']));
                $partners_uids = array_map('trim', explode(',', $row['partners']));
                
                $old_username_found_in_array = false;
                foreach ($usernames as $key => $username) {
                    if (trim($username) == $old_username) {
                        $usernames[$key] = $new_user['username'];
                        $partners_uids[$key] = $new_uid;
                        $old_username_found_in_array = true;
                    }
                }
                
                if ($old_username_found_in_array) {
                    $new_partners_username = implode(', ', $usernames);
                    $new_partners_uids = implode(',', $partners_uids);
                    
                    $db->update_query('inplayscenes', ['partners_username' => $db->escape_string($new_partners_username), 'partners' => $db->escape_string($new_partners_uids)], "tid = {$row['tid']}");
                    $inplayscenes_updated_count++;
                }
            }
            
            flash_message("Es wurden {$posts_updated_count} Posts vom Benutzernamen '{$old_username}' erfolgreich auf '{$new_user['username']}' zugewiesen. Die Post-Zahl des neuen Benutzers wurde aktualisiert. Threads aktualisiert: {$db->affected_rows()}. Szenen aktualisiert: {$inplayscenes_updated_count}.", "success");
        } else {
            flash_message("Es wurden keine Posts mit dem Benutzernamen '{$old_username}' gefunden.", "error");
        }
        
        admin_redirect("index.php?module=config-reassignposts");
    }
    
    // Seitenausgabe (Formular)
    $page->output_header('Posts reassignen');
    
    $form = new Form('index.php?module=config-reassignposts', 'post');
    
    $form_container = new FormContainer('Posts einem neuen Benutzer zuweisen');
    $form_container->output_row('Alter Benutzername', 'Der alte Benutzername.', $form->generate_text_box('old_username', '', ['id' => 'old_username']), 'old_username');
    $form_container->output_row('Neue Benutzer-ID', 'Die ID des Benutzers, dem die Posts zugewiesen werden sollen.', $form->generate_text_box('new_uid', '', ['id' => 'new_uid']), 'new_uid');
    $form_container->end();
    
    $buttons[] = $form->generate_submit_button('Posts reassignen');
    $form->output_submit_wrapper($buttons);
    
    $form->end();
    $page->output_footer();
    
    die();
}