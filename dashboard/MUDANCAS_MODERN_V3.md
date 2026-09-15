# Dashboard Moderno GLPI 10 - Modern Ops v3

Esta versão evolui a v2 que já rodou no GLPI 10.0.25, mantendo compatibilidade com a base antiga do plugin Dashboard e evitando dependências da arquitetura GLPI 11 do DashGLPI.

## Principais mudanças

- `modern.php` foi reorganizado para usar uma camada de métricas em `inc/metrics.class.php`.
- CSS separado em `front/css/dashboard-glass.css`.
- JavaScript separado em `front/js/dashboard-glass.js`.
- Atualização automática via AJAX em `front/ajax/metrics.php`.
- Cache interno em `GLPI_TMP_DIR/dashboard_modern_cache` para reduzir carga no banco.
- Tela de configurações por usuário dentro do próprio dashboard.
- Drill-down nos KPIs em `front/metrics/drilldown.php`.
- KPIs novos: SLA vencido, vencendo em breve, MTTA, MTTR, backlog por idade e reabertos estimados.
- Perfil de exibição: TV da TI, Gestor, Técnico e Diretoria.
- Botão de atualização manual e modo TV com rotação configurável.
- Links diretos dos chamados para `front/ticket.form.php?id=...` do GLPI.

## Arquivos adicionados/alterados

- `front/metrics/modern.php`
- `front/metrics/drilldown.php`
- `front/metrics/settings.php`
- `front/ajax/metrics.php`
- `front/css/dashboard-glass.css`
- `front/js/dashboard-glass.js`
- `inc/metrics.class.php`

## Observações importantes

- O indicador **Reabertos** é uma estimativa segura: chamados ativos que já tiveram `solvedate`. Para medir reabertura perfeita, seria necessário adaptar a leitura do histórico do GLPI conforme os registros reais do ambiente.
- O indicador **MTTA** usa `takeintoaccount_delay_stat` quando o campo existe no GLPI.
- O indicador **MTTR** usa `solve_delay_stat` quando disponível; caso contrário, calcula pela diferença entre abertura e solução.
- O monitor de SLA usa `time_to_resolve`; se seus chamados não tiverem esse campo preenchido, os cards de SLA podem aparecer zerados.

## Como testar

1. Faça backup da pasta atual `plugins/dashboard`.
2. Substitua pela pasta deste pacote.
3. Limpe cache do navegador.
4. Acesse o menu do plugin Dashboard.
5. Abra `Configurações` no menu lateral e ajuste atualização, perfil, limite de chamados e modo TV.
