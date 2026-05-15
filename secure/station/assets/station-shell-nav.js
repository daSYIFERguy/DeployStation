/**
 * Soft navigation: swap dashboard main content without reloading the shell
 * (sidebar, AI Assist, clipboard stay mounted).
 */
(function (global) {
  'use strict';

  if (global.__stationShellNavInit) {
    return;
  }
  global.__stationShellNavInit = true;

  var loading = false;

  function stationBase() {
    return (global.STATION_WEB_BASE || '').replace(/\/$/, '');
  }

  function resolveUrl(href) {
    try {
      return new URL(href, window.location.href);
    } catch (_) {
      return null;
    }
  }

  function isSameStationPage(url) {
    if (!url || url.origin !== window.location.origin) {
      return false;
    }
    var path = url.pathname || '';
    var base = stationBase();
    if (base && path.indexOf(base) === 0) {
      path = path.slice(base.length) || '/';
    }
    if (/\/logout\.php$/i.test(path)) {
      return false;
    }
    if (/\/github-oauth/i.test(path)) {
      return false;
    }
    if (/\/index\.php$/i.test(path) && !url.search) {
      return false;
    }
    return /\.php$/i.test(path) || path === '/' || path.endsWith('/');
  }

  function shouldIntercept(anchor, event) {
    if (!anchor || anchor.tagName !== 'A') {
      return false;
    }
    if (anchor.hasAttribute('download')) {
      return false;
    }
    if (anchor.target && anchor.target !== '_self') {
      return false;
    }
    if (anchor.hasAttribute('data-station-full-nav')) {
      return false;
    }
    if (event && (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
      return false;
    }
    if (!document.querySelector('.dashboard-shell main.dashboard-main')) {
      return false;
    }
    var url = resolveUrl(anchor.getAttribute('href') || '');
    return url && isSameStationPage(url);
  }

  function runScripts(container) {
    var scripts = container.querySelectorAll('script');
    scripts.forEach(function (old) {
      var s = document.createElement('script');
      if (old.src) {
        s.src = old.src;
        if (old.async) {
          s.async = true;
        }
        if (old.defer) {
          s.defer = true;
        }
      } else {
        s.textContent = old.textContent;
      }
      old.parentNode.replaceChild(s, old);
    });
  }

  function syncNavActive(doc) {
    var activeHref = '';
    doc.querySelectorAll('.dashboard-menu-link').forEach(function (link) {
      if (link.classList.contains('active')) {
        activeHref = link.getAttribute('href') || '';
      }
    });
    if (!activeHref) {
      return;
    }
    document.querySelectorAll('.dashboard-menu-link').forEach(function (link) {
      var href = link.getAttribute('href') || '';
      link.classList.toggle('active', href === activeHref);
    });
    var mobileNav = document.querySelector('.dashboard-nav');
    if (mobileNav && mobileNav.classList.contains('is-open')) {
      mobileNav.classList.remove('is-open');
      var toggle = document.getElementById('dashboardMobileToggle');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation');
      }
    }
  }

  function dispatchPageChange(url, title) {
    try {
      window.dispatchEvent(new CustomEvent('station:page-change', {
        detail: { url: url, title: title || document.title }
      }));
    } catch (_) {}
  }

  function loadPage(url, push) {
    if (loading) {
      return;
    }
    loading = true;
    document.body.classList.add('station-shell-loading');

    fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'text/html',
        'X-Station-Shell-Nav': '1'
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var nextMain = doc.querySelector('main.dashboard-main');
        var currentMain = document.querySelector('main.dashboard-main');
        if (!nextMain || !currentMain) {
          window.location.href = url;
          return;
        }

        currentMain.innerHTML = nextMain.innerHTML;
        runScripts(currentMain);

        if (doc.title) {
          document.title = doc.title;
        }

        syncNavActive(doc);

        if (push) {
          history.pushState({ stationShell: true, url: url }, document.title, url);
        }

        dispatchPageChange(url, document.title);
        window.scrollTo(0, 0);
      })
      .catch(function () {
        window.location.href = url;
      })
      .finally(function () {
        loading = false;
        document.body.classList.remove('station-shell-loading');
      });
  }

  document.addEventListener('click', function (event) {
    var anchor = event.target.closest('a[href]');
    if (!shouldIntercept(anchor, event)) {
      return;
    }
    var url = resolveUrl(anchor.getAttribute('href'));
    if (!url) {
      return;
    }
    var absolute = url.href;
    if (absolute === window.location.href) {
      event.preventDefault();
      return;
    }
    event.preventDefault();
    loadPage(absolute, true);
  });

  window.addEventListener('popstate', function (event) {
    if (event.state && event.state.stationShell && event.state.url) {
      loadPage(event.state.url, false);
    }
  });

  if (history.state && !history.state.stationShell) {
    history.replaceState({ stationShell: true, url: window.location.href }, document.title, window.location.href);
  } else if (!history.state) {
    history.replaceState({ stationShell: true, url: window.location.href }, document.title, window.location.href);
  }

  global.__stationShellNavLoad = loadPage;
})(window);
