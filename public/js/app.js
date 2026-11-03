/* ADVOGABT — interações da interface (sem dependências) */
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]');
  csrf = csrf ? csrf.getAttribute('content') : '';

  // Tema claro/escuro
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var root = document.documentElement;
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('advogabt-theme', next); } catch (e) {}
    });
  });

  // Menus suspensos
  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-dropdown-toggle]');
    document.querySelectorAll('.dropdown.open').forEach(function (d) {
      if (!toggle || d !== toggle.closest('.dropdown')) d.classList.remove('open');
    });
    if (toggle) {
      e.preventDefault();
      toggle.closest('.dropdown').classList.toggle('open');
    }
  });

  // Menu lateral no celular
  document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  });

  // Confirmação antes de excluir
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Alertas somem sozinhos
  document.querySelectorAll('.alert[data-autohide]').forEach(function (el) {
    setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 400); }, 4000);
  });

  // Filtros que enviam o formulário ao mudar
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  // Relatórios: período personalizado mostra as datas; os demais aplicam na hora
  document.querySelectorAll('[data-period-select]').forEach(function (sel) {
    var range = sel.form.querySelector('[data-custom-range]');
    sel.addEventListener('change', function () {
      if (sel.value === 'personalizado') { if (range) range.style.display = ''; return; }
      sel.form.submit();
    });
  });

  // Dia inteiro desativa horários
  var allDay = document.querySelector('[name="all_day"]');
  if (allDay) {
    var sync = function () {
      document.querySelectorAll('[data-time-field]').forEach(function (f) { f.disabled = allDay.checked; });
    };
    allDay.addEventListener('change', sync);
    sync();
  }

  // Arrastar e soltar (quadro de tarefas e funil do CRM)
  var dragged = null;
  document.querySelectorAll('[data-draggable]').forEach(function (card) {
    card.setAttribute('draggable', 'true');
    card.addEventListener('dragstart', function (e) {
      dragged = card;
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
    });
    card.addEventListener('dragend', function () { card.classList.remove('dragging'); dragged = null; });
  });

  document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
    var column = zone.closest('.column');
    zone.addEventListener('dragover', function (e) { e.preventDefault(); column.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function (e) { if (!zone.contains(e.relatedTarget)) column.classList.remove('drag-over'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      column.classList.remove('drag-over');
      if (!dragged) return;
      var url = dragged.getAttribute('data-move-url');
      var field = zone.getAttribute('data-field');
      var value = zone.getAttribute('data-dropzone');
      if (dragged.parentElement === zone) return;
      zone.prepend(dragged);
      var body = {}; body[field] = value;
      fetch(url, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body)
      }).then(function (r) {
        if (!r.ok) throw new Error('Falha ao mover');
        if (zone.hasAttribute('data-reload')) window.location.reload();
      }).catch(function () {
        alert('Não foi possível mover o item. A página será recarregada.');
        window.location.reload();
      });
    });
  });

  // Inserir variável no editor de modelos
  document.querySelectorAll('[data-insert-var]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var area = document.querySelector('textarea[name="content"]');
      if (!area) return;
      var text = '{{' + btn.getAttribute('data-insert-var') + '}}';
      var s = area.selectionStart, en = area.selectionEnd;
      area.value = area.value.slice(0, s) + text + area.value.slice(en);
      area.focus();
      area.selectionStart = area.selectionEnd = s + text.length;
    });
  });

  // Máscaras simples (CPF/CNPJ, telefone, CEP)
  function digits(v) { return v.replace(/\D/g, ''); }
  var masks = {
    doc: function (v) {
      v = digits(v).slice(0, 14);
      if (v.length <= 11) return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
      return v.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
    },
    phone: function (v) {
      v = digits(v).slice(0, 11);
      if (v.length > 10) return v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
      if (v.length > 6) return v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
      if (v.length > 2) return v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
      return v;
    },
    cep: function (v) { return digits(v).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'); }
  };
  document.querySelectorAll('[data-mask]').forEach(function (input) {
    var fn = masks[input.getAttribute('data-mask')];
    if (!fn) return;
    input.addEventListener('input', function () { input.value = fn(input.value); });
  });

  // Busca de endereço pelo CEP (ViaCEP)
  var cep = document.querySelector('[data-cep-lookup]');
  if (cep) {
    cep.addEventListener('blur', function () {
      var v = digits(cep.value);
      if (v.length !== 8) return;
      fetch('https://viacep.com.br/ws/' + v + '/json/').then(function (r) { return r.json(); }).then(function (d) {
        if (d.erro) return;
        var f = cep.form;
        if (f.address && !f.address.value) f.address.value = [d.logradouro, d.bairro].filter(Boolean).join(', ');
        if (f.city && !f.city.value) f.city.value = d.localidade || '';
        if (f.state && !f.state.value) f.state.value = d.uf || '';
      }).catch(function () {});
    });
  }

  // ---------- Assistente IA ----------
  var chatLog = document.querySelector('[data-chat-log]');
  function scrollChat() { if (chatLog) chatLog.scrollTop = chatLog.scrollHeight; }
  scrollChat();

  function escapeHtml(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

  // Atalhos preenchem a caixa de mensagem
  document.querySelectorAll('[data-prompt]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector('[data-chat-input]');
      if (!input) return;
      input.value = btn.getAttribute('data-prompt');
      input.focus();
      var pos = input.value.indexOf('(');
      if (pos >= 0) { input.selectionStart = pos; input.selectionEnd = input.value.indexOf(')', pos) + 1; }
    });
  });

  // Copiar resposta
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var raw = btn.closest('.msg-body').querySelector('[data-copy-raw]');
    var text = raw ? raw.value : '';
    var done = function () { var old = btn.innerHTML; btn.textContent = 'Copiado!'; setTimeout(function () { btn.innerHTML = old; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (err) {} ta.remove();
    }
  });

  // Envio sem recarregar a página (com recarga normal como alternativa)
  var chatForm = document.querySelector('[data-chat-form]');
  if (chatForm && chatLog) {
    var input = chatForm.querySelector('[data-chat-input]');
    var sendBtn = chatForm.querySelector('[data-chat-send]');
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); chatForm.requestSubmit ? chatForm.requestSubmit() : chatForm.submit(); }
    });
    chatForm.addEventListener('submit', function (e) {
      var text = input.value.trim();
      if (!text || !window.fetch) return;
      e.preventDefault();
      var welcome = chatLog.querySelector('[data-chat-welcome]');
      if (welcome) welcome.remove();
      var end = chatLog.querySelector('#fim');
      var userMsg = document.createElement('div');
      userMsg.className = 'msg msg-user';
      userMsg.innerHTML = '<div class="msg-bubble pre">' + escapeHtml(text) + '</div>';
      var thinking = document.createElement('div');
      thinking.className = 'msg msg-ai msg-thinking';
      thinking.innerHTML = '<div class="msg-avatar">✦</div><div class="msg-body"><div class="msg-bubble"><span class="dots"><span></span><span></span><span></span></span> Pensando...</div></div>';
      chatLog.insertBefore(userMsg, end);
      chatLog.insertBefore(thinking, end);
      scrollChat();
      var data = new FormData(chatForm);
      input.value = '';
      sendBtn.disabled = true;
      fetch(chatForm.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: data })
        .then(function (r) {
          if (r.status === 422) return r.json().then(function (j) { throw new Error((j && j.message) || 'Verifique a mensagem.'); });
          if (r.status === 429) throw new Error('Muitas mensagens em pouco tempo. Aguarde um minuto.');
          if (!r.ok) throw new Error('Erro ' + r.status + ' no servidor.');
          return r.json();
        })
        .then(function (j) {
          if (j.redirect) { window.location.href = j.redirect; return; }
          thinking.outerHTML = j.html;
          scrollChat();
        })
        .catch(function (err) {
          thinking.remove();
          input.value = text;
          alert(err.message || 'Não foi possível enviar a mensagem.');
        })
        .then(function () { sendBtn.disabled = false; });
    });
  }

  // Cadastro de provedor: ao trocar o tipo, ajusta modelos, URL e dicas
  var driverSel = document.querySelector('[data-ai-driver]');
  if (driverSel) {
    var f = driverSel.form;
    driverSel.addEventListener('change', function () {
      var o = driverSel.selectedOptions[0];
      var models = (o.getAttribute('data-models') || '').split(',').filter(Boolean);
      f.querySelector('[data-ai-models]').innerHTML = models.map(function (m) { return '<option value="' + escapeHtml(m) + '"></option>'; }).join('');
      var model = f.querySelector('[name="model"]');
      model.value = o.getAttribute('data-default-model') || '';
      var url = f.querySelector('[data-ai-base-url]');
      url.placeholder = o.getAttribute('data-base-url') || 'https://.../v1';
      var hint = o.getAttribute('data-hint');
      f.querySelector('[data-ai-hint]').innerHTML = hint ? '✦ <span>' + escapeHtml(hint) + '</span>' : '';
      var PR = { gratis: ['Grátis', 'green'], freemium: ['Grátis com limites', 'blue'], comercial: ['Comercial (pago)', 'orange'], depende: ['Grátis ou pago', ''] };
      var pr = PR[o.getAttribute('data-pricing')] || ['', ''];
      var badge = f.querySelector('[data-ai-pricing-badge]');
      if (badge) { badge.textContent = pr[0]; badge.className = 'badge ' + pr[1]; }
      var prNote = f.querySelector('[data-ai-pricing-note]');
      if (prNote) prNote.textContent = o.getAttribute('data-pricing-note') || '';
      var link = f.querySelector('[data-ai-keys-link]');
      var href = o.getAttribute('data-keys-url');
      if (href) { link.href = href; link.hidden = false; } else { link.hidden = true; }
      var name = f.querySelector('[name="name"]');
      if (!name.dataset.touched) name.value = o.textContent.replace(/\s*\(.*\)\s*$/, '').trim();
    });
    f.querySelector('[name="name"]').addEventListener('input', function () { this.dataset.touched = '1'; });

    // Carregar os modelos que a chave pode usar
    var loadBtn = f.querySelector('[data-ai-load-models]');
    var modelList = f.querySelector('[data-ai-model-list]');
    var modelStatus = f.querySelector('[data-ai-models-status]');
    if (loadBtn) {
      loadBtn.addEventListener('click', function () {
        var body = new FormData();
        body.append('driver', driverSel.value);
        body.append('base_url', f.querySelector('[name="base_url"]').value);
        body.append('api_key', f.querySelector('[name="api_key"]').value);
        if (loadBtn.getAttribute('data-provider-id')) body.append('provider_id', loadBtn.getAttribute('data-provider-id'));
        loadBtn.disabled = true;
        modelStatus.className = 'small muted';
        modelStatus.textContent = 'Consultando o provedor...';
        fetch(loadBtn.getAttribute('data-url'), { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            var models = (res.j && res.j.models) || [];
            modelStatus.className = 'small ' + (res.ok && models.length ? 'text-green' : 'text-red');
            modelStatus.textContent = (res.j && res.j.message) || 'Não foi possível carregar os modelos.';
            if (!res.ok || !models.length) { modelList.hidden = true; return; }
            var current = f.querySelector('[name="model"]').value;
            f.querySelector('[data-ai-models]').innerHTML = models.map(function (m) { return '<option value="' + escapeHtml(m) + '"></option>'; }).join('');
            modelList.innerHTML = '<option value="">Escolha um modelo...</option>' + models.map(function (m) {
              return '<option value="' + escapeHtml(m) + '"' + (m === current ? ' selected' : '') + '>' + escapeHtml(m) + '</option>';
            }).join('');
            modelList.hidden = false;
          })
          .catch(function () { modelStatus.className = 'small text-red'; modelStatus.textContent = 'Não foi possível carregar os modelos.'; })
          .then(function () { loadBtn.disabled = false; });
      });
      modelList.addEventListener('change', function () { if (modelList.value) f.querySelector('[name="model"]').value = modelList.value; });
    }
  }

  // Integrações: copiar token/segredo
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var src = btn.parentElement.querySelector('[data-copy-source]');
    if (!src) return;
    var text = src.textContent.trim();
    var done = function () { var old = btn.innerHTML; btn.textContent = 'Copiado!'; setTimeout(function () { btn.innerHTML = old; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var range = document.createRange(); range.selectNodeContents(src);
      var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
      try { document.execCommand('copy'); done(); } catch (err) {}
    }
  });

  // Integrações: "Todos os eventos" esconde a lista de eventos
  document.querySelectorAll('[data-all-events]').forEach(function (box) {
    var list = document.querySelector('[data-event-list]');
    box.addEventListener('change', function () { if (list) list.hidden = box.checked; });
  });

  // WhatsApp: rola até o fim, Enter envia e busca mensagens novas a cada 8s
  var wa = document.querySelector('[data-wa-messages]');
  if (wa) {
    wa.scrollTop = wa.scrollHeight;
    var waInput = document.querySelector('[data-wa-input]');
    if (waInput) {
      waInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (waInput.value.trim()) waInput.form.submit(); }
      });
    }
    setInterval(function () {
      if (document.hidden) return;
      fetch(wa.getAttribute('data-poll') + '?after=' + wa.getAttribute('data-last'), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j || !j.html) return;
          var nearBottom = wa.scrollHeight - wa.scrollTop - wa.clientHeight < 80;
          wa.insertAdjacentHTML('beforeend', j.html);
          wa.setAttribute('data-last', j.last_id);
          if (nearBottom) wa.scrollTop = wa.scrollHeight;
        })
        .catch(function () {});
    }, 8000);
  }

  // Arquivos na nuvem: carrega a pasta do processo/cliente depois da página
  document.querySelectorAll('[data-cloud-panel]').forEach(function (box) {
    fetch(box.getAttribute('data-cloud-panel'), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { box.innerHTML = j.html; })
      .catch(function (e) {
        box.innerHTML = '<div class="empty"><div>Não foi possível carregar os arquivos (' + e.message + '). Recarregue a página.</div></div>';
      });
  });
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-cloud-upload]');
    if (!form || e.defaultPrevented) return;
    var btn = form.querySelector('button');
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }
  });

  // Copiar o valor de um campo (ex.: URL de retorno)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy-input]');
    if (!btn) return;
    var input = btn.parentElement.querySelector('input');
    if (!input) return;
    input.select();
    try { navigator.clipboard.writeText(input.value); } catch (err) { document.execCommand('copy'); }
    var old = btn.innerHTML;
    btn.textContent = 'Copiado!';
    setTimeout(function () { btn.innerHTML = old; }, 1500);
  });

  // Arquivos na nuvem: mostra os campos da forma de conexão escolhida
  document.querySelectorAll('[data-auth-mode]').forEach(function (sel) {
    var apply = function () {
      document.querySelectorAll('[data-mode]').forEach(function (el) { el.hidden = el.getAttribute('data-mode') !== sel.value; });
    };
    sel.addEventListener('change', apply);
  });

  // Agenda e e-mail: preenche o servidor SMTP conforme a conta (Gmail, Outlook, outro).
  document.querySelectorAll('[data-mail-provider]').forEach(function (sel) {
    var form = sel.closest('form');
    sel.addEventListener('change', function () {
      var o = sel.options[sel.selectedIndex];
      form.querySelectorAll('[data-provider-help]').forEach(function (el) { el.hidden = el.getAttribute('data-provider-help') !== sel.value; });
      form.querySelector('[name="host"]').value = o.getAttribute('data-host') || '';
      form.querySelector('[name="port"]').value = o.getAttribute('data-port') || '587';
      form.querySelector('[name="encryption"]').value = o.getAttribute('data-encryption') || 'tls';
      if (sel.value === 'smtp') { var d = form.querySelector('details'); if (d) d.open = true; }
    });
  });
})();

// Assinatura digital: signatários, origem do documento e opções por plataforma.
(function () {
  var form = document.querySelector('[data-signature-form]');
  if (!form) return;
  var list = form.querySelector('[data-signer-list]');
  var tpl = form.querySelector('[data-signer-template]');
  var masks = function (root) {
    root.querySelectorAll('[data-mask="phone"]').forEach(function (i) {
      i.addEventListener('input', function () {
        var d = i.value.replace(/\D/g, '').slice(0, 11);
        i.value = d.length > 10 ? d.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3') : d.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
      });
    });
  };
  form.querySelector('[data-signer-add]').addEventListener('click', function () {
    var i = parseInt(list.getAttribute('data-next') || '0', 10);
    list.setAttribute('data-next', i + 1);
    var wrap = document.createElement('div');
    wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, i);
    var row = wrap.firstElementChild;
    list.appendChild(row);
    masks(row);
    var first = row.querySelector('input');
    if (first) first.focus();
  });
  list.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-signer-remove]');
    if (btn) btn.closest('[data-signer-row]').remove();
  });

  var syncSource = function () {
    var checked = form.querySelector('[data-source-toggle]:checked');
    var v = checked ? checked.value : 'texto';
    form.querySelectorAll('[data-source]').forEach(function (el) {
      var on = el.getAttribute('data-source') === v;
      el.hidden = !on;
      el.querySelectorAll('textarea, input').forEach(function (f) { f.disabled = !on; });
    });
  };
  var syncProvider = function () {
    var checked = form.querySelector('[data-provider-choice]:checked');
    var v = checked ? checked.value : '';
    form.querySelectorAll('[data-provider-only]').forEach(function (el) {
      el.hidden = el.getAttribute('data-provider-only').split(' ').indexOf(v) === -1;
    });
    var label = form.querySelector('[data-submit-label]');
    if (label) label.textContent = v === 'manual' ? 'Gerar PDF para assinar' : 'Enviar para assinatura';
  };
  form.querySelectorAll('[data-source-toggle]').forEach(function (r) { r.addEventListener('change', syncSource); });
  form.querySelectorAll('[data-provider-choice]').forEach(function (r) { r.addEventListener('change', syncProvider); });
  syncSource();
  syncProvider();
})();

// Assinatura digital (configuração): carrega os cofres da D4Sign.
(function () {
  var btn = document.querySelector('[data-load-safes]');
  if (!btn) return;
  var form = btn.closest('form');
  var csrf = document.querySelector('meta[name="csrf-token"]');
  btn.addEventListener('click', function () {
    var out = form.querySelector('[data-safes-status]');
    var sel = form.querySelector('[name="safe_uuid"]');
    var body = new FormData();
    ['environment', 'api_token', 'crypt_key'].forEach(function (n) {
      var f = form.querySelector('[name="' + n + '"]');
      if (f && f.value) body.append(n, f.value);
    });
    btn.disabled = true;
    out.textContent = 'Carregando...';
    fetch(btn.getAttribute('data-load-safes'), { method: 'POST', body: body, headers: { 'X-CSRF-TOKEN': csrf ? csrf.content : '', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Falha ao carregar.'); return j; }); })
      .then(function (j) {
        var current = sel.value;
        sel.innerHTML = '<option value="">— Escolha o cofre —</option>';
        j.safes.forEach(function (s) {
          var o = document.createElement('option');
          o.value = s.uuid; o.textContent = s.name; o.selected = s.uuid === current;
          sel.appendChild(o);
        });
        out.textContent = j.safes.length ? j.safes.length + ' cofre(s) encontrado(s). Escolha um e salve.' : 'A conta não tem cofres. Crie um no painel da D4Sign.';
      })
      .catch(function (e) { out.textContent = e.message; })
      .then(function () { btn.disabled = false; });
  });
  var sel = form.querySelector('[name="safe_uuid"]');
  var name = form.querySelector('[name="safe_name"]');
  if (sel && name) sel.addEventListener('change', function () { name.value = sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : ''; });
})();

// Cobrança: mostra só as formas de pagamento que a plataforma escolhida oferece
(function () {
  var provider = document.querySelector('[data-billing-provider]');
  var method = document.querySelector('[data-billing-method]');
  if (!provider || !method) return;
  var sync = function () {
    var opt = provider.selectedOptions[0];
    var allowed = (opt ? opt.getAttribute('data-methods') : '').split(',');
    Array.prototype.forEach.call(method.options, function (o) { o.hidden = o.disabled = allowed.indexOf(o.value) === -1; });
    if (method.selectedOptions[0] && method.selectedOptions[0].disabled) method.value = allowed[0];
  };
  provider.addEventListener('change', sync);
  sync();
})();
