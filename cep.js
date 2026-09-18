/**
 * cep.js — busca ViaCEP + autocomplete de cidades (IBGE)
 *
 * Ativa automaticamente em qualquer campo com [data-cep].
 * Mapeia campos pelo atributo data-* no input de CEP:
 *   data-cep-logradouro  → seletor do campo logradouro/endereço
 *   data-cep-bairro      → seletor do campo bairro
 *   data-cep-cidade      → seletor do campo cidade
 *   data-cep-uf          → seletor do campo UF/estado
 *
 * Autocomplete de cidades: ativa em qualquer [data-cidade-uf]
 *   O valor do atributo é o seletor do campo UF que filtra as cidades.
 */
(function () {
  'use strict';

  // ── Utilitários ───────────────────────────────────────────────────────────

  function cepLimpo(v) {
    return (v || '').replace(/\D/g, '');
  }

  function setValor(seletor, valor, contexto) {
    if (!seletor) return;
    var el = (contexto || document).querySelector(seletor);
    if (!el) return;
    el.value = valor;
    el.dispatchEvent(new Event('input',  { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function mostrarSpinner(input, ativo) {
    var existing = input.parentElement.querySelector('.cep-spinner');
    if (ativo) {
      if (existing) return;
      var s = document.createElement('span');
      s.className = 'cep-spinner position-absolute end-0 top-50 translate-middle-y me-2';
      s.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary" role="status"></span>';
      s.style.pointerEvents = 'none';
      var wrap = input.parentElement;
      if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
      wrap.appendChild(s);
    } else {
      if (existing) existing.remove();
    }
  }

  function mostrarFeedback(input, tipo, msg) {
    var prev = input.parentElement.querySelector('.cep-feedback');
    if (prev) prev.remove();
    if (!tipo) return;
    var d = document.createElement('div');
    d.className = 'cep-feedback form-text ' + (tipo === 'erro' ? 'text-danger' : 'text-success');
    d.textContent = msg;
    input.parentElement.appendChild(d);
    if (tipo === 'ok') setTimeout(function () { d.remove(); }, 3000);
  }

  // ── Fetch com timeout compatível (sem AbortSignal.timeout) ───────────────

  function fetchComTimeout(url, ms) {
    return new Promise(function (resolve, reject) {
      var controller = new AbortController();
      var timer = setTimeout(function () {
        controller.abort();
        reject(new DOMException('timeout', 'TimeoutError'));
      }, ms);

      fetch(url, { signal: controller.signal })
        .then(function (r) { clearTimeout(timer); resolve(r); })
        .catch(function (e) { clearTimeout(timer); reject(e); });
    });
  }

  // ── Busca CEP — tenta direto na ViaCEP, cai no proxy PHP se falhar ───────

  function preencherCampos(input, data) {
    var ctx = input.closest('form') || document;
    setValor(input.dataset.cepLogradouro, data.logradouro || '', ctx);
    setValor(input.dataset.cepBairro,     data.bairro     || '', ctx);
    setValor(input.dataset.cepCidade,     data.localidade || '', ctx);
    setValor(input.dataset.cepUf,         data.uf         || '', ctx);

    if (input.dataset.cepCidade && data.uf) {
      var cidadeEl = ctx.querySelector(input.dataset.cepCidade);
      if (cidadeEl && cidadeEl.dataset.cidadeUf) {
        carregarCidades(cidadeEl, data.uf, data.localidade);
      }
    }
  }

  function buscarCep(input) {
    var cep = cepLimpo(input.value);
    if (cep.length !== 8) return;

    mostrarSpinner(input, true);
    mostrarFeedback(input, null);

    var urlDireto = 'https://viacep.com.br/ws/' + cep + '/json/';
    var urlProxy  = 'cep_proxy.php?cep=' + cep;

    function tentarBusca() {
      // Tenta proxy PHP primeiro (server-side, sem restrição de CSP/CORS).
      // Se falhar, tenta ViaCEP direto como fallback.
      return fetchComTimeout(urlProxy, 8000)
        .then(function (r) {
          if (!r.ok) throw new Error('proxy HTTP ' + r.status);
          return r.json();
        })
        .catch(function () {
          return fetchComTimeout(urlDireto, 6000)
            .then(function (r) {
              if (!r.ok) throw new Error('HTTP ' + r.status);
              return r.json();
            });
        });
    }

    tentarBusca()
      .then(function (data) {
        if (data.erro) {
          mostrarFeedback(input, 'erro', 'CEP não encontrado.');
        } else {
          preencherCampos(input, data);
          mostrarFeedback(input, 'ok', 'Endereço preenchido automaticamente.');
        }
      })
      .catch(function () {
        mostrarFeedback(input, 'erro', 'Não foi possível consultar o CEP. Preencha manualmente.');
      })
      .then(function () {
        mostrarSpinner(input, false);
      });
  }

  // ── Autocomplete de cidades (IBGE) ────────────────────────────────────────

  var _cidadeCache = {};

  function carregarCidades(input, uf, valorAtual) {
    var key = uf || 'br';
    if (_cidadeCache[key]) {
      _aplicarDatalist(input, _cidadeCache[key], valorAtual);
      return;
    }

    var url = uf
      ? 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome'
      : 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios?orderBy=nome';

    fetchComTimeout(url, 8000)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var nomes = data.map(function (m) { return m.nome; });
        _cidadeCache[key] = nomes;
        _aplicarDatalist(input, nomes, valorAtual);
      })
      .catch(function () { /* silencioso: usuário digita manualmente */ });
  }

  function _aplicarDatalist(input, cidades, valorAtual) {
    var listId = input.getAttribute('list');
    var dl = listId ? document.getElementById(listId) : null;
    if (!dl) {
      dl = document.createElement('datalist');
      dl.id = 'cep-dl-' + Math.random().toString(36).slice(2);
      document.body.appendChild(dl);
      input.setAttribute('list', dl.id);
    }
    dl.innerHTML = cidades.map(function (c) {
      return '<option value="' + c.replace(/"/g, '&quot;') + '">';
    }).join('');
    if (valorAtual !== undefined) input.value = valorAtual;
  }

  // ── Inicialização ─────────────────────────────────────────────────────────

  function inicializar() {
    // Campos de CEP
    document.querySelectorAll('[data-cep]').forEach(function (input) {
      // Máscara 00000-000
      input.addEventListener('input', function () {
        var v = cepLimpo(this.value);
        if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5, 8);
        this.value = v;
      });

      input.addEventListener('blur', function () {
        if (cepLimpo(this.value).length === 8) buscarCep(this);
      });

      // Enter busca sem submeter
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (cepLimpo(this.value).length === 8) buscarCep(this);
        }
      });
    });

    // Campos de cidade com autocomplete manual
    document.querySelectorAll('[data-cidade-uf]').forEach(function (input) {
      var carregado = false;
      input.addEventListener('focus', function () {
        if (carregado) return;
        carregado = true;
        var ufSel = this.dataset.cidadeUf;
        var uf = '';
        if (ufSel) {
          var ufEl = (this.closest('form') || document).querySelector(ufSel);
          if (ufEl) uf = ufEl.value.trim().toUpperCase();
        }
        carregarCidades(this, uf, this.value);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inicializar);
  } else {
    inicializar();
  }

})();
