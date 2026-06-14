(function () {
  'use strict';

  var API_BASE = '/api/roast-limited/orchestrator.php';
  var RESUME_BASE = '/api/roast-limited/resume.php';
  var ADS_JSON = '/api/data/roast-ads.json';

  var SIM_STEPS = [
    { phase: 'identify', label: 'Reading frame geometry…', pct: 20, slot: 1 },
    { phase: 'condition', label: 'Inspecting scratches and dirt…', pct: 45, slot: 2 },
    { phase: 'mods', label: 'Scanning for aftermarket crimes…', pct: 70, slot: 3 },
    { phase: 'judge', label: 'Calculating shame score…', pct: 90, slot: 0 }
  ];

  var stepMinMs = 1200;
  var lastJobId = '';
  var lastRetryable = false;
  var simTimer = null;
  var pollTimer = null;

  var els = {};

  function $(id) {
    return document.getElementById(id);
  }

  function initEls() {
    els.upload = $('roast-upload-section');
    els.loading = $('roast-loading-section');
    els.result = $('roast-result-section');
    els.error = $('roast-error-section');
    els.submit = $('roast-submit');
    els.image = $('roast-image');
    els.make = $('roast-make-override');
    els.model = $('roast-model-override');
    els.stepLabel = $('roast-step-label');
    els.stepPct = $('roast-step-pct');
    els.progress = $('roast-progress-bar');
    els.adContainer = $('roast-ad-container');
    els.score = $('roast-score-display');
    els.scoreCaption = $('roast-score-caption');
    els.identity = $('roast-identity');
    els.mods = $('roast-mods');
    els.noMods = $('roast-no-mods');
    els.roastText = $('roast-text');
    els.notice = $('roast-interpretation-notice');
    els.errorMsg = $('roast-error-message');
    els.retryBtn = $('roast-retry-btn');
    els.tryAgain = $('roast-try-again');
    els.eventMeta = $('roast-event-meta');
  }

  function hideAllPanels() {
    els.upload.classList.add('hidden');
    els.loading.classList.add('hidden');
    els.result.classList.add('hidden');
    els.error.classList.add('hidden');
  }

  function showUpload() {
    hideAllPanels();
    els.upload.classList.remove('hidden');
    stopSim();
    hideAds();
  }

  function showLoading() {
    hideAllPanels();
    els.loading.classList.remove('hidden');
  }

  function friendlyError(err) {
    var msg = (err && err.message) ? String(err.message) : '';
    if (msg.indexOf('401') !== -1 || (err && err.code === 'CLOUD_AUTH')) {
      return 'Groq API key rejected (401). OpenRouter should take over after you upload the latest API files — also verify GROQ key at console.groq.com starts with gsk_.';
    }
    if (msg.indexOf('API HTTP') !== -1) {
      return 'Cloud judge unavailable (' + msg + '). Retry — or check api/.env Groq + OpenRouter keys.';
    }
    return msg || 'Something went wrong.';
  }

  function showError(message, retryable) {
    stopSim();
    hideAds();
    hideAllPanels();
    els.error.classList.remove('hidden');
    els.errorMsg.textContent = message || 'Something went wrong.';
    lastRetryable = !!retryable;
    if (retryable) {
      els.retryBtn.classList.remove('hidden');
    } else {
      els.retryBtn.classList.add('hidden');
    }
  }

  function hideAds() {
    if (!els.adContainer) return;
    els.adContainer.querySelectorAll('.roast-ad-slot').forEach(function (n) {
      n.classList.add('hidden');
    });
  }

  function showAdSlot(slotNum) {
    hideAds();
    if (!slotNum) return;
    var slot = $('roast-ad-slot-' + slotNum);
    if (slot) slot.classList.remove('hidden');
  }

  function buildAdSlotsFromJson(ads) {
    if (!els.adContainer || !ads) return;
    var map = { 1: ads.identify, 2: ads.condition || ads.inspect, 3: ads.mods || ads.judge };
    Object.keys(map).forEach(function (num) {
      var cfg = map[num];
      if (!cfg) return;
      var div = document.createElement('div');
      div.id = 'roast-ad-slot-' + num;
      div.className = 'roast-ad-slot hidden rounded-xl border border-zinc-800 bg-zinc-900/80 p-3 max-h-[120px] overflow-hidden';
      var label = document.createElement('p');
      label.className = 'text-[10px] uppercase tracking-wider text-zinc-500 mb-1';
      label.textContent = cfg.label || 'While you wait';
      div.appendChild(label);
      var a = document.createElement('a');
      a.href = cfg.href || '#';
      a.className = 'flex items-center gap-3 group';
      if (cfg.img) {
        var img = document.createElement('img');
        img.src = cfg.img;
        img.alt = '';
        img.className = 'h-14 w-14 rounded-lg object-cover shrink-0';
        img.width = 56;
        img.height = 56;
        a.appendChild(img);
      }
      var span = document.createElement('span');
      span.className = 'text-sm text-zinc-200 group-hover:text-green-300 font-semibold leading-tight';
      span.textContent = cfg.title || '';
      a.appendChild(span);
      div.appendChild(a);
      els.adContainer.appendChild(div);
    });
    if (ads.step_min_ms) stepMinMs = ads.step_min_ms;
  }

  function setProgress(label, pct) {
    if (els.stepLabel) els.stepLabel.textContent = label;
    if (els.stepPct) els.stepPct.textContent = pct + '%';
    if (els.progress) els.progress.style.width = pct + '%';
  }

  function stopSim() {
    if (simTimer) {
      clearInterval(simTimer);
      simTimer = null;
    }
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startSimulatedSteps() {
    var idx = 0;
    setProgress(SIM_STEPS[0].label, SIM_STEPS[0].pct);
    showAdSlot(SIM_STEPS[0].slot);
    simTimer = setInterval(function () {
      idx++;
      if (idx >= SIM_STEPS.length) return;
      var s = SIM_STEPS[idx];
      setProgress(s.label, s.pct);
      showAdSlot(s.slot);
    }, stepMinMs);
  }

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  async function replaySteps(steps) {
    var list = steps && steps.length ? steps : SIM_STEPS.map(function (s) {
      return { label: s.label, pct: s.pct, phase: s.phase };
    });
    for (var i = 0; i < list.length; i++) {
      var st = list[i];
      var sim = SIM_STEPS.find(function (x) { return x.phase === st.phase; });
      setProgress(st.label || sim.label, st.pct || sim.pct);
      if (sim) showAdSlot(sim.slot);
      await sleep(stepMinMs);
    }
  }

  function renderResult(data) {
    hideAds();
    hideAllPanels();
    els.result.classList.remove('hidden');

    var r = data.result || {};
    var score = r.score != null ? r.score : '—';
    els.score.textContent = score;
    if (typeof score === 'number') {
      els.scoreCaption.textContent = score >= 85
        ? 'Elite cred — barely any roast fuel.'
        : score >= 65
          ? 'Decent cred. Survivable.'
          : score >= 40
            ? 'Mid cred — questionable choices visible.'
            : score >= 22
              ? 'Low cred — partial bike, wrong photo, or mystery parts.'
              : 'Rock bottom — not even a full bike.';
    }

    var id = r.identity || {};
    var conf = id.confidence != null ? ' (' + Math.round(id.confidence * 100) + '% confidence)' : '';
    els.identity.textContent = (id.make || '?') + ' ' + (id.model || '?') + conf;

    var inspect = r.inspect || {};
    var mods = inspect.visual_mods || [];
    els.mods.innerHTML = '';
    if (mods.length) {
      els.noMods.classList.add('hidden');
      mods.forEach(function (m) {
        var li = document.createElement('li');
        li.textContent = (m.part || 'Part') + ': ' + (m.observed_spec || '?') + ' (stock: ' + (m.stock_spec || '?') + ')';
        els.mods.appendChild(li);
      });
    } else {
      els.noMods.classList.remove('hidden');
    }

    els.roastText.textContent = r.roast || '(Roast unavailable — partial result.)';
    els.notice.textContent = r.interpretation_notice || r.partial_notice || '';
    if (r.pipeline_mode === 'local_fallback_pipeline' || r.pipeline_mode === 'hybrid') {
      els.notice.textContent += ' Vision fallback was used for one or more steps.';
    }
  }

  function handleEnvelope(env) {
    if (!env) {
      showError('Empty response from server.', true);
      return;
    }
    lastJobId = env.job_id || lastJobId;

    if (env.status === 'processing') {
      return;
    }

    stopSim();

    if (env.ok === false || env.status === 'failed') {
      var err = env.error || {};
      showError(friendlyError(err), !!err.retryable);
      return;
    }

    if (env.status === 'partial' || env.status === 'complete') {
      replaySteps(env.steps || []).then(function () {
        renderResult(env);
        if (env.status === 'partial' && env.error && env.error.retryable) {
          lastRetryable = true;
          els.retryBtn.classList.remove('hidden');
          els.error.classList.remove('hidden');
          els.errorMsg.textContent = friendlyError(env.error) || 'Partial result only.';
        }
      });
    }
  }

  async function pollResume(jobId, attempts) {
    var max = attempts || 15;
    var n = 0;
    return new Promise(function (resolve) {
      pollTimer = setInterval(async function () {
        n++;
        try {
          var res = await fetch(RESUME_BASE + '?job_id=' + encodeURIComponent(jobId), { credentials: 'same-origin' });
          if (res.ok) {
            var env = await res.json();
            if (env.status !== 'processing') {
              clearInterval(pollTimer);
              pollTimer = null;
              resolve(env);
              return;
            }
          }
        } catch (e) { /* ignore */ }
        if (n >= max) {
          clearInterval(pollTimer);
          pollTimer = null;
          resolve(null);
        }
      }, 2000);
    });
  }

  async function submitRoast(retryJobId) {
    var file = els.image.files && els.image.files[0];
    if (!retryJobId && !file) {
      showError('Pick a photo first.', false);
      return;
    }

    if (!retryJobId && window.RoastCredits) {
      try {
        await window.RoastCredits.init();
        window.RoastCredits.mountBanner('#roast-credits-mount');
        await window.RoastCredits.gate('solo');
      } catch (e) {
        showError((e && e.message) ? e.message : 'Ad or credits required.', false);
        return;
      }
    }

    showLoading();
    startSimulatedSteps();
    els.retryBtn.classList.add('hidden');
    els.error.classList.add('hidden');

    var fd = new FormData();
    if (retryJobId) {
      fd.append('retry_job_id', retryJobId);
    } else {
      fd.append('image', file);
    }
    if (els.make.value.trim()) fd.append('make_override', els.make.value.trim());
    if (els.model.value.trim()) fd.append('model_override', els.model.value.trim());
    var bypassEl = document.getElementById('roast-bypass-key');
    if (bypassEl && bypassEl.value.trim()) fd.append('bypass_key', bypassEl.value.trim());
    if (window.RoastCredits) {
      var unlock = window.RoastCredits.getUnlockToken();
      if (unlock) fd.append('ad_unlock_token', unlock);
    }

    try {
      var res = await fetch(API_BASE, { method: 'POST', body: fd, credentials: 'same-origin' });
      var env = null;
      try {
        env = await res.json();
      } catch (parseErr) {
        env = null;
      }

      if (!env && (res.status === 504 || res.status === 502) && lastJobId) {
        env = await pollResume(lastJobId);
      }

      if (!env) {
        showError('Server timeout — try again in a moment.', true);
        return;
      }

      lastJobId = env.job_id || lastJobId;
      handleEnvelope(env);
      if (window.RoastCredits) {
        window.RoastCredits.clearUnlockToken();
        window.RoastCredits.refreshBalance();
      }
    } catch (e) {
      if (lastJobId) {
        var recovered = await pollResume(lastJobId);
        if (recovered) {
          handleEnvelope(recovered);
          return;
        }
      }
      showError('Network error. Check connection and retry.', true);
    } finally {
      stopSim();
      hideAds();
    }
  }

  async function pingEvent() {
    try {
      var res = await fetch(API_BASE + '?ping=1', { credentials: 'same-origin' });
      var data = await res.json();
      if (data.step_min_ms) stepMinMs = data.step_min_ms;
      if (!data.event_active) {
        showError('This limited event is not active right now.', false);
        els.submit.disabled = true;
      }
      if (data.event_end && els.eventMeta) {
        els.eventMeta.textContent += ' Ends ' + data.event_end + '.';
      }
      if (!data.groq_configured && !data.vision_configured && els.eventMeta) {
        els.eventMeta.textContent += ' (Groq API key required on server.)';
      }
    } catch (e) { /* optional ping */ }
  }

  function bindEvents() {
    if (window.RoastCredits) {
      window.RoastCredits.init().then(function () {
        window.RoastCredits.mountBanner('#roast-credits-mount');
      }).catch(function () { /* optional */ });
    }
    els.submit.addEventListener('click', function () {
      lastJobId = '';
      submitRoast('');
    });
    els.retryBtn.addEventListener('click', function () {
      if (lastRetryable && lastJobId) {
        submitRoast(lastJobId);
      } else {
        showUpload();
      }
    });
    els.tryAgain.addEventListener('click', showUpload);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initEls();
    bindEvents();
    fetch(ADS_JSON).then(function (r) { return r.json(); }).then(buildAdSlotsFromJson).catch(function () {
      /* fallback: inline slots if fetch fails */
      ['identify', 'inspect', 'judge'].forEach(function (_, i) {
        var n = i + 1;
        if (!$('roast-ad-slot-' + n) && els.adContainer) {
          var d = document.createElement('div');
          d.id = 'roast-ad-slot-' + n;
          d.className = 'roast-ad-slot hidden text-xs text-zinc-500 p-2';
          d.textContent = 'While you wait — shop OnlyBikes parts at onlybikes.shop';
          els.adContainer.appendChild(d);
        }
      });
    });
    pingEvent();
  });
})();
