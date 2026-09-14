<?php
/**
 * DIAGNÓSTICO TEMPORÁRIO — não faz parte do plugin.
 * Verifica se o servidor está executando a versão atual do
 * config.class.php ou uma versão antiga em cache (OPcache).
 * Acesse direto pelo navegador (GET, sem precisar preencher nada).
 * Apagar este arquivo depois de resolver o problema.
 */

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

header('Content-Type: text/plain; charset=utf-8');

$classFile = GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php';

echo "== DIAGNOSTICO WHATSAPPBOT ==\n\n";

echo "-- Arquivo no disco --\n";
echo "Caminho: $classFile\n";
echo "Existe? " . (is_file($classFile) ? 'sim' : 'NAO') . "\n";
echo "Ultima modificacao: " . (is_file($classFile) ? date('Y-m-d H:i:s', filemtime($classFile)) : 'n/a') . "\n";
echo "Tamanho: " . (is_file($classFile) ? filesize($classFile) . ' bytes' : 'n/a') . "\n\n";

echo "-- Classe carregada em memoria pelo PHP --\n";
$methods = get_class_methods('PluginWhatsappbotConfig');
echo "Metodos disponiveis: " . implode(', ', $methods) . "\n";
echo "Tem 'validateFormToken'? " . (in_array('validateFormToken', $methods) ? 'SIM (versao atualizada)' : 'NAO (versao ANTIGA em cache!)') . "\n";
echo "Tem 'debugFormToken'?    " . (in_array('debugFormToken', $methods) ? 'SIM (versao atualizada)' : 'NAO (versao ANTIGA em cache!)') . "\n\n";

echo "-- OPcache --\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "OPcache habilitado? " . (!empty($status['opcache_enabled']) ? 'sim' : 'nao') . "\n";
} else {
    echo "Funcao opcache_get_status nao existe (extensao pode estar desabilitada para uso via script)\n";
}
echo "ini opcache.enable: " . (ini_get('opcache.enable') ? 'on' : 'off') . "\n";
echo "ini opcache.validate_timestamps: " . (ini_get('opcache.validate_timestamps') === '' ? '(nao definido)' : (ini_get('opcache.validate_timestamps') ? 'on (bom, detecta mudancas)' : 'OFF -- provavel causa do problema!')) . "\n";
echo "ini opcache.revalidate_freq: " . ini_get('opcache.revalidate_freq') . "\n";
