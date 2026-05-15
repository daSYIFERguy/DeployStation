(function (global) {
  'use strict';

  function apiUrl(path) {
    var base = (global.STATION_WEB_BASE || '').replace(/\/$/, '');
    var rel = String(path || '').replace(/^\//, '');
    return base ? base + '/' + rel : rel;
  }

  function parseJsonResponse(response) {
    return response.text().then(function (text) {
      var trimmed = (text || '').trim();
      if (!trimmed) {
        throw new Error('Empty response from server (HTTP ' + response.status + ').');
      }
      try {
        return JSON.parse(trimmed);
      } catch (err) {
        if (response.status === 401) {
          throw new Error('Session expired — sign in again.');
        }
        var preview = trimmed.replace(/\s+/g, ' ').slice(0, 120);
        throw new Error('Invalid server response (HTTP ' + response.status + '): ' + preview);
      }
    });
  }

  function detectProjectFromUrl(url) {
    try {
      var u = new URL(url, window.location.origin);
      var q = u.searchParams.get('project');
      if (q) { return q; }
      var m = u.pathname.match(/\/p\/([a-z0-9_-]+)/i);
      if (m) { return m[1]; }
    } catch (_) {}
    return '';
  }

  global.stationFetchJson = function (url, options) {
    options = options || {};
    options.credentials = options.credentials || 'same-origin';
    if (!options.headers) { options.headers = {}; }
    if (!options.headers.Accept) { options.headers.Accept = 'application/json'; }
    return fetch(url, options).then(function (r) {
      return parseJsonResponse(r).then(function (data) {
        if (data && data.redirectUrl && !data.ok) {
          throw new Error(data.message || 'Please sign in again.');
        }
        return data;
      });
    });
  };

  global.stationApiUrl = apiUrl;

  function initAssist() {
    if (window.__stationAssistInit) { return; }
    window.__stationAssistInit = true;

    var dock = document.getElementById('stationPageToolsDock');
    var toggle = document.getElementById('stationAssistFabToggle');
    var panel = document.getElementById('stationAssistFabPanel');
    var closeBtn = document.getElementById('stationAssistFabClose');
    var log = document.getElementById('stationAssistChatLog');
    var input = document.getElementById('stationAssistInput');
    var extra = document.getElementById('stationAssistExtra');
    var sendBtn = document.getElementById('stationAssistSend');
    var statusEl = document.getElementById('stationAssistStatus');
    var includePage = document.getElementById('stationAssistIncludePage');
    var includeProject = document.getElementById('stationAssistIncludeProject');
    var ctxLabel = document.getElementById('stationAssistContextLabel');

    if (!dock || !toggle || !panel || !log || !input || !sendBtn) { return; }

    var clipFab = document.getElementById('stationClipboardFab');
    var clipPanel = document.getElementById('stationClipboardFabPanel');
    var clipToggle = document.getElementById('stationClipboardFabToggle');

    function setOpen(open) {
      var o = !!open;
      if (!o && panel.contains(document.activeElement)) {
        try { document.activeElement.blur(); } catch (_) {}
        try { toggle.focus({ preventScroll: true }); } catch (_) { try { toggle.focus(); } catch (__) {} }
      }
      dock.classList.toggle('assist-open', o);
      panel.classList.toggle('assist-fab-panel--open', o);
      panel.setAttribute('aria-hidden', o ? 'false' : 'true');
      if (typeof panel.toggleAttribute === 'function') {
        panel.toggleAttribute('inert', !o);
      }
      toggle.setAttribute('aria-expanded', o ? 'true' : 'false');
      panel.style.display = o ? 'flex' : 'none';
      if (o) {
        if (clipFab && clipPanel) {
          clipFab.classList.remove('is-open');
          clipPanel.classList.remove('clip-fab-panel--open');
          clipPanel.style.display = 'none';
          if (clipToggle) { clipToggle.setAttribute('aria-expanded', 'false'); }
        }
        refreshContext();
        window.setTimeout(function () { input.focus(); }, 60);
      }
    }

    panel.style.display = 'none';
    if (typeof panel.toggleAttribute === 'function') {
      panel.toggleAttribute('inert', true);
    }

    function updateContextLabel() {
      if (!ctxLabel) { return; }
      var slug = detectProjectFromUrl(window.location.href);
      var parts = [document.title || 'This page'];
      if (slug) { parts.push('project ' + slug); }
      ctxLabel.textContent = parts.join(' · ');
    }

    function refreshContext() {
      updateContextLabel();
      var qs = 'pageUrl=' + encodeURIComponent(window.location.href) +
        '&pageTitle=' + encodeURIComponent(document.title || '');
      var slug = detectProjectFromUrl(window.location.href);
      if (slug) { qs += '&project=' + encodeURIComponent(slug); }
      global.stationFetchJson(apiUrl('station-assist-api.php?' + qs))
        .then(function (data) {
          if (!data || !data.ok) { return; }
          if (sendBtn) { sendBtn.disabled = !data.openaiConfigured; }
          if (statusEl && !data.openaiConfigured) {
            statusEl.textContent = 'Add OpenAI under Admin → Integrations or User Settings.';
          }
        })
        .catch(function () {});
    }

    function appendMsg(role, text) {
      if (!log || !text) { return; }
      var div = document.createElement('div');
      div.className = 'assist-chat-msg assist-chat-msg-' + role;
      div.textContent = text;
      log.appendChild(div);
      log.scrollTop = log.scrollHeight;
    }

    function sendChat() {
      var msg = (input.value || '').trim();
      if (!msg) { return; }
      appendMsg('user', msg);
      input.value = '';
      sendBtn.disabled = true;
      if (statusEl) { statusEl.textContent = 'Thinking…'; }

      var slug = detectProjectFromUrl(window.location.href);
      global.stationFetchJson(apiUrl('station-assist-api.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: msg,
          pageUrl: window.location.href,
          pageTitle: document.title || '',
          project: slug,
          extraContext: extra ? extra.value : '',
          includePageContext: includePage ? includePage.checked : true,
          includeProjectContext: includeProject ? includeProject.checked : true
        })
      })
        .then(function (data) {
          sendBtn.disabled = false;
          if (statusEl) { statusEl.textContent = ''; }
          if (!data || !data.ok) {
            appendMsg('error', (data && data.message) ? data.message : 'Chat failed.');
            return;
          }
          appendMsg('assistant', data.reply || '(empty reply)');
        })
        .catch(function (err) {
          sendBtn.disabled = false;
          if (statusEl) { statusEl.textContent = ''; }
          appendMsg('error', err && err.message ? err.message : 'Network error.');
        });
    }

    toggle.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      setOpen(!panel.classList.contains('assist-fab-panel--open'));
    });
    if (closeBtn) {
      closeBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        setOpen(false);
      });
    }
    sendBtn.addEventListener('click', sendChat);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendChat();
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && panel.classList.contains('assist-fab-panel--open')) {
        setOpen(false);
      }
    });
    document.addEventListener('click', function (ev) {
      if (!panel.classList.contains('assist-fab-panel--open')) { return; }
      if (dock.contains(ev.target)) { return; }
      setOpen(false);
    });

    updateContextLabel();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAssist);
  } else {
    initAssist();
  }
})(window);
