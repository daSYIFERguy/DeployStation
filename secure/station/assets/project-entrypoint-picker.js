(function () {
  'use strict';

  var cfg = window.STATION_ENTRYPOINT_PICKER;
  if (!cfg || !cfg.project) {
    return;
  }

  var projectSlug = cfg.project;
  var modal = document.getElementById('entrypointModal');
  var openBtn = document.getElementById('openEntrypointPicker');
  var listEl = document.getElementById('entrypointList');
  var crumbEl = document.getElementById('entrypointBreadcrumb');
  var statusEl = document.getElementById('entrypointPickerStatus');
  var dirInput = document.getElementById('webEntryDir');
  var fileInput = document.getElementById('webEntryFile');
  var saveBtn = document.getElementById('entrypointSaveBtn');
  var browseDir = '';

  var indexNames = { 'index.html': 1, 'index.htm': 1, 'index.php': 1 };

  function isIndexFile(name) {
    return !!indexNames[String(name || '').toLowerCase()];
  }

  function setModalOpen(open) {
    if (!modal) {
      return;
    }
    if (open) {
      modal.removeAttribute('hidden');
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('entrypoint-modal-open');
    } else {
      modal.setAttribute('hidden', 'hidden');
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('entrypoint-modal-open');
    }
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
    rootBtn.className = 'entrypoint-crumb';
    rootBtn.textContent = projectSlug;
    rootBtn.addEventListener('click', function () {
      loadBrowse('');
    });
    crumbEl.appendChild(rootBtn);
    parts.forEach(function (part) {
      acc = acc === '' ? part : acc + '/' + part;
      var sep = document.createElement('span');
      sep.className = 'entrypoint-crumb-sep';
      sep.textContent = '/';
      crumbEl.appendChild(sep);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'entrypoint-crumb';
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
    if (dirInput) {
      dirInput.value = browseDir;
    }
    if (fileInput) {
      fileInput.value = 'index.html';
    }
    if (saveBtn) {
      saveBtn.disabled = true;
    }
    if (statusEl) {
      statusEl.textContent = 'Loading…';
    }
    renderBreadcrumb();

    var url =
      'project-workspace-api.php?project=' +
      encodeURIComponent(projectSlug) +
      '&action=browse&mode=entrypoint&dir=' +
      encodeURIComponent(browseDir);

    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!listEl) {
          return;
        }
        listEl.innerHTML = '';
        if (!data || !data.ok) {
          if (statusEl) {
            statusEl.textContent = (data && data.message) ? data.message : (data && data.error) || 'Could not list folder.';
          }
          return;
        }
        var indexFiles = data.indexFiles || [];
        if (statusEl) {
          statusEl.textContent =
            indexFiles.length > 0
              ? 'Click an index file in this folder, or open a subfolder.'
              : 'Open a subfolder that contains index.html, index.htm, or index.php.';
        }
        var entries = data.entries || [];
        if (!entries.length) {
          listEl.innerHTML = '<p class="entrypoint-picker-empty">This folder is empty.</p>';
          return;
        }
        entries.forEach(function (entry) {
          var btn = document.createElement('button');
          btn.type = 'button';
          var isDir = entry.type === 'dir';
          var isIndex = entry.type === 'index' || (entry.type === 'file' && isIndexFile(entry.name));
          btn.className = 'entrypoint-row entrypoint-row-' + (isDir ? 'dir' : isIndex ? 'index' : 'file');
          if (isDir) {
            btn.innerHTML = '<span class="entrypoint-row-icon">📁</span><span>' + entry.name + '/</span>';
            btn.addEventListener('click', function () {
              var next = browseDir === '' ? entry.name : browseDir + '/' + entry.name;
              loadBrowse(next);
            });
          } else if (isIndex) {
            btn.innerHTML = '<span class="entrypoint-row-icon">📄</span><span>' + entry.name + '</span>';
            btn.addEventListener('click', function () {
              if (dirInput) {
                dirInput.value = browseDir;
              }
              if (fileInput) {
                fileInput.value = entry.name;
              }
              if (saveBtn) {
                saveBtn.disabled = false;
              }
              if (statusEl) {
                statusEl.textContent = 'Selected: ' + (browseDir ? browseDir + '/' : '') + entry.name;
              }
            });
          } else {
            btn.innerHTML = '<span class="entrypoint-row-icon">·</span><span>' + entry.name + '</span>';
            btn.disabled = true;
            btn.title = 'Not an index file';
          }
          listEl.appendChild(btn);
        });
      })
      .catch(function () {
        if (statusEl) {
          statusEl.textContent = 'Network error loading folder.';
        }
      });
  }

  function openPicker(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    setModalOpen(true);
    loadBrowse('');
  }

  if (openBtn) {
    openBtn.addEventListener('click', openPicker);
  } else {
    document.addEventListener('click', function (event) {
      var trigger = event.target && event.target.closest ? event.target.closest('#openEntrypointPicker') : null;
      if (trigger) {
        openPicker(event);
      }
    });
  }

  document.querySelectorAll('[data-entrypoint-close]').forEach(function (el) {
    el.addEventListener('click', function (event) {
      event.preventDefault();
      setModalOpen(false);
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) {
      setModalOpen(false);
    }
  });
})();
