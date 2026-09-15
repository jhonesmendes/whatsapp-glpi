# Mudanças aplicadas — Dashboard GLPI 10 Modern Glass

Esta versão repagina o dashboard de métricas do plugin `dashboard` para ficar muito mais próximo da proposta visual do DashGLPI do Diogo Berlanda, mas sem portar a arquitetura GLPI 11.

## O que mudou de verdade nesta versão

- `front/metrics/index.php` agora redireciona para `front/metrics/modern.php` preservando filtros `ent` e `grp`.
- O layout antigo foi preservado como `front/metrics/index_controlfrog_legacy.php`.
- `front/metrics/modern.php` foi refeito como página standalone, com:
  - sidebar lateral estilo GLPI Pro;
  - menu por seções: Visão Geral, Monitor SLA, Ranking Técnicos e Chamados Ativos;
  - glassmorphism real com blur, transparência, sombras, gradientes e grid de fundo;
  - modo claro/escuro com `localStorage`;
  - modo TV com fullscreen e rotação automática entre seções;
  - relógio em tempo real;
  - cards KPI no padrão do DashGLPI;
  - gráficos usando o Chart.js local do plugin, compatível com a versão antiga incluída no projeto;
  - Top Categorias em barras horizontais animadas para ficar mais próximo da referência;
  - tabela de atividade recente;
  - ranking de técnicos baseado em chamados solucionados/fechados;
  - grid de chamados ativos para visualização em TV.

## Compatibilidade

- Mantido foco em GLPI 10.0.x / 10.0.25.
- Não foram usadas chamadas específicas do GLPI 11.
- A camada de dados continua usando as variáveis e filtros dos arquivos originais:
  - `metrics.inc.php`
  - `metrics_ent.inc.php`
  - `metrics_grp.inc.php`

## Observação importante

O plugin DashGLPI original usa uma arquitetura própria com `front/dashboard.php`, `ajax/dashboard.php`, `inc/dashboard.class.php` e assets em `public/`. Esta adaptação não copia essa arquitetura, porque isso aumentaria o risco de quebra no GLPI 10.0.25. A proposta aqui foi trazer a "cara" visual e comportamental dele para dentro do seu plugin atual.

## Como acessar

Acesse normalmente pelo menu do plugin. O link antigo para `metrics/index.php` agora abrirá o novo layout.

Acesso direto:

```text
/plugins/dashboard/front/metrics/modern.php
```

Layout antigo:

```text
/plugins/dashboard/front/metrics/index_controlfrog_legacy.php
```

---

## Atualização v3 - Modern Ops

Aplicadas melhorias de arquitetura, atualização automática, configurações, cache, drill-down e métricas operacionais. Ver `MUDANCAS_MODERN_V3.md`.
