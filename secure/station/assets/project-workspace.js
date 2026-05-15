(function () {
  'use strict';

  var cfg = window.STATION_WORKSPACE_IDE;
  if (!cfg || !cfg.project) {
    return;
  }

  var project = cfg.project;
  var canBuild = !!cfg.canBuild;
  var apiBase = 'project-workspace-api.php?project=' + encodeURIComponent(project);
  var browseDir = '';
  var openPath = cfg.initialFile || '';
  var dirty = false;

  var treeEl = document.getElementById('wsTree');
  var crumbEl = document.getElementById('wsBreadcrumb');
  var pathLabel = document.getElementById('wsOpenPath');
  var editor = document.getElementById('wsEditor');
  var statusEl = document.getElementById('wsStatus');
  var terminalPanel = document.getElementById('wsTerminal');
  var terminalFrame = document.getElementById('wsTerminalFrame');
  var ideBody = document.getElementById('wsIdeBody');
  var tabFiles = document.getElementById('wsTabFiles');
  var tabEditor = document.getElementById('wsTabEditor');
  var backToFiles = document.getElementById('wsBackToFiles');
  var mobileMq = window.matchMedia('(max-width: 760px)');

  function isMobileWorkspace() {
    return mobileMq.matches;
  }

  function setMobilePanel(panel) {
    if (!ideBody || !isMobileWorkspace()) {
      return;
    }
    var showEditor = panel === 'editor';
    ideBody.classList.toggle('workspace-ide-body--panel-files', !showEditor);
    ideBody.classList.toggle('workspace-ide-body--panel-editor', showEditor);
    if (tabFiles) {
      tabFiles.classList.toggle('is-active', !showEditor);
      tabFiles.setAttribute('aria-selected', showEditor ? 'false' : 'true');
    }
    if (tabEditor) {
      tabEditor.classList.toggle('is-active', showEditor);
      tabEditor.setAttribute('aria-selected', showEditor ? 'true' : 'false');
    }
  }

  function syncWorkspaceLayout() {
    if (!ideBody) {
      return;
    }
    if (!isMobileWorkspace()) {
      ideBody.classList.remove('workspace-ide-body--panel-files', 'workspace-ide-body--panel-editor');
      if (tabFiles) {
        tabFiles.classList.remove('is-active');
        tabFiles.setAttribute('aria-selected', 'false');
      }
      if (tabEditor) {
        tabEditor.classList.remove('is-active');
        tabEditor.setAttribute('aria-selected', 'false');
      }
      return;
    }
    if (!ideBody.classList.contains('workspace-ide-body--panel-files') &&
        !ideBody.classList.contains('workspace-ide-body--panel-editor')) {
      setMobilePanel('files');
    }
  }

  function bindMobileTabs() {
    function onTab(panel) {
      return function (ev) {
        ev.preventDefault();
        setMobilePanel(panel);
      };
    }
    if (tabFiles) {
      tabFiles.addEventListener('click', onTab('files'));
    }
    if (tabEditor) {
      tabEditor.addEventListener('click', onTab('editor'));
    }
    if (backToFiles) {
      backToFiles.addEventListener('click', onTab('files'));
    }
    if (typeof mobileMq.addEventListener === 'function') {
      mobileMq.addEventListener('change', syncWorkspaceLayout);
    }
  }

  bindMobileTabs();
  syncWorkspaceLayout();

  function setStatus(msg, isError) {
    if (!statusEl) {
      return;
    }
    statusEl.textContent = msg || '';
    statusEl.style.color = isError ? '#b91c1c' : '';
  }

  function apiGet(action, params) {
    var q = apiBase + '&action=' + encodeURIComponent(action);
    if (params) {
      Object.keys(params).forEach(function (k) {
        q += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      });
    }
    return fetch(q, { credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function apiPostJson(body) {
    return fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(Object.assign({ project: project }, body)),
    }).then(function (r) {
      return r.json();
    });
  }

  function formatSize(n) {
    n = Number(n) || 0;
    if (n < 1024) {
      return n + ' B';
    }
    if (n < 1048576) {
      return (n / 1024).toFixed(1) + ' KB';
    }
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function renderBreadcrumb() {
    if (!crumbEl) {
      return;
    }
    crumbEl.innerHTML = '';
    var parts = browseDir === '' ? [] : browseDir.split('/');
    var acc = '';
    var rootBtn = document.createElement('button');
    rootBtn.type = 'button';
    rootBtn.className = 'workspace-ide-crumb';
    rootBtn.textContent = project;
    rootBtn.addEventListener('click', function () {
      loadBrowse('');
    });
    crumbEl.appendChild(rootBtn);
    parts.forEach(function (part) {
      var sep = document.createElement('span');
      sep.className = 'workspace-ide-crumb-sep';
      sep.textContent = '/';
      crumbEl.appendChild(sep);
      acc = acc === '' ? part : acc + '/' + part;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'workspace-ide-crumb';
      btn.textContent = part;
      (function (path) {
        btn.addEventListener('click', function () {
          loadBrowse(path);
        });
      })(acc);
      crumbEl.appendChild(btn);
    });
  }

  function loadBrowse(dir) {
    browseDir = dir || '';
    renderBreadcrumb();
    if (treeEl) {
      treeEl.innerHTML = '<p class="workspace-ide-empty">Loading…</p>';
    }
    return apiGet('browse', { dir: browseDir }).then(function (data) {
      if (!treeEl) {
        return;
      }
      treeEl.innerHTML = '';
      if (!data || !data.ok) {
        treeEl.innerHTML = '<p class="workspace-ide-empty">' + (data && data.message ? data.message : 'Could not list folder.') + '</p>';
        return;
      }
      if (data.parent !== undefined && browseDir !== '') {
        var up = document.createElement('button');
        up.type = 'button';
        up.className = 'workspace-ide-row';
        up.innerHTML = '<span class="workspace-ide-row-icon">⬆</span><span>..</span>';
        up.addEventListener('click', function () {
          loadBrowse(data.parent || '');
        });
        treeEl.appendChild(up);
      }
      (data.entries || []).forEach(function (entry) {
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'workspace-ide-row' + (openPath === (browseDir ? browseDir + '/' + entry.name : entry.name) ? ' active' : '');
        var rel = browseDir === '' ? entry.name : browseDir + '/' + entry.name;
        if (entry.type === 'dir') {
          row.innerHTML = '<span class="workspace-ide-row-icon">📁</span><span>' + entry.name + '/</span>';
          row.addEventListener('click', function () {
            loadBrowse(rel);
          });
        } else {
          row.innerHTML =
            '<span class="workspace-ide-row-icon">📄</span><span>' +
            entry.name +
            '</span><span class="workspace-ide-row-meta">' +
            formatSize(entry.size) +
            '</span>';
          row.addEventListener('click', function () {
            openFile(rel);
          });
        }
        treeEl.appendChild(row);
      });
      if (!(data.entries || []).length) {
        treeEl.innerHTML = '<p class="workspace-ide-empty">Empty folder</p>';
      }
    });
  }

  function openFile(path) {
    if (dirty && !window.confirm('Discard unsaved changes?')) {
      return;
    }
    if (isMobileWorkspace()) {
      setMobilePanel('editor');
    }
    openPath = path;
    if (pathLabel) {
      pathLabel.textContent = path;
    }
    if (editor) {
      editor.value = '';
      editor.disabled = true;
    }
    setStatus('Loading ' + path + '…');
    apiGet('file', { path: path }).then(function (data) {
      if (!editor) {
        return;
      }
      editor.disabled = !canBuild;
      if (!data || !data.ok) {
        setStatus('Could not open file.', true);
        return;
      }
      if (!data.readable) {
        editor.value = '';
        setStatus('Binary or unsupported file — download via project URL if served.', true);
        return;
      }
      editor.value = data.content || '';
      dirty = false;
      setStatus('Opened · ' + formatSize(data.size));
      loadBrowse(browseDir);
    });
  }

  function saveFile() {
    if (!canBuild || !openPath || !editor) {
      return;
    }
    setStatus('Saving…');
    apiPostJson({ action: 'save', path: openPath, content: editor.value })
      .then(function (data) {
        if (!data || !data.ok) {
          setStatus((data && data.error) || 'Save failed.', true);
          return;
        }
        dirty = false;
        setStatus('Saved.');
        loadBrowse(browseDir);
      })
      .catch(function () {
        setStatus('Save failed.', true);
      });
  }

  function mkdirPrompt() {
    var name = window.prompt('New folder name:', 'new-folder');
    if (!name) {
      return;
    }
    name = name.replace(/[/\\]+/g, '').trim();
    if (!name) {
      return;
    }
    var rel = browseDir === '' ? name : browseDir + '/' + name;
    apiPostJson({ action: 'mkdir', path: rel }).then(function (data) {
      setStatus(data && data.message ? data.message : 'Done', !(data && data.ok));
      loadBrowse(browseDir);
    });
  }

  function newFilePrompt() {
    var name = window.prompt('New file name:', 'index.html');
    if (!name) {
      return;
    }
    name = name.replace(/[/\\]+/g, '').trim();
    if (!name) {
      return;
    }
    var rel = browseDir === '' ? name : browseDir + '/' + name;
    apiPostJson({ action: 'save', path: rel, content: '' }).then(function (data) {
      if (data && data.ok) {
        loadBrowse(browseDir).then(function () {
          openFile(rel);
        });
      } else {
        setStatus((data && data.error) || 'Create failed.', true);
      }
    });
  }

  function deleteSelected() {
    if (!openPath) {
      setStatus('Select a file first.', true);
      return;
    }
    if (!window.confirm('Delete ' + openPath + '? This cannot be undone.')) {
      return;
    }
    apiPostJson({ action: 'delete', path: openPath }).then(function (data) {
      setStatus(data && data.message ? data.message : 'Done', !(data && data.ok));
      if (data && data.ok) {
        openPath = '';
        if (editor) {
          editor.value = '';
        }
        if (pathLabel) {
          pathLabel.textContent = '—';
        }
        loadBrowse(browseDir);
      }
    });
  }

  function uploadFiles(fileList) {
    if (!fileList || !fileList.length) {
      return;
    }
    var i = 0;
    function next() {
      if (i >= fileList.length) {
        loadBrowse(browseDir);
        setStatus('Upload complete.');
        return;
      }
      var fd = new FormData();
      fd.append('action', 'upload');
      fd.append('project', project);
      fd.append('dir', browseDir);
      fd.append('file', fileList[i]);
      setStatus('Uploading ' + fileList[i].name + '…');
      fetch(apiBase, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            setStatus((data && data.message) || (data && data.error) || 'Upload failed.', true);
          }
          i += 1;
          next();
        })
        .catch(function () {
          setStatus('Upload failed.', true);
        });
    }
    next();
  }

  function dockerAction(action) {
    var fd = new FormData();
    fd.append('project', project);
    fd.append('action', action);
    fd.append('format', 'json');
    setStatus('Docker ' + action + '…');
    fetch('docker-actions.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        setStatus((data && data.message) || 'Done', !(data && data.ok));
      })
      .catch(function () {
        setStatus('Docker action failed.', true);
      });
  }

  function setTerminalOpen(open) {
    if (!terminalPanel) {
      return;
    }
    terminalPanel.hidden = !open;
    if (open && terminalFrame && cfg.terminalUrl && !terminalFrame.src) {
      terminalFrame.src = cfg.terminalUrl;
    }
  }

  if (editor) {
    editor.addEventListener('input', function () {
      dirty = true;
    });
    editor.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 's') {
        e.preventDefault();
        saveFile();
      }
    });
  }

  var saveBtn = document.getElementById('wsSave');
  if (saveBtn) {
    saveBtn.addEventListener('click', saveFile);
  }
  var refreshBtn = document.getElementById('wsRefresh');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      loadBrowse(browseDir);
    });
  }
  var mkdirBtn = document.getElementById('wsMkdir');
  if (mkdirBtn) {
    mkdirBtn.addEventListener('click', mkdirPrompt);
  }
  var newFileBtn = document.getElementById('wsNewFile');
  if (newFileBtn) {
    newFileBtn.addEventListener('click', newFilePrompt);
  }
  var delBtn = document.getElementById('wsDelete');
  if (delBtn) {
    delBtn.addEventListener('click', deleteSelected);
  }
  var uploadInput = document.getElementById('wsUploadInput');
  if (uploadInput) {
    uploadInput.addEventListener('change', function () {
      uploadFiles(this.files);
      this.value = '';
    });
  }
  var termBtn = document.getElementById('wsToggleTerminal');
  if (termBtn) {
    termBtn.addEventListener('click', function () {
      setTerminalOpen(terminalPanel.hidden);
    });
  }
  var termClose = document.getElementById('wsTerminalClose');
  if (termClose) {
    termClose.addEventListener('click', function () {
      setTerminalOpen(false);
    });
  }

  ['wsDockerStart', 'wsDockerStop', 'wsDockerRestart'].forEach(function (id) {
    var btn = document.getElementById(id);
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      dockerAction(btn.getAttribute('data-docker-action') || 'restart');
    });
  });

  loadBrowse('').then(function () {
    if (openPath) {
      var parts = openPath.split('/');
      if (parts.length > 1) {
        parts.pop();
        browseDir = parts.join('/');
        loadBrowse(browseDir).then(function () {
          openFile(openPath);
        });
      } else {
        openFile(openPath);
      }
    }
    syncWorkspaceLayout();
  });
})();
