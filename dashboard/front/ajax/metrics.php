<?php
/** AJAX JSON para atualização automática do dashboard moderno. */
include ("../../../../inc/includes.php");
include ("../../../../inc/config.php");
include_once ("../../inc/metrics.class.php");

global $DB, $CFG_GLPI;
Session::checkLoginUser();
PluginDashboardModernMetrics::securityHeaders(true);
if (!PluginDashboardModernMetrics::hasTicketReadRight()) {
   PluginDashboardModernMetrics::jsonResponse(array('ok' => false, 'error' => 'ticket_read_required'), 403);
}

$id_ent = null;
$id_grp = 0;
$metricsDir = dirname(__DIR__) . "/metrics";

if (isset($_REQUEST['ent'])) {
   $requested_ent = PluginDashboardModernMetrics::intValue($_REQUEST['ent'], 0, 0, 2147483647);
   if (PluginDashboardModernMetrics::canSeeEntity($requested_ent)) {
      $id_ent = $requested_ent;
   } else {
      PluginDashboardModernMetrics::jsonResponse(array('ok' => false, 'error' => 'entity_not_allowed'), 403);
   }
} elseif (isset($_REQUEST['grp'])) {
   $requested_grp = PluginDashboardModernMetrics::intValue($_REQUEST['grp'], 0, 1, 2147483647);
   if (PluginDashboardModernMetrics::canSeeGroup($DB, $requested_grp)) {
      $id_grp = $requested_grp;
   } else {
      PluginDashboardModernMetrics::jsonResponse(array('ok' => false, 'error' => 'group_not_allowed'), 403);
   }
}

include ($metricsDir . "/scope.inc.php");

$settings = PluginDashboardModernMetrics::getSettings($DB);
$scopeLabel = isset($actent) && $actent ? $actent : ('GLPI ' . (isset($CFG_GLPI['version']) ? $CFG_GLPI['version'] : '10'));
$periodLabel = isset($period_name) ? $period_name : __('Total', 'dashboard');
$context = array(
   'period' => isset($period) ? $period : '',
   'entidade' => isset($entidade) ? $entidade : '',
   'id_grp' => (int)$id_grp,
   'id_ent' => $id_ent,
   'scope_label' => $scopeLabel,
   'period_label' => $periodLabel
);

$dashboard = PluginDashboardModernMetrics::build($DB, $CFG_GLPI, $context, $settings, true);
PluginDashboardModernMetrics::jsonResponse(array('ok' => true, 'dashboard' => $dashboard), 200);
?>
