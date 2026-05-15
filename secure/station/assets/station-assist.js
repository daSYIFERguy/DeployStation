(function (global) {
  'use strict';

  var STORAGE_OPEN = 'stationAssistOpen';
  var STORAGE_DOCKED = 'stationAssistDocked';

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

  function isDesktopDock() {
    return window.matchMedia('(min-width: 761px)').matches;
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
    var historyLoaded = false;

    function readStorage(key) {
      try { return sessionStorage.getItem(key); } catch (_) { return null; }
    }

    function writeStorage(key, val) {
      try { sessionStorage.setItem(key, val); } catch (_) {}
    }

    function syncBodyOverlay() {
      var assistOn = panel.classList.contains('assist-fab-panel--open');
      var clipOn = clipPanel && clipPanel.classList.contains('clip-fab-panel--open');
      document.body.classList.toggle('station-overlay-open', (assistOn && !isDesktopDock()) || clipOn);
    }

    function syncDockedLayout() {
      var open = panel.classList.contains('assist-fab-panel--open');
      var docked = open && isDesktopDock() && readStorage(STORAGE_DOCKED) !== '0';
      document.body.classList.toggle('station-assist-docked', docked);
    }

    function setOpen(open, options) {
      options = options || {};
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

      writeStorage(STORAGE_OPEN, o ? '1' : '0');
      if (o && isDesktopDock() && readStorage(STORAGE_DOCKED) === null) {
        writeStorage(STORAGE_DOCKED, '1');
      }
      syncDockedLayout();

      if (o) {
        if (clipFab && clipPanel) {
          clipFab.classList.remove('is-open');
          clipPanel.classList.remove('clip-fab-panel--open');
          clipPanel.style.display = 'none';
          dock.classList.remove('clip-open');
          if (clipToggle) { clipToggle.setAttribute('aria-expanded', 'false'); }
        }
        refreshContext();
        if (!historyLoaded) {
          loadChatHistory();
        }
        if (!options.skipFocus) {
          window.setTimeout(function () { input.focus(); }, 60);
        }
      }
      syncBodyOverlay();
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

    function syncProjectChip() {
      if (!includeProject) { return; }
      var slug = detectProjectFromUrl(window.location.href);
      var chip = includeProject.closest('.assist-ctx-chip');
      includeProject.disabled = !slug;
      if (!slug) {
        includeProject.checked = false;
      }
      if (chip) {
        chip.classList.toggle('is-unavailable', !slug);
      }
    }

    function appendMsg(role, text) {
      if (!log || !text) { return; }
      var div = document.createElement('div');
      div.className = 'assist-chat-msg assist-chat-msg-' + role;
      div.textContent = text;
      log.appendChild(div);
      log.scrollTop = log.scrollHeight;
    }

    function appendPageContextNote() {
      var slug = detectProjectFromUrl(window.location.href);
      var label = document.title || 'this page';
      if (slug) {
        label += ' · project ' + slug;
      }
      appendMsg('system', 'Now viewing: ' + label);
    }

    function loadChatHistory() {
      var qs = 'pageUrl=' + encodeURIComponent(window.location.href) +
        '&pageTitle=' + encodeURIComponent(document.title || '');
      var slug = detectProjectFromUrl(window.location.href);
      if (slug) { qs += '&project=' + encodeURIComponent(slug); }
      global.stationFetchJson(apiUrl('station-assist-api.php?' + qs))
        .then(function (data) {
          historyLoaded = true;
          if (!data || !data.ok) { return; }
          if (sendBtn) { sendBtn.disabled = !data.openaiConfigured; }
          if (Array.isArray(data.chatHistory) && data.chatHistory.length > 0) {
            var hasUser = log.querySelector('.assist-chat-msg-user, .assist-chat-msg-assistant');
            if (!hasUser) {
              log.innerHTML = '';
              data.chatHistory.forEach(function (turn) {
                if (!turn || !turn.role || !turn.content) { return; }
                var role = turn.role === 'assistant' ? 'assistant' : (turn.role === 'user' ? 'user' : 'system');
                appendMsg(role, turn.content);
              });
            }
          }
          if (statusEl && !data.openaiConfigured) {
            statusEl.textContent = 'Add OpenAI under Admin → Integrations or User Settings.';
          }
        })
        .catch(function () {
          historyLoaded = true;
        });
    }

    function refreshContext() {
      updateContextLabel();
      syncProjectChip();
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

    function runClientActions(actions) {
      if (!Array.isArray(actions)) { return; }
      actions.forEach(function (action) {
        if (!action || !action.type) { return; }
        if (action.type !== 'navigate' || !action.url) { return; }
        var url = String(action.url);
        if (action.hard) {
          window.location.href = url;
          return;
        }
        if (global.__stationShellNavLoad) {
          global.__stationShellNavLoad(url, true);
          return;
        }
        window.location.href = url;
      });
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
          runClientActions(data.clientActions);
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
      if (event.key === 'Escape' && panel.classList.contains('assist-fab-panel--open') && !isDesktopDock()) {
        setOpen(false);
      }
    });
    document.addEventListener('click', function (ev) {
      if (!panel.classList.contains('assist-fab-panel--open')) { return; }
      if (isDesktopDock()) { return; }
      if (dock.contains(ev.target)) { return; }
      setOpen(false);
    });

    window.addEventListener('station:page-change', function () {
      updateContextLabel();
      syncProjectChip();
      refreshContext();
      if (panel.classList.contains('assist-fab-panel--open')) {
        appendPageContextNote();
      }
    });

    window.matchMedia('(min-width: 761px)').addEventListener('change', syncDockedLayout);

    updateContextLabel();
    syncProjectChip();

    if (readStorage(STORAGE_OPEN) === '1') {
      setOpen(true, { skipFocus: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAssist);
  } else {
    initAssist();
  }
})(window);
