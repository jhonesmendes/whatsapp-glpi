/*
 * Carimbo de Nome - WhatsApp Web (v1.1)
 * Intercepta o envio (Enter ou botão Enviar), insere "*Nome*" na primeira
 * linha da mensagem e só então deixa o WhatsApp enviar.
 */
(() => {
  'use strict';

  const LOG = (...a) => console.log('%c[Carimbo]', 'color:#00a884;font-weight:bold', ...a);

  const estado = { nome: '', ativo: true, liberado: false, ocupado: false };

  // ---------- Configuração ----------
  chrome.storage.sync.get({ nome: '', ativo: true }, (cfg) => {
    estado.nome = (cfg.nome || '').trim();
    estado.ativo = cfg.ativo !== false;
    LOG('carregado. Nome:', estado.nome || '(não cadastrado)', '| ativo:', estado.ativo);
    atualizarSelo();
  });

  chrome.storage.onChanged.addListener((mud) => {
    if (mud.nome) estado.nome = (mud.nome.newValue || '').trim();
    if (mud.ativo) estado.ativo = mud.ativo.newValue !== false;
    atualizarSelo();
  });

  const carimbo = () => `*${estado.nome}*`;
  const espera = (ms) => new Promise((r) => setTimeout(r, ms));

  // ---------- Identificação da caixa de mensagem ----------
  const RE_ENVIAR = /^(enviar|send)$/i;
  const RE_LEGENDA = /legenda|caption|mensagem|message/i;

  function ehBotaoEnviar(el) {
    if (!el) return false;
    const label = (el.getAttribute('aria-label') || el.getAttribute('title') || '').trim();
    if (RE_ENVIAR.test(label)) return true;
    return !!el.querySelector('[data-icon="send"], [data-icon*="send"]');
  }

  // Editor de mensagem: caixa do rodapé da conversa ou legenda de mídia.
  function ehComposer(editor) {
    if (!editor || editor.closest('#side')) return false;
    if (editor.closest('#main footer')) return true;
    if (editor.getAttribute('data-tab') === '10') return true;
    const label = editor.getAttribute('aria-label') || editor.getAttribute('aria-placeholder') ||
      editor.getAttribute('title') || '';
    if (RE_LEGENDA.test(label)) return true;
    // legenda de foto/arquivo: há um botão Enviar no mesmo bloco próximo
    let no = editor.parentElement;
    for (let i = 0; i < 10 && no && no !== document.body; i++, no = no.parentElement) {
      if (no.querySelector('#main, #side')) return false;
      const btns = no.querySelectorAll('button, [role="button"]');
      for (const b of btns) if (ehBotaoEnviar(b)) return true;
    }
    return false;
  }

  function editorDe(alvo) {
    if (!(alvo instanceof Element)) return null;
    const editor = alvo.closest('[contenteditable="true"]');
    return ehComposer(editor) ? editor : null;
  }

  function botaoEnviarPerto(editor) {
    let no = editor.parentElement;
    while (no && no !== document.body) {
      const btns = no.querySelectorAll('button, [role="button"]');
      for (const b of btns) if (ehBotaoEnviar(b)) return b;
      if (no.id === 'main') break;
      no = no.parentElement;
    }
    return null;
  }

  function editorDoBotao(botao) {
    let no = botao.parentElement;
    while (no && no !== document.body) {
      const eds = no.querySelectorAll('[contenteditable="true"]');
      for (const ed of eds) if (ehComposer(ed)) return ed;
      no = no.parentElement;
    }
    return null;
  }

  const textoDe = (editor) => (editor.innerText || '').replace(/[​‎‏]/g, '').trim();
  const jaCarimbado = (editor) => textoDe(editor).split('\n')[0].trim() === carimbo();

  // ---------- Inserção do carimbo ----------
  function cursorNoInicio(editor) {
    editor.focus();
    const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
    const primeiro = walker.nextNode();
    const range = document.createRange();
    if (primeiro) range.setStart(primeiro, 0);
    else range.setStart(editor, 0);
    range.collapse(true);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    document.dispatchEvent(new Event('selectionchange'));
  }

  function colar(editor, texto) {
    const dt = new DataTransfer();
    dt.setData('text/plain', texto);
    editor.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  }

  async function carimbar(editor) {
    const tentativas = [
      () => colar(editor, carimbo() + '\n'),
      () => document.execCommand('insertText', false, carimbo() + '\n'),
      () => { // texto + quebra de linha via Shift+Enter
        document.execCommand('insertText', false, carimbo());
        editor.dispatchEvent(new KeyboardEvent('keydown', {
          key: 'Enter', code: 'Enter', keyCode: 13, which: 13, shiftKey: true, bubbles: true, cancelable: true
        }));
      }
    ];
    for (let i = 0; i < tentativas.length; i++) {
      cursorNoInicio(editor);
      await espera(50);
      estado.liberado = true; // não interceptar o Shift+Enter sintético
      tentativas[i]();
      estado.liberado = false;
      await espera(120);
      if (jaCarimbado(editor)) { LOG('carimbo inserido (método', i + 1 + ')'); return true; }
      LOG('método', i + 1, 'não funcionou. Texto atual:', JSON.stringify(textoDe(editor).slice(0, 60)));
    }
    return false;
  }

  // ---------- Avisos e selo ----------
  function aviso(msg) {
    let caixa = document.getElementById('carimbo-nome-aviso');
    if (!caixa) {
      caixa = document.createElement('div');
      caixa.id = 'carimbo-nome-aviso';
      Object.assign(caixa.style, {
        position: 'fixed', bottom: '90px', left: '50%', transform: 'translateX(-50%)',
        background: '#d93025', color: '#fff', padding: '10px 16px', borderRadius: '8px',
        fontSize: '14px', fontFamily: 'Segoe UI, Helvetica, Arial, sans-serif',
        zIndex: 99999, boxShadow: '0 2px 8px rgba(0,0,0,.25)', maxWidth: '80vw'
      });
      document.body.appendChild(caixa);
    }
    caixa.textContent = msg;
    caixa.style.display = 'block';
    clearTimeout(caixa._t);
    caixa._t = setTimeout(() => { caixa.style.display = 'none'; }, 5000);
  }

  // Selo discreto no canto: confirma que a extensão está rodando nesta aba
  function atualizarSelo() {
    if (!document.body) return;
    let selo = document.getElementById('carimbo-nome-selo');
    if (!selo) {
      selo = document.createElement('div');
      selo.id = 'carimbo-nome-selo';
      Object.assign(selo.style, {
        position: 'fixed', top: '6px', left: '50%', transform: 'translateX(-50%)',
        padding: '2px 10px', borderRadius: '10px', fontSize: '11px',
        fontFamily: 'Segoe UI, Helvetica, Arial, sans-serif', color: '#fff',
        zIndex: 99999, pointerEvents: 'none', opacity: '0.85'
      });
      document.body.appendChild(selo);
    }
    if (!estado.ativo) { selo.textContent = 'Carimbo desativado'; selo.style.background = '#667781'; }
    else if (!estado.nome) { selo.textContent = 'Carimbo: cadastre seu nome'; selo.style.background = '#d93025'; }
    else { selo.textContent = 'Fibra Forte: ' + estado.nome; selo.style.background = '#00a884'; }
  }

  // ---------- Fluxo de envio ----------
  function precisaIntervir(editor) {
    if (!estado.ativo || estado.liberado) return false;
    if (!textoDe(editor)) return false;
    if (jaCarimbado(editor)) return false;
    return true;
  }

  async function processar(editor, via) {
    if (estado.ocupado) return;
    estado.ocupado = true;
    try {
      if (!estado.nome) {
        aviso('Cadastre seu nome na extensão "Carimbo de Nome" antes de enviar mensagens.');
        return;
      }
      const ok = await carimbar(editor);
      if (!ok) {
        aviso('Não foi possível inserir seu nome. A mensagem NÃO foi enviada.');
        return;
      }
      estado.liberado = true;
      await espera(50);
      const botao = botaoEnviarPerto(editor);
      if (botao) {
        LOG('enviando pelo botão');
        botao.click();
      } else {
        LOG('botão não encontrado, enviando com Enter');
        editor.focus();
        editor.dispatchEvent(new KeyboardEvent('keydown', {
          key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true
        }));
      }
      await espera(150);
    } finally {
      estado.liberado = false;
      estado.ocupado = false;
    }
  }

  function bloquear(e) {
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
  }

  // Enter na caixa de mensagem (window + captura = roda antes do WhatsApp)
  window.addEventListener('keydown', (e) => {
    if (estado.liberado) return;
    if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey || e.isComposing) return;
    const editor = editorDe(e.target);
    if (!editor) {
      const ce = e.target instanceof Element && e.target.closest('[contenteditable="true"]');
      if (ce) LOG('Enter em editor não reconhecido:', ce.getAttribute('aria-label'), ce);
      return;
    }
    if (estado.ocupado) { bloquear(e); return; }
    if (!precisaIntervir(editor)) return;
    bloquear(e);
    LOG('Enter interceptado');
    processar(editor, 'enter');
  }, true);

  // Clique no botão Enviar (mensagem normal ou legenda de foto/arquivo)
  const aoClicar = (e) => {
    if (estado.liberado || !(e.target instanceof Element)) return;
    const botao = e.target.closest('button, [role="button"]');
    if (!botao || !ehBotaoEnviar(botao)) return;
    const editor = editorDoBotao(botao);
    if (!editor) { LOG('botão Enviar sem editor reconhecido'); return; }
    if (estado.ocupado) { bloquear(e); return; }
    if (!precisaIntervir(editor)) return;
    bloquear(e);
    if (e.type === 'click') { LOG('clique em Enviar interceptado'); processar(editor, 'clique'); }
  };
  ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'].forEach((t) =>
    window.addEventListener(t, aoClicar, true));

  // Garante o selo mesmo se o body ainda não existia
  const obs = new MutationObserver(() => {
    if (!document.getElementById('carimbo-nome-selo')) atualizarSelo();
  });
  if (document.body) obs.observe(document.body, { childList: true });
  atualizarSelo();
})();
