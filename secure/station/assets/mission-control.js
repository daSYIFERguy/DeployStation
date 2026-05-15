(function () {
  'use strict';

  var root = document.getElementById('missionControlRoot');
  if (!root) {
    return;
  }

  var pollCb = document.getElementById('missionLivePoll');
  var updatedEl = document.getElementById('missionUpdatedAt');
  var fleetMeta = document.getElementById('missionFleetMeta');
  var fleetGrid = document.getElementById('missionFleetGrid');
  var timer = null;

  function svgGauge(pct, stroke, label, size) {
    pct = Math.max(0, Math.min(100, pct));
    var r = Math.round(size * 0.38);
    var c = Math.round(size / 2);
    var circ = 2 * Math.PI * r;
    var dash = (pct / 100) * circ;
    var gap = Math.max(0.01, circ - dash);
    var center = label || (Math.round(pct) + '%');
    return '<svg class="mc-gauge-svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">'
      + '<circle cx="' + c + '" cy="' + c + '" r="' + r + '" fill="none" stroke="#1e293b" stroke-width="8" opacity="0.35"/>'
      + '<circle cx="' + c + '" cy="' + c + '" r="' + r + '" fill="none" stroke="' + stroke + '" stroke-width="8" stroke-linecap="round"'
      + ' stroke-dasharray="' + dash.toFixed(2) + ' ' + gap.toFixed(2) + '" transform="rotate(-90 ' + c + ' ' + c + ')"/>'
      + '<text x="' + c + '" y="' + (c + 4) + '" text-anchor="middle" class="mc-gauge-center">' + center + '</text>'
      + '</svg>';
  }

  function renderFleet(containers, maxCpu, maxMem) {
    if (!fleetGrid) {
      return;
    }
    maxCpu = Math.max(0.01, maxCpu || 0.01);
    maxMem = Math.max(0.01, maxMem || 0.01);
    if (!containers || !containers.length) {
      fleetGrid.innerHTML = '<p class="mc-empty">No container stats from Docker.</p>';
      if (fleetMeta) {
        fleetMeta.textContent = '0 active';
      }
      return;
    }
    if (fleetMeta) {
      fleetMeta.textContent = containers.length + ' active';
    }
    fleetGrid.innerHTML = containers.map(function (r) {
      var cpuW = Math.min(100, ((r.cpuNum || 0) / maxCpu) * 100);
      var memW = Math.min(100, ((r.memNum || 0) / maxMem) * 100);
      var name = r.name || 'container';
      return '<article class="mc-fleet-card">'
        + '<code class="mc-fleet-name">' + name + '</code>'
        + '<div class="mc-fleet-gauges">'
        + '<div class="mc-mini-gauge">' + svgGauge(cpuW, '#22d3ee', r.cpu || '', 72) + '<span>CPU</span></div>'
        + '<div class="mc-mini-gauge">' + svgGauge(memW, '#c084fc', r.memPerc || '', 72) + '<span>RAM</span></div>'
        + '</div></article>';
    }).join('');
  }

  function poll() {
    fetch('host-health-api.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return;
        }
        if (updatedEl) {
          var d = new Date();
          updatedEl.textContent = 'Updated ' + d.toLocaleTimeString();
        }
        renderFleet(data.containers || [], data.maxCpu, data.maxMem);
      })
      .catch(function () {});
  }

  function armPoll() {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    if (!pollCb || !pollCb.checked) {
      return;
    }
    poll();
    timer = setInterval(poll, 8000);
  }

  if (pollCb) {
    pollCb.addEventListener('change', armPoll);
    armPoll();
  }
})();
