# Dashboard Modern Ops v4.1 - Correção de gráficos

Correção aplicada sobre a V4 Hardening.

## Problema corrigido

Após a V4, os cards **Fluxo de Criação**, **Abertos vs Solucionados** e **Backlog por Idade** podiam ficar em branco.

A causa mais provável era conflito entre o CSP de segurança da V4 e a biblioteca Chart.js antiga usada pelo plugin original. Essa versão antiga usa avaliação dinâmica de JavaScript em alguns pontos, prática bloqueada por políticas CSP mais rígidas.

## Solução

- Removida a dependência do Chart.js antigo nesses três cards.
- Os gráficos agora são renderizados em SVG puro pelo `front/js/dashboard-glass.js`.
- Mantido o CSP mais rígido da V4, sem liberar `unsafe-eval`.
- Adicionado cache-busting em CSS/JS com `?v=4.1`.
- Mantidos os mesmos dados, filtros, permissões, cache e hardening da V4.

## Arquivos alterados

- `front/metrics/modern.php`
- `front/js/dashboard-glass.js`
- `front/css/dashboard-glass.css`

## Aplicação

Aplicar por cima da pasta atual:

```text
plugins/dashboard/
```

Depois reiniciar o Apache e executar Ctrl+F5 no navegador ou abrir em janela anônima.
