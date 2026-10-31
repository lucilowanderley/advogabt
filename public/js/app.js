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
})();
