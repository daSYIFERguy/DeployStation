(function () {
  'use strict';

  const dataEl = document.getElementById('templateFilesData');
  const files = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};

  const codeEl = document.getElementById('templateViewerCode');
  const labelEl = document.getElementById('templateViewerFileLabel');
  const copyEl = document.getElementById('templateViewerCopy');
  document.querySelectorAll('[data-template-file]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const path = btn.getAttribute('data-template-file') || '';
      const content = Object.prototype.hasOwnProperty.call(files, path) ? files[path] : '';
      if (codeEl) { codeEl.textContent = content || ''; }
      if (labelEl) { labelEl.textContent = path || 'Select a file'; }
      document.querySelectorAll('[data-template-file]').forEach(function (other) {
        other.classList.remove('active');
      });
      btn.classList.add('active');
    });
  });
  if (copyEl) {
    copyEl.addEventListener('click', function () {
      const text = (codeEl && codeEl.textContent) || '';
      if (!text) { return; }
      try {
        navigator.clipboard.writeText(text)
          .then(function () {
            copyEl.textContent = 'Copied';
            window.setTimeout(function () { copyEl.textContent = 'Copy'; }, 1200);
          })
          .catch(function () { copyEl.textContent = 'Copy failed'; });
      } catch (_) {
        copyEl.textContent = 'Copy blocked';
      }
    });
  }

  const editorTree = document.getElementById('templateEditorTree');
  const editorBody = document.getElementById('templateEditorBody');
  const editorPath = document.getElementById('templateEditorPath');
  const editorLabel = document.getElementById('templateEditorFileLabel');
  const editorJson = document.getElementById('templateFilesJson');
  const addFileBtn = document.getElementById('templateTreeAddFile');
  const deleteFileBtn = document.getElementById('templateTreeDeleteFile');
  const editorForm = document.querySelector('[data-template-editor]');

  if (!editorTree || !editorBody) {
    return;
  }

  const fileMap = Object.assign({}, files);
  let activePath = '';

  function normalizePath(p) {
    return String(p || '').trim().replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+/g, '/');
  }

  function renderEditorTree() {
    const paths = Object.keys(fileMap).sort();
    if (paths.length === 0) {
      editorTree.innerHTML = '<p class="template-picker-empty">No files yet — add one.</p>';
      return;
    }
    const root = {};
    paths.forEach(function (path) {
      const parts = path.split('/');
      let cursor = root;
      parts.forEach(function (part, i) {
        if (!part) { return; }
        const isFile = i === parts.length - 1;
        if (!cursor[part]) {
          cursor[part] = { name: part, isFile: isFile, path: isFile ? path : '', children: {} };
        }
        cursor = cursor[part].children;
      });
    });

    function renderNode(nodeMap) {
      const names = Object.keys(nodeMap).sort();
      let html = '<ul class="template-tree-list">';
      names.forEach(function (name) {
        const node = nodeMap[name];
        if (node.isFile) {
          const active = node.path === activePath ? ' active' : '';
          const esc = node.path.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
          html += '<li class="template-tree-item template-tree-file"><button type="button" class="template-tree-link' + active + '" data-template-file="' + esc + '"><span class="template-tree-icon">📄</span><span>' + name + '</span></button></li>';
        } else {
          html += '<li class="template-tree-item template-tree-folder"><details open><summary><span class="template-tree-icon">📁</span><span>' + name + '</span></summary>';
          html += renderNode(node.children);
          html += '</details></li>';
        }
      });
      html += '</ul>';
      return html;
    }

    editorTree.innerHTML = renderNode(root);
    editorTree.querySelectorAll('[data-template-file]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        selectFile(btn.getAttribute('data-template-file') || '');
      });
    });
  }

  function selectFile(path) {
    path = normalizePath(path);
    if (!path) { return; }
    if (activePath && activePath !== path) {
      fileMap[activePath] = editorBody.value;
    }
    activePath = path;
    editorBody.value = Object.prototype.hasOwnProperty.call(fileMap, path) ? fileMap[path] : '';
    if (editorPath) { editorPath.value = path; }
    if (editorLabel) { editorLabel.textContent = path; }
    if (deleteFileBtn) { deleteFileBtn.disabled = false; }
    renderEditorTree();
  }

  if (editorPath) {
    editorPath.addEventListener('change', function () {
      const next = normalizePath(editorPath.value);
      if (!next || next === activePath) { return; }
      const body = editorBody.value;
      if (activePath) { delete fileMap[activePath]; }
      fileMap[next] = body;
      selectFile(next);
    });
  }

  editorBody.addEventListener('input', function () {
    if (activePath) { fileMap[activePath] = editorBody.value; }
  });

  if (addFileBtn) {
    addFileBtn.addEventListener('click', function () {
      const path = normalizePath(window.prompt('New file path (e.g. src/index.html):', 'index.html'));
      if (!path) { return; }
      if (Object.prototype.hasOwnProperty.call(fileMap, path)) {
        window.alert('That path already exists.');
        return;
      }
      fileMap[path] = '';
      selectFile(path);
      editorBody.focus();
    });
  }

  if (deleteFileBtn) {
    deleteFileBtn.addEventListener('click', function () {
      if (!activePath) { return; }
      if (Object.keys(fileMap).length <= 1) {
        window.alert('Templates must contain at least one file.');
        return;
      }
      if (!window.confirm('Delete ' + activePath + '?')) { return; }
      delete fileMap[activePath];
      activePath = '';
      editorBody.value = '';
      if (editorPath) { editorPath.value = ''; }
      if (editorLabel) { editorLabel.textContent = 'Select a file'; }
      deleteFileBtn.disabled = true;
      const first = Object.keys(fileMap).sort()[0];
      if (first) { selectFile(first); } else { renderEditorTree(); }
    });
  }

  if (editorForm) {
    editorForm.addEventListener('submit', function () {
      if (activePath) { fileMap[activePath] = editorBody.value; }
      if (editorJson) { editorJson.value = JSON.stringify(fileMap); }
    });
  }

  const firstPath = Object.keys(fileMap).sort()[0];
  if (firstPath) {
    selectFile(firstPath);
  } else {
    renderEditorTree();
  }
})();
