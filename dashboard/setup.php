<?php

function plugin_init_dashboard() {
    global $PLUGIN_HOOKS, $LANG;
    
    $PLUGIN_HOOKS['csrf_compliant']['dashboard'] = true;
    
    Plugin::registerClass('PluginDashboardConfig', [
        'addtabon' => ['Entity']
    ]);  

    $PLUGIN_HOOKS["menu_toadd"]['dashboard'] = array('plugins'  => 'PluginDashboardConfig');
    $PLUGIN_HOOKS['config_page']['dashboard'] = 'front/index.php';
}

function plugin_version_dashboard() {
    global $DB, $LANG;

    return array(
        'name'           => __('Dashboard', 'dashboard'),
        'version'        => '1.0.2',
        'author'         => '<a href="https://forge.glpi-project.org/projects/dashboard"> Stevenes Donato </a>',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://forge.glpi-project.org/projects/dashboard',
        'minGlpiVersion' => '9.4'
    );
}

function plugin_dashboard_check_prerequisites() {
    if (version_compare(GLPI_VERSION, '9.4', '>=')) {
        return true;
    } else {
        echo "GLPI version NOT compatible. Requires GLPI >= 9.4";
        return false; // Retorna false em caso de versão incompatível
    }
}

function plugin_dashboard_check_config($verbose = false) {
    if ($verbose) {
        echo 'Installed / not configured';
    }
    return true;
}

?>
