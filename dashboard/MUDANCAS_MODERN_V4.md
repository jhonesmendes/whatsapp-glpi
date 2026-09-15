# Modern Ops V4 — Hardening de segurança

Esta versão foi gerada em cima da V3 que já havia rodado no GLPI 10.0.25.
O objetivo foi manter o visual e o comportamento, mas reduzir superfície de ataque.

## Principais mudanças

- Adicionado token CSRF nas configurações do dashboard.
- `settings.php` agora bloqueia POST sem token válido.
- `modern.php`, `drilldown.php`, `ajax/metrics.php` e `settings.php` validam login e direito de leitura de chamados.
- Validação reforçada para filtro por entidade (`ent`).
- Validação reforçada para filtro por grupo (`grp`) com checagem da entidade do grupo.
- Substituído o uso direto de `addslashes()` por escape centralizado via `$DB->escape()` quando disponível.
- Criado `scope.inc.php` para substituir os includes legados de métricas no dashboard moderno.
- O dashboard moderno não depende mais de `metrics.inc.php`, `metrics_ent.inc.php` e `metrics_grp.inc.php` para montar o escopo.
- Includes legados receberam bloqueio contra acesso direto por URL.
- Layout legado `index_controlfrog_legacy.php` agora redireciona para `modern.php`.
- Cache continua em `GLPI_TMP_DIR`, com criação automática de `.htaccess` e `index.html` na pasta de cache.
- Escrita de cache com `LOCK_EX`.
- Limpeza automática de arquivos de cache antigos.
- Headers de segurança adicionados:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `Referrer-Policy: same-origin`
  - `Permissions-Policy`
  - `Content-Security-Policy` compatível com a página atual
- Drill-down limitado a 150 registros por consulta.
- Fallback visual de ícones no CSS, evitando depender de arquivos de fonte no dashboard moderno.

## Arquivos principais alterados

- `inc/metrics.class.php`
- `front/metrics/modern.php`
- `front/ajax/metrics.php`
- `front/metrics/drilldown.php`
- `front/metrics/settings.php`
- `front/metrics/index.php`
- `front/metrics/index_controlfrog_legacy.php`
- `front/metrics/scope.inc.php`
- `front/metrics/metrics.inc.php`
- `front/metrics/metrics_ent.inc.php`
- `front/metrics/metrics_grp.inc.php`
- `front/css/dashboard-glass.css`

## Validação feita

Foi executado `php -l` nos arquivos alterados da V4, sem erros de sintaxe.

Observação: o pacote original possui bibliotecas antigas de terceiros que podem não passar no `php -l` global em versões modernas do PHP, mas isso já existia antes da V4 e não foi causado por esta alteração.

## Recomendação de implantação

1. Fazer backup da pasta atual `plugins/dashboard`.
2. Substituir pela pasta desta V4.
3. Limpar cache do navegador.
4. Acessar o dashboard pelo menu do plugin.
5. Testar:
   - abertura normal do painel;
   - atualização automática;
   - salvar configurações;
   - drill-down dos KPIs;
   - filtro por entidade/grupo, se usado.

## Nota honesta

Esta versão melhora bastante a segurança para intranet/VPN.
Ainda assim, não recomendo expor este dashboard diretamente na internet sem uma camada externa de proteção, como VPN, reverse proxy autenticado, TLS bem configurado e restrição por IP.
