(function () {
  'use strict';

  const PVP_BUILD = 'v1.2.0';
  const PVP_DEBUG = /(?:^|[?&])debug=1(?:&|$)/.test(window.location.search) ||
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1';
  const PVP_DEBUG_MAX = 100;
  const PVP_API = '/api/roast-limited/pvp.php';
  const ROAST_API = '/api/roast-limited/orchestrator.php';
  const TOKEN_KEY = 'roast_pvp_token';
  const FACE_KEY = 'roast_pvp_face_hash';
  const VERIFIED_KEY = 'roast_pvp_verified';
  const SCORE_CACHE_KEY = 'roast_pvp_score_cache';
  const PLACEHOLDER = '--';

  const AUTO_JUDGE_FIRST_MS = 2500;
  const AUTO_JUDGE_INTERVAL_MS = 10000;

  const CHALLENGES = [
    {
      id: 'center',
      label: 'Center',
      prompt: 'Look at the camera',
      stableNeeded: 1,
      test: function (m) {
        const t = getLivenessThresholds();
        return Math.abs(m.yaw) < t.centerYaw && Math.abs(m.pitch) < t.centerPitch;
      },
      hint: function () { return 'Face the camera — hold still for a second'; },
    },
    {
      id: 'left',
      label: 'Left',
      prompt: 'Turn your head LEFT',
      stableNeeded: 1,
      test: function (m) { return m.yaw < -getLivenessThresholds().turnYaw; },
      hint: function () { return 'Turn a bit more to your left'; },
    },
    {
      id: 'right',
      label: 'Right',
      prompt: 'Turn your head RIGHT',
      stableNeeded: 1,
      test: function (m) { return m.yaw > getLivenessThresholds().turnYaw; },
      hint: function () { return 'Turn a bit more to your right'; },
    },
  ];

  const LIVENESS_STABLE_FRAMES = 1;
  const LIVENESS_STUCK_MS = 8000;
  const REALNESS_STUCK_MIN = 48;

  const QUEUE_STEPS = [
    { label: 'Scanning verified riders...', pct: 22 },
    { label: 'Pairing similar chaos levels...', pct: 44 },
    { label: 'Rolling dice for stranger match...', pct: 66 },
    { label: 'Almost connected...', pct: 88 },
  ];

  const JUDGE_STEPS = [
    { label: 'Reading frame geometry...', pct: 18 },
    { label: 'Inspecting scratches and dirt...', pct: 36 },
    { label: 'Scanning for aftermarket crimes...', pct: 54 },
    { label: 'Cross-checking opponent build...', pct: 72 },
    { label: 'Calculating shame scores...', pct: 90 },
  ];

  const state = {
    token: '',
    faceHash: '',
    matchId: '',
    role: '',
    matchStatus: '',
    localStream: null,
    remoteStream: null,
    bikeFacing: 'environment',
    modeConfirmed: false,
    modeConfirmedMatchId: '',
    modeSetPromise: null,
    modeSetPromiseMatchId: '',
    modeRetryTimer: null,
    liveScoringTimer: null,
    liveScoringBusy: false,
    duelPollTimer: null,
    landmarker: null,
    livenessIdx: 0,
    livenessLandmarks: [],
    livenessAborted: false,
    livenessForceAdvance: false,
    livenessStepStartedAt: 0,
    livenessLastMetrics: null,
    livenessActive: false,
    livenessRealness: { faceFrames: 0, totalFrames: 0, minYaw: 99, maxYaw: -99 },
    turnstileSiteKey: '',
    turnstileWidgetId: null,
    turnstileBusy: false,
    pollTimer: null,
    timerInterval: null,
    secondsLeft: 0,
    pc: null,
    signalSince: 0,
    signalTimer: null,
    makingOffer: false,
    pendingIce: [],
    webrtcMatchId: '',
    webrtcStarting: false,
    iceServersCache: null,
    turnSource: '',
    turnWarning: '',
    localIceCounts: { host: 0, srflx: 0, relay: 0, prflx: 0 },
    remoteIceCounts: { host: 0, srflx: 0, relay: 0, prflx: 0 },
    webrtcRetryCount: 0,
    remoteVideoWatchdog: null,
    webrtcRetryTimer: null,
    remoteAttachTimer: null,
    fastPollTimer: null,
    iceGatherTimer: null,
    statsTimer: null,
    queueAnimTimer: null,
    judgeAnimTimer: null,
    queueAnimIdx: 0,
    judgeAnimIdx: 0,
    liveStats: { online: 0, queue: 0, duels: 0 },
    debugLines: [],
  };

  function pvpDebug(level, msg, detail) {
    const ts = new Date().toISOString().slice(11, 19);
    let line = ts + ' [' + level + '] ' + msg;
    if (detail !== undefined && detail !== null) {
      let extra = detail;
      if (typeof detail === 'object') {
        try { extra = JSON.stringify(detail); } catch (e) { extra = String(detail); }
      }
      if (extra !== '' && extra !== '{}') line += ' ' + extra;
    }
    if (PVP_DEBUG) {
      if (level === 'error') console.error('[Roast PvP]', line);
      else if (level === 'warn') console.warn('[Roast PvP]', line);
      else console.info('[Roast PvP]', line);
      state.debugLines.push(line);
      if (state.debugLines.length > PVP_DEBUG_MAX) state.debugLines.shift();
      const el = $('pvp-webrtc-debug');
      if (el) {
        el.textContent = state.debugLines.join('\n');
        el.scrollTop = el.scrollHeight;
      }
    } else if (level === 'error') {
      console.error('[Roast PvP]', msg);
    }
  }

  function dumpWebRtcDebug() {
    if (!PVP_DEBUG) {
      return { build: PVP_BUILD, debug: false, hint: 'Add ?debug=1 to the URL for diagnostics.' };
    }
    const oppVid = $('pvp-cam-opp');
    return {
      build: PVP_BUILD,
      matchId: state.matchId,
      role: state.role,
      webrtcMatchId: state.webrtcMatchId,
      signalSince: state.signalSince,
      hasRemoteVideo: hasRemoteVideo(),
      localTracks: state.localStream ? state.localStream.getTracks().map(function (t) {
        return { kind: t.kind, state: t.readyState, enabled: t.enabled, muted: t.muted };
      }) : [],
      remoteTracks: state.remoteStream ? state.remoteStream.getTracks().map(function (t) {
        return { kind: t.kind, state: t.readyState, enabled: t.enabled, muted: t.muted };
      }) : [],
      pc: state.pc ? {
        signaling: state.pc.signalingState,
        connection: state.pc.connectionState,
        ice: state.pc.iceConnectionState,
        gathering: state.pc.iceGatheringState,
      } : null,
      oppVideo: oppVid ? {
        readyState: oppVid.readyState,
        videoWidth: oppVid.videoWidth,
        videoHeight: oppVid.videoHeight,
        paused: oppVid.paused,
        muted: oppVid.muted,
      } : null,
      turnSource: state.turnSource,
      turnWarning: state.turnWarning,
      localIceCounts: state.localIceCounts,
      remoteIceCounts: state.remoteIceCounts,
      webrtcRetryCount: state.webrtcRetryCount,
      log: state.debugLines.slice(),
    };
  }

  function iceCandidateType(candidateStr) {
    if (!candidateStr) return 'unknown';
    const match = String(candidateStr).match(/\btyp (\w+)/);
    return match ? match[1] : 'unknown';
  }

  function countIceCandidate(bucket, candidateStr) {
    const typ = iceCandidateType(candidateStr);
    if (bucket[typ] === undefined) bucket[typ] = 0;
    bucket[typ] += 1;
  }

  function clearIceServersCache() {
    state.iceServersCache = null;
  }

  function $(id) {
    return document.getElementById(id);
  }

  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function getToken() {
    if (!state.token) {
      state.token = sessionStorage.getItem(TOKEN_KEY) || uuid();
      sessionStorage.setItem(TOKEN_KEY, state.token);
    }
    return state.token;
  }

  function stopLiveScoring() {
    if (state.liveScoringTimer) clearInterval(state.liveScoringTimer);
    state.liveScoringTimer = null;
    state.liveScoringBusy = false;
  }

  function stopDuelPoll() {
    if (state.duelPollTimer) clearInterval(state.duelPollTimer);
    state.duelPollTimer = null;
  }

  function revokeBikeLastFrame() { /* no separate bike preview */ }

  function loadCachedScores() {
    try {
      const raw = sessionStorage.getItem(SCORE_CACHE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || typeof data !== 'object') return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function saveCachedScores(you, opp, yourBest) {
    const prev = loadCachedScores() || {};
    sessionStorage.setItem(SCORE_CACHE_KEY, JSON.stringify({
      you: you != null ? you : (prev.you != null ? prev.you : null),
      opp: opp != null ? opp : (prev.opp != null ? prev.opp : null),
      yourBest: yourBest != null ? yourBest : (prev.yourBest != null ? prev.yourBest : null),
      at: Date.now(),
    }));
  }

  function applyCachedScoresToUi() {
    const cached = loadCachedScores();
    if (!cached) return;
    updateLiveScores({
      you_score: cached.you,
      opponent_score: cached.opp,
      your_best: cached.yourBest,
    });
  }

  function hasLiveFaceTrack() {
    if (!state.localStream) return false;
    return state.localStream.getVideoTracks().some(function (t) {
      return t.readyState === 'live' && t.enabled;
    });
  }

  function replaceLocalTracksOnPeerConnection() {
    if (!state.pc || !state.localStream) return;
    const senders = state.pc.getSenders();
    state.localStream.getTracks().forEach(function (track) {
      if (track.readyState !== 'live') return;
      var sender = null;
      for (var i = 0; i < senders.length; i++) {
        if (senders[i].track && senders[i].track.kind === track.kind) {
          sender = senders[i];
          break;
        }
      }
      if (sender) {
        sender.replaceTrack(track).catch(function (e) {
          pvpDebug('warn', 'replaceTrack failed', e && e.message ? e.message : String(e));
        });
      } else {
        try { state.pc.addTrack(track, state.localStream); } catch (e) { /* ignore */ }
      }
    });
    attachSelfVideos();
  }

  function waitForVideoFrame(videoEl, timeoutMs) {
    return new Promise(function (resolve) {
      if (!videoEl) {
        resolve(false);
        return;
      }
      if (videoEl.readyState >= 2 && videoEl.videoWidth > 0) {
        resolve(true);
        return;
      }
      var done = false;
      var timer = setTimeout(function () {
        if (done) return;
        done = true;
        resolve(videoEl.readyState >= 2 && videoEl.videoWidth > 0);
      }, timeoutMs || 4000);
      videoEl.onloadeddata = function () {
        if (done) return;
        if (videoEl.videoWidth > 0) {
          done = true;
          clearTimeout(timer);
          resolve(true);
        }
      };
    });
  }

  function bikeFrameBlobFromVideo(videoEl) {
    return new Promise(function (resolve) {
      if (!videoEl || videoEl.readyState < 2 || !videoEl.videoWidth) {
        resolve(null);
        return;
      }
      var maxW = 640;
      var vw = videoEl.videoWidth;
      var vh = videoEl.videoHeight;
      var scale = vw > maxW ? maxW / vw : 1;
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(vw * scale);
      canvas.height = Math.round(vh * scale);
      var ctx = canvas.getContext('2d');
      if (!ctx) {
        resolve(null);
        return;
      }
      ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', 0.82);
    });
  }

  async function captureBikeJudgingFrame() {
    return await bikeFrameBlobFromVideo($('pvp-cam-you'));
  }

  function setDuelCaptureStatus(text) {
    const el = $('pvp-duel-status');
    if (el && text) el.textContent = text;
  }

  function resetSession() {
    stopMatchTimers();
    stopLiveScoring();
    stopDuelPoll();
    teardownWebRtc();
    stopStream(state.localStream);
    state.localStream = null;
    state.remoteStream = null;
    state.matchId = '';
    state.role = '';
    state.matchStatus = '';
    state.livenessIdx = 0;
    state.livenessLandmarks = [];
    state.livenessAborted = false;
    state.livenessForceAdvance = false;
    state.livenessStepStartedAt = 0;
    state.livenessLastMetrics = null;
    state.livenessActive = false;
    state.livenessRealness = { faceFrames: 0, totalFrames: 0, minYaw: 99, maxYaw: -99 };
    state.turnstileWidgetId = null;
    state.turnstileBusy = false;
    state.debugLines = [];
    state.bikeFacing = 'environment';
    state.modeConfirmed = false;
    state.modeConfirmedMatchId = '';
    state.modeSetPromise = null;
    state.modeSetPromiseMatchId = '';
    if (state.modeRetryTimer) clearTimeout(state.modeRetryTimer);
    state.modeRetryTimer = null;
    const savedFace = sessionStorage.getItem(FACE_KEY);
    const savedVerified = sessionStorage.getItem(VERIFIED_KEY);
    sessionStorage.removeItem(TOKEN_KEY);
    state.token = uuid();
    sessionStorage.setItem(TOKEN_KEY, state.token);
    if (savedFace) {
      state.faceHash = savedFace;
      sessionStorage.setItem(FACE_KEY, savedFace);
    } else {
      state.faceHash = '';
    }
    if (savedVerified) {
      sessionStorage.setItem(VERIFIED_KEY, savedVerified);
    }
  }

  function stopStream(stream) {
    if (!stream) return;
    stream.getTracks().forEach((t) => t.stop());
  }

  function hideAll() {
    ['pvp-lobby', 'pvp-liveness', 'pvp-queue', 'pvp-duel', 'pvp-judging', 'pvp-vs', 'pvp-error'].forEach((id) => {
      const el = $(id);
      if (el) el.classList.add('hidden');
    });
    const quitTop = $('pvp-quit-top');
    if (quitTop) quitTop.classList.add('hidden');
  }

  function showError(msg) {
    stopMatchTimers();
    hideAll();
    const errSec = $('pvp-error');
    if (errSec) errSec.classList.remove('hidden');
    const errMsg = $('pvp-error-message');
    if (errMsg) errMsg.textContent = msg || 'Something went wrong.';
  }

  function bypassKey() {
    if (!PVP_DEBUG) return '';
    const el = $('pvp-bypass-key');
    return el && el.value.trim() ? el.value.trim() : '';
  }

  async function pvpPost(action, extra) {
    extra = extra || {};
    const fd = new FormData();
    fd.append('action', action);
    fd.append('token', getToken());
    Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
    const res = await fetch(PVP_API, { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!res.ok) {
      let body = null;
      try { body = await res.json(); } catch (e) { /* ignore */ }
      return body || { ok: false, error: { message: 'Request failed (' + res.status + ')' } };
    }
    return res.json();
  }

  async function pvpStatus() {
    const res = await fetch(PVP_API + '?action=status&token=' + encodeURIComponent(getToken()), { credentials: 'same-origin' });
    return res.json();
  }

  async function pvpStats() {
    const res = await fetch(
      PVP_API + '?action=stats&token=' + encodeURIComponent(getToken()),
      { credentials: 'same-origin' }
    );
    return res.json();
  }

  async function pvpSignals() {
    if (!state.matchId) return { ok: true, signals: [] };
    const url = PVP_API + '?action=signals&match_id=' + encodeURIComponent(state.matchId) +
      '&token=' + encodeURIComponent(getToken()) + '&since=' + state.signalSince;
    const res = await fetch(url, { credentials: 'same-origin' });
    return res.json();
  }

  function stopQueueAnim() {
    if (state.queueAnimTimer) clearInterval(state.queueAnimTimer);
    state.queueAnimTimer = null;
  }

  function stopJudgeAnim() {
    if (state.judgeAnimTimer) clearInterval(state.judgeAnimTimer);
    state.judgeAnimTimer = null;
  }

  function stopMatchTimers() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    if (state.timerInterval) clearInterval(state.timerInterval);
    state.pollTimer = null;
    state.timerInterval = null;
    stopQueueAnim();
    stopJudgeAnim();
  }

  function stopAll() {
    stopMatchTimers();
  }

  function setProgress(labelId, barId, label, pct) {
    const lbl = $(labelId);
    const bar = $(barId);
    if (lbl) lbl.textContent = label;
    if (bar) bar.style.width = String(pct) + '%';
  }

  function startQueueAnim() {
    stopQueueAnim();
    state.queueAnimIdx = 0;
    function tick() {
      const step = QUEUE_STEPS[state.queueAnimIdx % QUEUE_STEPS.length];
      setProgress('pvp-queue-step', 'pvp-queue-bar', step.label, step.pct);
      state.queueAnimIdx += 1;
    }
    tick();
    state.queueAnimTimer = setInterval(tick, 1400);
  }

  function startJudgeAnim(waitingForOpp) {
    stopJudgeAnim();
    state.judgeAnimIdx = 0;
    function tick() {
      if (waitingForOpp) {
        setProgress('pvp-judge-label', 'pvp-judge-bar', 'Waiting for opponent to submit...', 40);
        return;
      }
      const step = JUDGE_STEPS[state.judgeAnimIdx % JUDGE_STEPS.length];
      setProgress('pvp-judge-label', 'pvp-judge-bar', step.label, step.pct);
      state.judgeAnimIdx += 1;
    }
    tick();
    if (!waitingForOpp) {
      state.judgeAnimTimer = setInterval(tick, 1200);
    }
  }

  function updateLiveStatsUI() {
    const s = state.liveStats;
    const online = s.online || 0;
    const queue = s.queue || 0;
    const duels = s.duels || 0;

    const countEl = $('pvp-live-count');
    if (countEl) {
      countEl.textContent = online === 1 ? '1 rider online' : online + ' riders online';
    }

    const detailEl = $('pvp-live-detail');
    if (detailEl) {
      if (duels > 0 && queue > 0) {
        detailEl.textContent = queue + ' in queue | ' + duels + ' live duel' + (duels === 1 ? '' : 's');
      } else if (duels > 0) {
        detailEl.textContent = duels + ' live duel' + (duels === 1 ? '' : 's') + ' right now';
      } else if (queue > 0) {
        detailEl.textContent = queue + ' waiting for a match';
      } else {
        detailEl.textContent = 'Be the next rider in queue';
      }
    }
  }

  async function refreshLiveStats() {
    try {
      const data = await pvpStats();
      if (data && data.ok) {
        state.liveStats = {
          online: data.online || 0,
          queue: data.queue || 0,
          duels: data.duels || 0,
        };
        updateLiveStatsUI();
      }
    } catch (e) { /* ignore */ }
  }

  function startLiveStatsPoll() {
    if (state.statsTimer) clearInterval(state.statsTimer);
    refreshLiveStats();
    state.statsTimer = setInterval(refreshLiveStats, 8000);
  }

  function formatTime(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m + ':' + String(s).padStart(2, '0');
  }

  function updateVideoStatus(label) {
    const statusEl = $('pvp-video-status');
    const video = $('pvp-local-preview');
    if (!statusEl) return;
    if (!video) {
      statusEl.textContent = label || 'Camera: no video element';
      return;
    }
    statusEl.textContent = (label ? label + ' | ' : '') +
      'readyState=' + video.readyState +
      ' w=' + (video.videoWidth || 0) +
      ' h=' + (video.videoHeight || 0) +
      ' paused=' + video.paused +
      ' srcObject=' + (video.srcObject ? 'yes' : 'no');
  }

  function watchPreviewVideo(context) {
    let ticks = 0;
    const maxTicks = 40;
    const timer = setInterval(function () {
      ticks += 1;
      const video = $('pvp-local-preview');
      updateVideoStatus(context);
      if (!video) return;
      if (video.readyState >= 2 && video.videoWidth > 0) {
        pvpDebug('info', context + ' preview OK', {
          w: video.videoWidth,
          h: video.videoHeight,
          paused: video.paused,
        });
        clearInterval(timer);
        return;
      }
      if (ticks >= maxTicks) {
        pvpDebug('error', context + ' preview stalled', {
          readyState: video.readyState,
          paused: video.paused,
          hasSrcObject: !!video.srcObject,
          tracks: state.localStream ? state.localStream.getTracks().map(function (t) {
            return t.kind + ':' + t.readyState;
          }) : [],
        });
        clearInterval(timer);
      }
    }, 250);
  }

  function playVideoElement(videoEl, label, attempt, afterPlay) {
    if (!videoEl) return;
    attempt = attempt || 0;
    const playPromise = videoEl.play();
    if (!playPromise || !playPromise.then) return;
    playPromise.then(function () {
      pvpDebug('info', label + ' play OK', {
        w: videoEl.videoWidth,
        h: videoEl.videoHeight,
        paused: videoEl.paused,
        muted: videoEl.muted,
      });
      if (afterPlay) afterPlay(videoEl);
    }).catch(function (err) {
      const msg = err && err.message ? err.message : String(err);
      const interrupted = err && err.name === 'AbortError' ||
        msg.indexOf('interrupted') !== -1 ||
        msg.indexOf('aborted') !== -1;
      if (interrupted && attempt < 5) {
        setTimeout(function () {
          playVideoElement(videoEl, label, attempt + 1);
        }, 60 * (attempt + 1));
        return;
      }
      pvpDebug('warn', label + ' play failed', msg);
      if (!videoEl.muted) {
        videoEl.muted = true;
        playVideoElement(videoEl, label + ' (muted)', 0);
      }
    });
  }

  function attachStream(videoEl, stream, opts) {
    opts = opts || {};
    if (!videoEl || !stream) {
      pvpDebug('warn', 'attachStream skipped — no element or stream', opts.label || '');
      return;
    }
    const label = opts.label || 'video';
    const sameStream = videoEl.srcObject === stream;
    if (!sameStream) {
      videoEl.srcObject = stream;
      videoEl.onloadedmetadata = function () {
        pvpDebug('info', label + ' metadata', {
          w: videoEl.videoWidth,
          h: videoEl.videoHeight,
          tracks: stream.getTracks().map(function (t) { return t.kind + ':' + t.readyState; }),
        });
      };
    }
    videoEl.muted = !!opts.muted;
    videoEl.playsInline = true;
    videoEl.autoplay = true;
    playVideoElement(videoEl, label, 0, opts.unmuteAfterPlay ? function () {
      if (opts.unmuteAfterPlay) enableStrangerAudio(false);
    } : null);
  }

  function showStrangerAudioPrompt(show) {
    const btn = $('pvp-enable-audio');
    if (btn) btn.classList.toggle('hidden', !show);
  }

  function enableStrangerAudio(fromUserGesture) {
    const stream = state.remoteStream;
    if (!stream) return false;
    const audioTracks = stream.getAudioTracks().filter(function (t) {
      return t.readyState === 'live' && t.enabled;
    });
    if (!audioTracks.length) {
      pvpDebug('info', 'no remote audio tracks yet');
      return false;
    }

    [$('pvp-cam-opp'), $('pvp-cam-opp-judge')].forEach(function (videoEl) {
      if (!videoEl || videoEl.srcObject !== stream) return;
      videoEl.muted = false;
      videoEl.volume = 1;
    });

    var audioOk = false;
    [$('pvp-stranger-audio'), $('pvp-stranger-audio-judge')].forEach(function (audioEl) {
      if (!audioEl) return;
      if (audioEl.srcObject !== stream) audioEl.srcObject = stream;
      audioEl.muted = false;
      audioEl.volume = 1;
      const p = audioEl.play();
      if (p && p.then) {
        p.then(function () { audioOk = true; }).catch(function () { /* try video path */ });
      }
    });

    [$('pvp-cam-opp'), $('pvp-cam-opp-judge')].forEach(function (videoEl) {
      if (!videoEl || videoEl.srcObject !== stream || !videoEl.muted) return;
      const p = videoEl.play();
      if (p && p.then) {
        p.then(function () { audioOk = true; }).catch(function () { /* ignore */ });
      }
    });

    const anyMuted = [$('pvp-cam-opp'), $('pvp-cam-opp-judge')].some(function (v) {
      return v && v.srcObject === stream && v.muted;
    });
    if (!anyMuted || audioOk) {
      showStrangerAudioPrompt(false);
      pvpDebug('info', 'stranger audio enabled', { tracks: audioTracks.length });
      return true;
    }
    if (fromUserGesture) {
      showStrangerAudioPrompt(true);
    } else {
      showStrangerAudioPrompt(true);
      pvpDebug('warn', 'stranger audio blocked — tap Enable audio');
    }
    return false;
  }

  function attachSelfVideos() {
    attachStream($('pvp-cam-you'), state.localStream, { muted: true, label: 'you' });
    attachStream($('pvp-cam-you-judge'), state.localStream, { muted: true, label: 'you-judge' });
  }

  function attachRemoteVideos() {
    if (!state.remoteStream) {
      pvpDebug('warn', 'attachRemoteVideos — no remoteStream');
      return;
    }
    if (!hasRemoteVideo()) {
      pvpDebug('info', 'attachRemoteVideos — waiting for remote video track');
      return;
    }
    attachStream($('pvp-cam-opp'), state.remoteStream, {
      muted: true,
      unmuteAfterPlay: true,
      label: 'stranger',
    });
    attachStream($('pvp-cam-opp-judge'), state.remoteStream, {
      muted: true,
      unmuteAfterPlay: true,
      label: 'stranger-judge',
    });
    enableStrangerAudio(false);
    updateWebRtcBanner();
  }

  function scheduleAttachRemoteVideos() {
    if (state.remoteAttachTimer) clearTimeout(state.remoteAttachTimer);
    state.remoteAttachTimer = setTimeout(function () {
      state.remoteAttachTimer = null;
      attachRemoteVideos();
    }, 100);
  }

  function setRemoteTrack(track) {
    if (!track) return;
    if (track.kind === 'video' && track.readyState === 'ended') return;
    if (!state.remoteStream) {
      state.remoteStream = new MediaStream();
    }
    state.remoteStream.getTracks().forEach(function (t) {
      if (t.kind === track.kind) {
        state.remoteStream.removeTrack(t);
      }
    });
    state.remoteStream.addTrack(track);
    track.enabled = true;
    scheduleAttachRemoteVideos();
  }

  function hasRemoteVideo() {
    if (!state.remoteStream) return false;
    return state.remoteStream.getVideoTracks().some(function (t) {
      return t.readyState === 'live' && t.enabled;
    });
  }

  function logWebRtcState(label) {
    if (!state.pc) {
      pvpDebug('info', label + ' (no pc yet)');
      return;
    }
    pvpDebug('info', label, {
      signaling: state.pc.signalingState,
      connection: state.pc.connectionState,
      ice: state.pc.iceConnectionState,
      gathering: state.pc.iceGatheringState,
      remoteVideo: hasRemoteVideo(),
    });
  }

  function monitorLocalTracks() {
    if (!state.localStream) return;
    state.localStream.getTracks().forEach(function (track) {
      track.onended = function () {
        pvpDebug('error', 'LOCAL ' + track.kind + ' track ended.');
      };
      track.onmute = function () {
        pvpDebug('warn', 'LOCAL ' + track.kind + ' track muted');
      };
    });
  }

  async function postSignal(signalType, payloadStr) {
    const res = await pvpPost('signal', {
      match_id: state.matchId,
      signal_type: signalType,
      payload: payloadStr,
    });
    if (!res || res.ok === false) {
      pvpDebug('error', 'signal POST ' + signalType + ' failed', res && res.error ? res.error : res);
    } else {
      pvpDebug('info', 'signal POST ' + signalType + ' ok');
    }
    return res;
  }

  function roastDisplayText(snap) {
    if (!snap) return '(No roast)';
    const r = String(snap.roast || '').trim();
    if (r && !/choked|try again in a minute/i.test(r)) return r;
    return '(No roast)';
  }

  function scoreText(val) {
    return val != null ? String(val) : PLACEHOLDER;
  }

  function updateLiveScores(data) {
    let you = data.you_score != null ? data.you_score : (data.you ? data.you.score : null);
    let opp = data.opponent_score != null ? data.opponent_score : (data.opponent ? data.opponent.score : null);
    const cached = loadCachedScores();
    if (you == null && cached && cached.you != null) you = cached.you;
    if (opp == null && cached && cached.opp != null) opp = cached.opp;
    if (you != null || opp != null || data.your_average != null || data.your_best != null) {
      saveCachedScores(you, opp, data.your_average != null ? data.your_average : data.your_best);
    }
    ['pvp-you-score-live', 'pvp-you-score-judge', 'pvp-you-score'].forEach(function (id) {
      const el = $(id);
      if (el) el.textContent = scoreText(you);
    });
    ['pvp-opp-score-live', 'pvp-opp-score-judge', 'pvp-opp-score'].forEach(function (id) {
      const el = $(id);
      if (el) el.textContent = scoreText(opp);
    });
  }

  function startCountdown(seconds) {
    state.secondsLeft = seconds;
    const timerEl = $('pvp-timer');
    if (timerEl) timerEl.textContent = formatTime(seconds);
    if (state.timerInterval) clearInterval(state.timerInterval);
    state.timerInterval = setInterval(function () {
      state.secondsLeft = Math.max(0, state.secondsLeft - 1);
      if (timerEl) timerEl.textContent = formatTime(state.secondsLeft);
      if (state.secondsLeft <= 0) {
        clearInterval(state.timerInterval);
        state.timerInterval = null;
        onTimerExpired();
      }
    }, 1000);
  }

  function bumpCountdown(extraSec) {
    state.secondsLeft = Math.max(state.secondsLeft, extraSec);
    const timerEl = $('pvp-timer');
    if (timerEl) timerEl.textContent = formatTime(state.secondsLeft);
  }

  async function onTimerExpired() {
    const st = await pvpStatus();
    handleState(st);
  }

  function initDuelCapture() {
    state.bikeFacing = state.bikeFacing || 'user';
    setDuelCaptureStatus('Point your top camera at your bike — auto-judging every ~10s. Flip camera if needed.');
  }

  async function flipBikeCamera() {
    state.bikeFacing = state.bikeFacing === 'environment' ? 'user' : 'environment';
    setDuelCaptureStatus('Switching camera...');

    try {
      const audioTracks = state.localStream ? state.localStream.getAudioTracks() : [];
      const newStream = await requestLocalMedia({ audio: false, facingMode: state.bikeFacing });

      audioTracks.forEach(function (t) { newStream.addTrack(t); });
      const newVideoTrack = newStream.getVideoTracks()[0];

      if (state.pc) {
        const senders = state.pc.getSenders();
        const videoSender = senders.find(function (s) { return s.track && s.track.kind === 'video'; });
        if (videoSender && newVideoTrack) {
          await videoSender.replaceTrack(newVideoTrack);
        }
      }

      if (state.localStream) {
        state.localStream.getVideoTracks().forEach(function (t) { t.stop(); });
      }

      state.localStream = newStream;
      monitorLocalTracks();
      attachSelfVideos();
      setDuelCaptureStatus('Auto-judging from your top camera every ~10s.');
    } catch (e) {
      pvpDebug('error', 'Camera switch failed', e.message);
      setDuelCaptureStatus('Camera switch failed. Check permissions.');
    }
  }

  function isModeLiveReady() {
    return !!(state.matchId && state.modeConfirmed && state.modeConfirmedMatchId === state.matchId);
  }

  function clearModeRetryTimer() {
    if (state.modeRetryTimer) clearTimeout(state.modeRetryTimer);
    state.modeRetryTimer = null;
  }

  function resetModeLiveState() {
    state.modeConfirmed = false;
    state.modeConfirmedMatchId = '';
    state.modeSetPromise = null;
    state.modeSetPromiseMatchId = '';
    clearModeRetryTimer();
  }

  async function ensureDuelModeLive() {
    if (!state.matchId) return false;
    if (isModeLiveReady()) return true;
    if (state.modeSetPromise && state.modeSetPromiseMatchId === state.matchId) {
      return state.modeSetPromise;
    }

    const matchId = state.matchId;
    state.modeSetPromiseMatchId = matchId;
    state.modeSetPromise = (async function () {
      try {
        const data = await pvpPost('set_mode', { match_id: matchId, mode: 'live' });
        if (data && data.ok) {
          state.modeConfirmed = true;
          state.modeConfirmedMatchId = matchId;
          if (data.match_id) state.matchId = data.match_id;
          if (data.status) state.matchStatus = data.status;
          refreshDuelUi(data);
          return true;
        }
        if (state.modeConfirmedMatchId === matchId) {
          state.modeConfirmed = false;
          state.modeConfirmedMatchId = '';
        }
      } catch (e) { /* ignore */ }
      return false;
    })();

    try {
      return await state.modeSetPromise;
    } finally {
      if (state.modeSetPromiseMatchId === matchId) {
        state.modeSetPromise = null;
        state.modeSetPromiseMatchId = '';
      }
    }
  }

  function isLiveFrameStatusReady(status) {
    return ['matched', 'active', 'dueling'].indexOf(status || '') >= 0;
  }

  async function syncLiveFrameMatchReady() {
    if (isLiveFrameStatusReady(state.matchStatus)) return true;
    try {
      const st = await pvpStatus();
      if (st && st.match_id) state.matchId = st.match_id;
      if (st && st.status) state.matchStatus = st.status;
      if (st && st.your_mode === 'live' && st.match_id) {
        state.modeConfirmed = true;
        state.modeConfirmedMatchId = st.match_id;
      }
      return !!(st && st.ok && isLiveFrameStatusReady(st.status));
    } catch (e) {
      return false;
    }
  }

  async function sendLiveFrame() {
    if (!state.matchId || state.liveScoringBusy) return;
    if (!isLiveFrameStatusReady(state.matchStatus)) {
      const matchReady = await syncLiveFrameMatchReady();
      if (!matchReady) {
        setDuelCaptureStatus('Waiting for match…');
        return;
      }
    }
    if (!isModeLiveReady()) {
      const ready = await ensureDuelModeLive();
      if (!ready) {
        setDuelCaptureStatus('Setting up live judging…');
        return;
      }
    }
    state.liveScoringBusy = true;
    setDuelCaptureStatus('Judging your bike...');

    try {
      const blob = await captureBikeJudgingFrame();
      if (!blob) {
        setDuelCaptureStatus('Could not capture frame — keep your bike in the top camera view.');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'live_frame');
      fd.append('token', getToken());
      fd.append('match_id', state.matchId);
      fd.append('image', blob, 'bike-frame.jpg');
      const bk = bypassKey();
      if (bk) fd.append('bypass_key', bk);

      const res = await fetch(PVP_API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data || data.ok === false) {
        const code = (data && data.error && data.error.code) || '';
        const msg = (data && data.error && data.error.message) || 'Judgment failed.';
        if (code === 'NOT_READY') {
          setDuelCaptureStatus(msg || 'Match starting…');
          return;
        }
        if (code === 'EXPIRED') {
          setDuelCaptureStatus(msg || 'Round over.');
          return;
        }
        if (code === 'MODE' || code === 'PVP' || code === 'NOT_READY') {
          resetModeLiveState();
          await ensureDuelModeLive();
          return;
        }
        setDuelCaptureStatus(msg);
      } else {
        if (data.match_id) state.matchId = data.match_id;
        if (data.status) state.matchStatus = data.status;
        updateLiveScores(data);
        const avg = data.your_average != null ? data.your_average : data.you_score;
        const count = data.frame_count || data.your_frame_count;
        if (avg != null && count) {
          setDuelCaptureStatus('This frame: ' + data.frame_score + ' — match average: ' + avg + ' (' + count + ' judgments). Next auto-judge in ~10s.');
        } else if (avg != null) {
          setDuelCaptureStatus('This frame: ' + data.frame_score + ' — match average: ' + avg + '. Next auto-judge in ~10s.');
        } else {
          setDuelCaptureStatus('Scored ' + data.frame_score + ' — auto-judging continues.');
        }
        if (data.status === 'complete' || data.status === 'expired') {
          renderResults(data);
        } else if (data.status === 'dueling') {
          showJudging(data);
        }
      }
    } catch (e) {
      setDuelCaptureStatus('Network error during judgment.');
    } finally {
      state.liveScoringBusy = false;
    }
  }

  function startAutoJudging() {
    stopLiveScoring();
    if (!isModeLiveReady()) return;

    setTimeout(function () {
      if (!state.liveScoringBusy && isModeLiveReady()) sendLiveFrame();
    }, AUTO_JUDGE_FIRST_MS);

    state.liveScoringTimer = setInterval(function () {
      if (!state.liveScoringBusy && isModeLiveReady()) sendLiveFrame();
    }, AUTO_JUDGE_INTERVAL_MS);
  }

  function scheduleModeRetry() {
    clearModeRetryTimer();
    state.modeRetryTimer = setTimeout(async function () {
      state.modeRetryTimer = null;
      if (!state.matchId) return;
      await ensureDuelModeLive();
      if (isModeLiveReady()) maybeStartAutoJudging();
    }, 3000);
  }

  function maybeStartAutoJudging() {
    if (!state.matchId || state.liveScoringTimer) return;
    if (!isModeLiveReady()) return;
    startAutoJudging();
  }

  function mediaErrorMessage(err) {
    if (!err) return 'Camera/mic blocked or unavailable.';
    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
      return 'Permission denied. Click the lock/camera icon in the address bar, allow camera and microphone, then tap Start again.';
    }
    if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
      return 'No camera or microphone found. Plug in your webcam if you use one, then reload the page.';
    }
    if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
      return 'Camera is in use by another app or blocked in Windows/Mac privacy settings. Close Zoom/OBS/etc., allow onlybikes.shop in system camera settings, then retry.';
    }
    if (err.name === 'OverconstrainedError' || err.name === 'ConstraintNotSatisfiedError') {
      return 'Camera could not start with the requested settings. Try Chrome or Edge, or reload and allow again.';
    }
    if (err.name === 'SecurityError') {
      return 'Camera blocked by browser security. Open https://onlybikes.shop/roast-pvp.html directly (not in an embedded browser).';
    }
    if (err.message) return 'Camera error: ' + err.message + ' (' + (err.name || 'Error') + ')';
    return 'Camera/mic blocked or unavailable.';
  }

  async function requestLocalMedia(opts) {
    opts = opts || {};
    var wantAudio = !!opts.audio;
    var facing = opts.facingMode || 'user';
    var attempts = [];
    if (wantAudio) {
      attempts.push({ video: { facingMode: { ideal: facing }, width: { ideal: 640 }, height: { ideal: 480 } }, audio: true });
      attempts.push({ video: { facingMode: { ideal: facing } }, audio: true });
      attempts.push({ video: true, audio: true });
      attempts.push({ video: { facingMode: { ideal: facing } }, audio: false });
      attempts.push({ video: true, audio: false });
    } else {
      attempts.push({ video: { facingMode: { ideal: facing }, width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
      attempts.push({ video: { facingMode: { ideal: facing } }, audio: false });
      attempts.push({ video: true, audio: false });
    }
    var lastErr = null;
    for (var i = 0; i < attempts.length; i++) {
      try {
        var stream = await navigator.mediaDevices.getUserMedia(attempts[i]);
        if (stream && stream.getVideoTracks().length) return stream;
        stream.getTracks().forEach(function (t) { t.stop(); });
        lastErr = new DOMException('No video track', 'NotFoundError');
      } catch (e) {
        lastErr = e;
        pvpDebug('warn', 'getUserMedia attempt ' + (i + 1) + ' failed', e && e.name ? e.name : String(e));
      }
    }
    throw lastErr || new DOMException('getUserMedia failed', 'NotFoundError');
  }

  async function enableMedia() {
    var btn = $('pvp-enable-media');
    var hint = $('pvp-perm-hint');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Requesting camera and mic...';
    }
    if (hint) hint.textContent = 'Check for a browser permission popup (top of window or address bar).';

    if (!window.isSecureContext) {
      showError('Camera requires HTTPS. Use https://onlybikes.shop/roast-pvp.html');
      resetMediaBtn(btn);
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showError('Camera API not available in this browser/tab.');
      resetMediaBtn(btn);
      return;
    }

    try {
      state.localStream = await requestLocalMedia({ audio: true, facingMode: 'user' });
      monitorLocalTracks();
      pvpDebug('info', 'camera/mic granted', state.localStream.getTracks().map(function (t) {
        return t.kind + ':' + t.readyState;
      }).join(', '));
    } catch (e) {
      showError(mediaErrorMessage(e));
      resetMediaBtn(btn);
      return;
    }

    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Start PvP - allow camera and mic';
    }
    if (hint) hint.textContent = '3 moves: center, left, right. Verified once per session.';

    if (hasSessionVerification()) {
      state.faceHash = getCachedFaceHash();
      pvpDebug('info', 'session verification cache hit');
      await goToQueueAfterVerify('Welcome back — finding opponent...');
      return;
    }

    hideAll();
    $('pvp-liveness').classList.remove('hidden');
    attachStream($('pvp-local-preview'), state.localStream, { muted: true, label: 'liveness' });
    watchPreviewVideo('liveness');
    updateVideoStatus('liveness');

    try {
      await startLiveness();
    } catch (e) {
      showError('Face check could not start. Try Chrome/Edge, or reload and allow permissions.');
      resetMediaBtn(btn);
    }
  }

  function resetMediaBtn(btn) {
    if (!btn) btn = $('pvp-enable-media');
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Start PvP - allow camera and mic';
    }
  }

  async function initLandmarker() {
    if (state.landmarker) return state.landmarker;
    var mod = await import('https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/+esm');
    var FilesetResolver = mod.FilesetResolver;
    var FaceLandmarker = mod.FaceLandmarker;
    var vision = await FilesetResolver.forVisionTasks(
      'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm'
    );
    var base = {
      baseOptions: {
        modelAssetPath: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task',
        delegate: 'GPU',
      },
      runningMode: 'VIDEO',
      numFaces: 1,
    };
    try {
      state.landmarker = await FaceLandmarker.createFromOptions(vision, base);
      pvpDebug('info', 'face model ready (GPU)');
    } catch (gpuErr) {
      pvpDebug('warn', 'GPU face model failed, trying CPU', gpuErr && gpuErr.message ? gpuErr.message : String(gpuErr));
      base.baseOptions.delegate = 'CPU';
      state.landmarker = await FaceLandmarker.createFromOptions(vision, base);
      pvpDebug('info', 'face model ready (CPU)');
    }
    return state.landmarker;
  }

  function headPoseMetrics(landmarks) {
    if (!landmarks || landmarks.length < 264) return null;
    const nose = landmarks[1] || landmarks[0];
    const leftEye = landmarks[33];
    const rightEye = landmarks[263];
    if (!nose || !leftEye || !rightEye) return null;
    const midEyeX = (leftEye.x + rightEye.x) / 2;
    const midEyeY = (leftEye.y + rightEye.y) / 2;
    const eyeDist = Math.max(Math.abs(rightEye.x - leftEye.x), 0.01);
    const rawYaw = (nose.x - midEyeX) / eyeDist;
    return {
      nx: nose.x,
      ny: nose.y,
      yaw: rawYaw,
      pitch: (nose.y - midEyeY) / eyeDist,
      landmarks: landmarks,
    };
  }

  async function sha256Hex(text) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return Array.from(new Uint8Array(buf)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  }

  function hasSessionVerification() {
    try {
      const raw = sessionStorage.getItem(VERIFIED_KEY);
      if (!raw) return false;
      const data = JSON.parse(raw);
      return !!(data && data.faceHash && data.at);
    } catch (e) {
      return false;
    }
  }

  function getCachedFaceHash() {
    try {
      const raw = sessionStorage.getItem(VERIFIED_KEY);
      if (raw) {
        const data = JSON.parse(raw);
        if (data && data.faceHash) return data.faceHash;
      }
    } catch (e) { /* ignore */ }
    return sessionStorage.getItem(FACE_KEY) || '';
  }

  function saveSessionVerification(faceHash, method) {
    if (!faceHash) return;
    sessionStorage.setItem(FACE_KEY, faceHash);
    sessionStorage.setItem(VERIFIED_KEY, JSON.stringify({
      faceHash: faceHash,
      at: Date.now(),
      method: method || 'liveness',
    }));
    state.faceHash = faceHash;
  }

  function computeRealnessScore() {
    const r = state.livenessRealness;
    if (!r.totalFrames) return 0;
    const faceRatio = r.faceFrames / r.totalFrames;
    const yawRange = Math.max(0, r.maxYaw - r.minYaw);
    let score = Math.round(faceRatio * 55);
    if (faceRatio >= 0.8) score += 18;
    if (yawRange >= 0.06) score += 12;
    if (state.livenessIdx > 0) score += 15;
    return Math.min(100, score);
  }

  function updateRealnessTracking(metrics, hasFace) {
    const r = state.livenessRealness;
    r.totalFrames += 1;
    if (!hasFace || !metrics) return;
    r.faceFrames += 1;
    r.minYaw = Math.min(r.minYaw, metrics.yaw);
    r.maxYaw = Math.max(r.maxYaw, metrics.yaw);
  }

  function getLivenessThresholds() {
    const score = computeRealnessScore();
    const ease = score >= 70 ? 1.3 : (score >= 45 ? 1.1 : 1.0);
    return {
      centerYaw: 0.48 * ease,
      centerPitch: 0.52 * ease,
      turnYaw: Math.max(0.07, 0.11 / ease),
    };
  }

  async function loadPvpPublicConfig() {
    try {
      const res = await fetch(PVP_API + '?action=config', { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && data.turnstileSiteKey) {
        state.turnstileSiteKey = data.turnstileSiteKey;
      }
    } catch (e) { /* ignore */ }
  }

  function loadTurnstileScript() {
    return new Promise(function (resolve, reject) {
      if (window.turnstile) {
        resolve();
        return;
      }
      var existing = document.getElementById('cf-turnstile-script');
      if (existing) {
        existing.addEventListener('load', function () { resolve(); });
        existing.addEventListener('error', function () { reject(new Error('Turnstile script failed')); });
        return;
      }
      var s = document.createElement('script');
      s.id = 'cf-turnstile-script';
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      s.async = true;
      s.defer = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Turnstile script failed')); };
      document.head.appendChild(s);
    });
  }

  function hideTurnstileWidget() {
    const wrap = $('pvp-turnstile-wrap');
    if (wrap) {
      wrap.classList.add('hidden');
      wrap.innerHTML = '';
    }
    state.turnstileWidgetId = null;
  }

  async function showTurnstileChallenge() {
    if (state.turnstileBusy) return;
    if (!state.turnstileSiteKey) {
      const msg = $('pvp-liveness-stuck-msg');
      if (msg) msg.textContent = 'Cloudflare check is not configured yet. Keep trying the head moves.';
      return;
    }
    state.turnstileBusy = true;
    const btn = $('pvp-liveness-skip-step');
    if (btn) btn.classList.add('hidden');
    const msg = $('pvp-liveness-stuck-msg');
    if (msg) msg.textContent = 'Complete the quick Cloudflare check below.';
    try {
      await loadTurnstileScript();
      const wrap = $('pvp-turnstile-wrap');
      if (!wrap || !window.turnstile) throw new Error('Turnstile unavailable');
      wrap.classList.remove('hidden');
      wrap.innerHTML = '';
      const mount = document.createElement('div');
      wrap.appendChild(mount);
      state.turnstileWidgetId = window.turnstile.render(mount, {
        sitekey: state.turnstileSiteKey,
        theme: 'dark',
        callback: function (token) {
          verifyTurnstileAndContinue(token);
        },
        'error-callback': function () {
          state.turnstileBusy = false;
          if (msg) msg.textContent = 'Cloudflare check failed — try again or keep doing the head moves.';
          refreshStuckButtonUi();
        },
        'expired-callback': function () {
          state.turnstileBusy = false;
          hideTurnstileWidget();
          refreshStuckButtonUi();
        },
      });
    } catch (e) {
      state.turnstileBusy = false;
      if (msg) msg.textContent = 'Could not load Cloudflare check. Keep trying the head moves.';
      refreshStuckButtonUi();
    }
  }

  async function verifyTurnstileAndContinue(token) {
    const promptEl = $('pvp-liveness-prompt');
    if (promptEl) promptEl.textContent = 'Verifying...';
    try {
      const fd = new FormData();
      fd.append('action', 'verify_turnstile');
      fd.append('token', getToken());
      fd.append('turnstile_token', token);
      const res = await fetch(PVP_API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data || data.ok === false) {
        throw new Error((data && data.error && data.error.message) || 'Verification failed');
      }
      await completeVerificationBypass('turnstile');
    } catch (e) {
      state.turnstileBusy = false;
      hideTurnstileWidget();
      const msg = $('pvp-liveness-stuck-msg');
      if (msg) msg.textContent = (e && e.message) || 'Verification failed. Try the head moves again.';
      refreshStuckButtonUi();
    }
  }

  async function completeVerificationBypass(method) {
    state.livenessAborted = true;
    state.livenessActive = false;
    hideTurnstileWidget();
    const seed = state.livenessLandmarks.length
      ? JSON.stringify(state.livenessLandmarks)
      : JSON.stringify(state.livenessLastMetrics || { t: Date.now() });
    const faceHash = await sha256Hex('pvp-' + method + '|' + seed + '|' + getToken());
    saveSessionVerification(faceHash, method);
    await goToQueueAfterVerify(method === 'turnstile' ? 'Verified — finding opponent...' : 'Face verified - finding opponent...');
  }

  async function goToQueueAfterVerify(queueText) {
    hideAll();
    $('pvp-queue').classList.remove('hidden');
    const qt = $('pvp-queue-text');
    if (qt) qt.textContent = queueText || 'Face verified - finding opponent...';
    startQueueAnim();
    refreshLiveStats();
    await findOpponent();
  }

  function refreshStuckButtonUi() {
    const btn = $('pvp-liveness-skip-step');
    const msg = $('pvp-liveness-stuck-msg');
    if (!btn) return;
    const stuckMs = Date.now() - state.livenessStepStartedAt;
    if (stuckMs < LIVENESS_STUCK_MS || state.turnstileBusy) {
      btn.classList.add('hidden');
      if (msg) msg.classList.add('hidden');
      return;
    }
    const score = computeRealnessScore();
    btn.classList.remove('hidden');
    if (score >= REALNESS_STUCK_MIN) {
      btn.textContent = 'Stuck? Verify with Cloudflare';
      btn.disabled = false;
      if (msg) {
        msg.textContent = 'You look real enough — tap above for a quick Cloudflare check (only if stuck).';
        msg.classList.remove('hidden');
      }
    } else {
      btn.textContent = 'Stuck? Keep your face in frame';
      btn.disabled = true;
      if (msg) {
        msg.textContent = 'Stay in frame a bit longer, then the Cloudflare option unlocks.';
        msg.classList.remove('hidden');
      }
    }
  }

  async function onLivenessStuckClick() {
    const score = computeRealnessScore();
    if (score < REALNESS_STUCK_MIN) {
      refreshStuckButtonUi();
      return;
    }
    await showTurnstileChallenge();
  }

  function livenessStepCount() {
    const section = $('pvp-liveness');
    const fromData = section && section.dataset.livenessMoves
      ? parseInt(section.dataset.livenessMoves, 10)
      : NaN;
    if (!isNaN(fromData) && fromData > 0) return fromData;
    return CHALLENGES.length;
  }

  function renderLivenessSteps() {
    const wrap = $('pvp-liveness-steps');
    if (!wrap) return;
    const staticSteps = wrap.querySelectorAll('.pvp-liveness-step');
    if (staticSteps.length) {
      staticSteps.forEach(function (span, i) {
        let cls = 'border-zinc-700 text-zinc-600';
        if (i < state.livenessIdx) cls = 'border-green-500 text-green-300';
        else if (i === state.livenessIdx) cls = 'border-amber-400 text-amber-200';
        span.className = 'pvp-liveness-step text-xs px-2 py-1 rounded-full border ' + cls;
      });
      return;
    }
    // Never rebuild from CHALLENGES — keeps old cached JS from drawing 5 pills.
    const labels = ['Center', 'Left', 'Right'];
    wrap.textContent = '';
    labels.forEach(function (label, i) {
      const span = document.createElement('span');
      let cls = 'border-zinc-700 text-zinc-600';
      if (i < state.livenessIdx) cls = 'border-green-500 text-green-300';
      else if (i === state.livenessIdx) cls = 'border-amber-400 text-amber-200';
      span.className = 'pvp-liveness-step text-xs px-2 py-1 rounded-full border ' + cls;
      span.textContent = label;
      span.dataset.step = String(i);
      wrap.appendChild(span);
    });
  }

  function formatLivenessPrompt(step) {
    const total = livenessStepCount();
    const n = Math.min(state.livenessIdx + 1, total);
    return 'Move ' + n + ' of ' + total + ': ' + step.prompt;
  }

  function showLivenessSkipButton(show) {
    if (!show) {
      const btn = $('pvp-liveness-skip-step');
      if (btn) btn.classList.add('hidden');
      return;
    }
    refreshStuckButtonUi();
  }

  function recordLivenessPose(metrics) {
    state.livenessLandmarks.push(metrics.landmarks.slice(0, 30).map(function (p) {
      return [p.x.toFixed(3), p.y.toFixed(3)];
    }));
    state.livenessIdx += 1;
    state.livenessStepStartedAt = Date.now();
    state.livenessForceAdvance = false;
    showLivenessSkipButton(false);
    renderLivenessSteps();
  }

  async function startLiveness() {
    renderLivenessSteps();
    showLivenessSkipButton(false);
    hideTurnstileWidget();
    state.livenessActive = true;
    state.livenessAborted = false;
    state.livenessRealness = { faceFrames: 0, totalFrames: 0, minYaw: 99, maxYaw: -99 };
    state.livenessStepStartedAt = Date.now();
    pvpDebug('info', 'startLiveness — loading face model');
    const lm = await initLandmarker();
    pvpDebug('info', 'face model ready');
    const video = $('pvp-local-preview');
    let lastVideoTime = -1;
    let stableFrames = 0;

    return new Promise(function (resolve, reject) {
      function maybeFinish() {
        if (state.livenessIdx >= livenessStepCount()) {
          finishLiveness().then(resolve).catch(reject);
          return true;
        }
        return false;
      }

      function tick() {
        if (state.livenessAborted) return;
        if (!video || video.readyState < 2) {
          requestAnimationFrame(tick);
          return;
        }
        if (video.currentTime === lastVideoTime) {
          requestAnimationFrame(tick);
          return;
        }
        lastVideoTime = video.currentTime;
        const result = lm.detectForVideo(video, performance.now());
        const faces = result.faceLandmarks || [];
        const promptEl = $('pvp-liveness-prompt');
        if (!faces.length) {
          stableFrames = 0;
          updateRealnessTracking(null, false);
          if (promptEl) promptEl.textContent = 'Keep your face in frame';
          refreshStuckButtonUi();
          requestAnimationFrame(tick);
          return;
        }
        const metrics = headPoseMetrics(faces[0]);
        if (!metrics) {
          requestAnimationFrame(tick);
          return;
        }
        state.livenessLastMetrics = metrics;
        updateRealnessTracking(metrics, true);

        if (Date.now() - state.livenessStepStartedAt >= LIVENESS_STUCK_MS) {
          showLivenessSkipButton(true);
        }

        const step = CHALLENGES[state.livenessIdx];
        const forced = state.livenessForceAdvance;
        const passed = forced || (step && step.test(metrics));

        if (promptEl && step) {
          if (passed && !forced) {
            promptEl.textContent = formatLivenessPrompt(step);
          } else if (forced) {
            promptEl.textContent = 'Continuing...';
          } else if (typeof step.hint === 'function') {
            promptEl.textContent = step.hint(metrics);
          } else {
            promptEl.textContent = formatLivenessPrompt(step);
          }
        }

        if (passed) {
          stableFrames += 1;
          var needed = forced ? 1 : (step.stableNeeded != null ? step.stableNeeded : LIVENESS_STABLE_FRAMES);
          if (stableFrames >= needed) {
            recordLivenessPose(metrics);
            if (maybeFinish()) return;
          }
        } else {
          stableFrames = 0;
        }
        requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    });
  }

  async function finishLiveness() {
    state.livenessActive = false;
    const payload = JSON.stringify(state.livenessLandmarks);
    state.faceHash = await sha256Hex(payload);
    saveSessionVerification(state.faceHash, 'liveness');
    await goToQueueAfterVerify('Face verified - finding opponent...');
  }

  async function skipLivenessVerification() {
    state.livenessAborted = true;
    state.livenessActive = false;
    state.faceHash = await sha256Hex('pvp-dev-skip|' + getToken());
    saveSessionVerification(state.faceHash, 'debug');
    await goToQueueAfterVerify('Skipped face check - finding opponent...');
  }

  async function findOpponent() {
    try {
      const data = await pvpPost('join', { face_hash: state.faceHash });
      handleState(data);
      if (data.status === 'waiting') {
        state.pollTimer = setInterval(async function () {
          const st = await pvpStatus();
          handleState(st);
          if (['active', 'dueling', 'complete', 'expired'].indexOf(st.status) >= 0) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
          }
        }, 1000);
      }
    } catch (e) {
      showError('Network error while matchmaking.');
    }
  }

  function teardownWebRtc() {
    if (state.remoteVideoWatchdog) {
      clearTimeout(state.remoteVideoWatchdog);
      state.remoteVideoWatchdog = null;
    }
    if (state.webrtcRetryTimer) {
      clearTimeout(state.webrtcRetryTimer);
      state.webrtcRetryTimer = null;
    }
    if (state.remoteAttachTimer) {
      clearTimeout(state.remoteAttachTimer);
      state.remoteAttachTimer = null;
    }
    if (state.fastPollTimer) {
      clearInterval(state.fastPollTimer);
      state.fastPollTimer = null;
    }
    if (state.iceGatherTimer) {
      clearTimeout(state.iceGatherTimer);
      state.iceGatherTimer = null;
    }
    if (state.signalTimer) {
      clearInterval(state.signalTimer);
      state.signalTimer = null;
    }
    if (state.pc) {
      state.pc.ontrack = null;
      state.pc.onicecandidate = null;
      state.pc.onconnectionstatechange = null;
      state.pc.oniceconnectionstatechange = null;
      state.pc.close();
      state.pc = null;
    }
    if (state.remoteStream) {
      state.remoteStream = null;
    }
    state.signalSince = 0;
    state.makingOffer = false;
    state.pendingIce = [];
    state.webrtcMatchId = '';
    state.webrtcStarting = false;
  }

  const DEFAULT_ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
    {
      urls: [
        'turn:openrelay.metered.ca:80',
        'turn:openrelay.metered.ca:443',
        'turn:openrelay.metered.ca:443?transport=tcp',
      ],
      username: 'openrelayproject',
      credential: 'openrelayproject',
    },
  ];

  async function getIceServers(forceRefresh) {
    if (forceRefresh) clearIceServersCache();
    if (state.iceServersCache) return state.iceServersCache;
    try {
      const res = await fetch(PVP_API + '?action=ice', { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && data.iceServers && data.iceServers.length) {
        state.iceServersCache = data.iceServers;
        state.turnSource = data.turnSource || '';
        state.turnWarning = data.turnWarning || '';
        var turnCount = data.iceServers.filter(function (s) {
          var u = s.urls;
          if (Array.isArray(u)) return u.some(function (x) { return String(x).indexOf('turn') === 0; });
          return String(u).indexOf('turn') === 0;
        }).length;
        pvpDebug('info', 'ICE from API', data.iceServers.length + ' entries, ' + turnCount + ' TURN, source=' + state.turnSource);
        if (state.turnWarning) {
          pvpDebug('warn', 'TURN not production-ready', state.turnWarning);
        }
        if (state.turnSource === 'openrelay_fallback') {
          pvpDebug('error', 'Using unreliable OpenRelay TURN — cross-network video will often fail. Configure api/.env PVP_TURN_*.');
        }
        return state.iceServersCache;
      }
    } catch (e) { /* fallback below */ }
    state.iceServersCache = DEFAULT_ICE_SERVERS;
    state.turnSource = 'client_fallback';
    state.turnWarning = 'ICE API unreachable — using built-in OpenRelay fallback.';
    pvpDebug('warn', 'ICE API failed — client fallback OpenRelay');
    return state.iceServersCache;
  }

  async function flushPendingIce() {
    if (!state.pc || !state.pc.remoteDescription) return;
    while (state.pendingIce.length) {
      const raw = state.pendingIce.shift();
      try {
        await state.pc.addIceCandidate(raw instanceof RTCIceCandidate ? raw : new RTCIceCandidate(raw));
      } catch (e) { /* ignore */ }
    }
  }

  async function addRemoteIce(raw) {
    if (!state.pc) return;
    const candidate = raw instanceof RTCIceCandidate ? raw : new RTCIceCandidate(raw);
    if (!state.pc.remoteDescription) {
      state.pendingIce.push(candidate);
      return;
    }
    try {
      await state.pc.addIceCandidate(candidate);
    } catch (e) {
      pvpDebug('warn', 'addIceCandidate failed', e && e.message ? e.message : String(e));
    }
  }

  async function clearSignals() {
    if (!state.matchId) return;
    try {
      await pvpPost('clear_signals', { match_id: state.matchId });
    } catch (e) { /* ignore */ }
    state.signalSince = 0;
  }

  async function processSignal(sig) {
    if (!state.pc || !sig) return;
    state.signalSince = Math.max(state.signalSince, sig.id);
    let payload;
    try {
      payload = JSON.parse(sig.payload);
    } catch (e) {
      return;
    }

    try {
      if (sig.signal_type === 'offer') {
        if (state.pc.signalingState !== 'stable') {
          pvpDebug('warn', 'skip offer — signalingState=' + state.pc.signalingState);
          return;
        }
        if (state.pc.remoteDescription) return;
        pvpDebug('info', 'applying remote OFFER');
        await state.pc.setRemoteDescription(new RTCSessionDescription(payload));
        await flushPendingIce();
        const answer = await state.pc.createAnswer();
        await state.pc.setLocalDescription(answer);
        await postSignal('answer', JSON.stringify(answer));
        logWebRtcState('answer sent');
        return;
      }

      if (sig.signal_type === 'answer') {
        if (state.pc.signalingState !== 'have-local-offer') {
          pvpDebug('warn', 'skip answer — signalingState=' + state.pc.signalingState);
          return;
        }
        if (state.pc.remoteDescription) return;
        pvpDebug('info', 'applying remote ANSWER');
        await state.pc.setRemoteDescription(new RTCSessionDescription(payload));
        await flushPendingIce();
        logWebRtcState('answer applied');
        return;
      }

      if (sig.signal_type === 'ice' && payload && payload.candidate) {
        countIceCandidate(state.remoteIceCounts, payload.candidate);
        await addRemoteIce(payload);
        pvpDebug('info', 'ICE candidate added', iceCandidateType(payload.candidate) + ' ' + payload.candidate.substring(0, 36) + '...');
      }
    } catch (e) {
      pvpDebug('error', 'signal error (' + sig.signal_type + ')', e && e.message ? e.message : String(e));
    }
  }

  async function pollSignalsOnce() {
    try {
      const res = await pvpSignals();
      if (!res.ok) {
        pvpDebug('warn', 'signal poll rejected', res.error || res);
        return;
      }
      if (!res.signals || !res.signals.length) return;
      pvpDebug('info', 'signals received', res.signals.map(function (s) {
        return s.signal_type + '#' + s.id;
      }).join(', '));
      for (let i = 0; i < res.signals.length; i++) {
        await processSignal(res.signals[i]);
      }
    } catch (e) {
      pvpDebug('error', 'signal poll exception', e && e.message ? e.message : String(e));
    }
  }

  async function fetchSignalStats() {
    if (!state.matchId) return;
    try {
      const url = PVP_API + '?action=signal_stats&match_id=' + encodeURIComponent(state.matchId) +
        '&token=' + encodeURIComponent(getToken());
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok) pvpDebug('info', 'server signals', data.counts);
    } catch (e) { /* ignore */ }
  }

  function updateWebRtcBanner() {
    const banner = $('pvp-duel-banner');
    if (!banner) return;
    if (hasRemoteVideo()) {
      banner.textContent = 'Stranger connected';
      return;
    }
    const cs = state.pc ? state.pc.connectionState : '';
    const ice = state.pc ? state.pc.iceConnectionState : '';
    if (cs === 'connected' && ice === 'connected') {
      banner.textContent = 'Stranger connected';
    } else if (cs === 'connecting' || ice === 'checking' || cs === 'new') {
      banner.textContent = 'Connecting stranger video...';
    } else if (cs === 'failed' || ice === 'failed') {
      banner.textContent = 'Video failed — retrying with TURN...';
    } else if (ice === 'disconnected') {
      banner.textContent = 'Video disconnected — retrying...';
    }
  }

  function scheduleWebRtcRetry(isOfferer, delayMs) {
    if (state.webrtcRetryTimer) clearTimeout(state.webrtcRetryTimer);
    const wait = typeof delayMs === 'number' ? delayMs : 12000;
    state.webrtcRetryTimer = setTimeout(function () {
      state.webrtcRetryTimer = null;
      if (!state.matchId || hasRemoteVideo()) return;
      const cs = state.pc ? state.pc.connectionState : '';
      const ice = state.pc ? state.pc.iceConnectionState : '';
      if (cs === 'connected' || ice === 'connected') return;
      if (cs === 'connecting' || cs === 'new' || ice === 'checking') {
        scheduleWebRtcRetry(isOfferer, 4000);
        return;
      }
      pvpDebug('warn', 'WebRTC retry', { connection: cs, ice: ice });
      clearIceServersCache();
      startWebRtc(isOfferer, true);
    }, wait);
  }

  function ensureWebRtc(isOfferer) {
    if (!state.matchId || !state.localStream) {
      pvpDebug('warn', 'ensureWebRtc blocked', { matchId: !!state.matchId, localStream: !!state.localStream });
      return;
    }
    if (state.webrtcStarting) {
      pvpDebug('info', 'ensureWebRtc — already starting');
      return;
    }
    if (state.webrtcMatchId === state.matchId && state.pc && state.signalTimer) {
      pvpDebug('info', 'ensureWebRtc — reusing existing pc');
      attachSelfVideos();
      attachRemoteVideos();
      return;
    }
    pvpDebug('info', 'ensureWebRtc start', { isOfferer: isOfferer, role: state.role, matchId: state.matchId });
    startWebRtc(isOfferer, false).catch(function (e) {
      pvpDebug('error', 'WebRTC start failed', e && e.message ? e.message : String(e));
    });
  }

  function startRemoteVideoWatchdog(isOfferer) {
    if (state.remoteVideoWatchdog) clearTimeout(state.remoteVideoWatchdog);
    state.remoteVideoWatchdog = setTimeout(function () {
      state.remoteVideoWatchdog = null;
      if (!state.matchId || hasRemoteVideo()) return;
      logWebRtcState('watchdog — no remote video');
      fetchSignalStats();
      startWebRtc(isOfferer, true);
    }, 10000);
  }

  async function startWebRtc(isOfferer, isRetry) {
    if (!state.matchId || !state.localStream) return;
    if (state.webrtcStarting) return;

    if (!isRetry && state.webrtcMatchId === state.matchId && state.pc) {
      const cs = state.pc.connectionState;
      if (cs === 'connected' || cs === 'connecting' || cs === 'new') {
        attachSelfVideos();
        attachRemoteVideos();
        return;
      }
      if (state.signalTimer) {
        attachSelfVideos();
        attachRemoteVideos();
        return;
      }
    }

    state.webrtcStarting = true;
    if (isRetry) {
      state.webrtcRetryCount += 1;
    } else {
      state.webrtcRetryCount = 0;
    }
    state.localIceCounts = { host: 0, srflx: 0, relay: 0, prflx: 0 };
    state.remoteIceCounts = { host: 0, srflx: 0, relay: 0, prflx: 0 };
    const forceRelay = state.webrtcRetryCount >= 2;
    pvpDebug('info', 'startWebRtc', {
      isOfferer: isOfferer,
      isRetry: isRetry,
      retryCount: state.webrtcRetryCount,
      forceRelay: forceRelay,
      role: state.role,
    });
    try {
      if (isRetry) {
        pvpDebug('info', 'clearing old signals');
        await clearSignals();
      }
      if (isRetry || state.webrtcMatchId !== state.matchId) {
        teardownWebRtc();
        state.webrtcMatchId = state.matchId;
      }

      const iceServers = await getIceServers(isRetry);
      state.pc = new RTCPeerConnection({
        iceServers: iceServers,
        iceTransportPolicy: forceRelay ? 'relay' : 'all',
        iceCandidatePoolSize: 10,
        bundlePolicy: 'max-bundle',
      });
      if (forceRelay) {
        pvpDebug('warn', 'forcing relay-only ICE transport on retry');
      }

      state.pc.onconnectionstatechange = function () {
        logWebRtcState('connection change');
        updateWebRtcBanner();
        if (state.pc && state.pc.connectionState === 'failed') {
          scheduleWebRtcRetry(isOfferer, 1500);
        }
      };
      state.pc.oniceconnectionstatechange = function () {
        logWebRtcState('ice change');
        updateWebRtcBanner();
        if (!state.pc) return;
        if (state.pc.iceConnectionState === 'failed') {
          scheduleWebRtcRetry(isOfferer, 1500);
        } else if (state.pc.iceConnectionState === 'disconnected') {
          scheduleWebRtcRetry(isOfferer, 5000);
        }
      };
      state.pc.onicegatheringstatechange = function () {
        if (!state.pc) return;
        pvpDebug('info', 'ICE gathering', {
          state: state.pc.iceGatheringState,
          localIce: state.localIceCounts,
        });
        if (state.pc.iceGatheringState === 'complete') {
          if (state.iceGatherTimer) {
            clearTimeout(state.iceGatherTimer);
            state.iceGatherTimer = null;
          }
          if (!state.localIceCounts.relay) {
            pvpDebug('error', 'No local relay candidates gathered — TURN server unreachable or not configured', {
              turnSource: state.turnSource,
              localIce: state.localIceCounts,
            });
          }
        }
      };

      var tracksAdded = 0;
      state.localStream.getTracks().forEach(function (track) {
        if (track.readyState === 'live') {
          state.pc.addTrack(track, state.localStream);
          tracksAdded += 1;
        } else {
          pvpDebug('error', 'local track not live', track.kind + ' state=' + track.readyState);
        }
      });
      pvpDebug('info', 'addTrack count', tracksAdded);
      if (tracksAdded === 0) {
        pvpDebug('error', 'No live tracks to send — face camera may have stopped');
      }

      state.pc.ontrack = function (ev) {
        pvpDebug('info', 'ontrack', {
          kind: ev.track ? ev.track.kind : '?',
          streams: ev.streams ? ev.streams.length : 0,
          state: ev.track ? ev.track.readyState : '',
        });
        if (ev.streams && ev.streams[0]) {
          const incoming = ev.streams[0];
          if (!state.remoteStream || state.remoteStream.id === incoming.id) {
            state.remoteStream = incoming;
          } else {
            incoming.getTracks().forEach(function (t) {
              setRemoteTrack(t);
            });
            scheduleAttachRemoteVideos();
            updateWebRtcBanner();
            return;
          }
        } else if (ev.track) {
          setRemoteTrack(ev.track);
          if (ev.track) {
            ev.track.onunmute = function () { scheduleAttachRemoteVideos(); };
            ev.track.onended = function () { logWebRtcState('remote track ended'); };
          }
          updateWebRtcBanner();
          return;
        }
        if (ev.track) {
          ev.track.onunmute = function () { scheduleAttachRemoteVideos(); };
          ev.track.onended = function () { logWebRtcState('remote track ended'); };
        }
        scheduleAttachRemoteVideos();
        updateWebRtcBanner();
      };

      state.pc.onicecandidate = function (ev) {
        if (ev.candidate && state.matchId) {
          countIceCandidate(state.localIceCounts, ev.candidate.candidate);
          postSignal('ice', JSON.stringify(ev.candidate));
        } else if (!ev.candidate) {
          pvpDebug('info', 'ICE gathering complete (local)', state.localIceCounts);
        }
      };

      if (isOfferer) {
        state.makingOffer = true;
        pvpDebug('info', 'creating OFFER');
        const offer = await state.pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true });
        await state.pc.setLocalDescription(offer);
        await postSignal('offer', JSON.stringify(offer));
        state.makingOffer = false;
      } else {
        pvpDebug('info', 'waiting for OFFER (answerer)');
      }

      await fetchSignalStats();
      await pollSignalsOnce();
      state.signalTimer = setInterval(function () {
        pollSignalsOnce();
      }, 700);

      if (state.iceGatherTimer) clearTimeout(state.iceGatherTimer);
      state.iceGatherTimer = setTimeout(function () {
        state.iceGatherTimer = null;
        if (!state.pc || state.pc.iceGatheringState === 'complete') return;
        pvpDebug('error', 'ICE gathering stuck', {
          gathering: state.pc.iceGatheringState,
          localIce: state.localIceCounts,
          turnSource: state.turnSource,
        });
      }, 10000);

      var fastPolls = 0;
      if (state.fastPollTimer) clearInterval(state.fastPollTimer);
      state.fastPollTimer = setInterval(function () {
        pollSignalsOnce();
        fastPolls += 1;
        if (fastPolls >= 30) {
          clearInterval(state.fastPollTimer);
          state.fastPollTimer = null;
        }
      }, 500);

      scheduleWebRtcRetry(isOfferer, 15000);
      startRemoteVideoWatchdog(isOfferer);
    } finally {
      state.webrtcStarting = false;
    }
  }

  function updateDuelStatus(data) {
    const el = $('pvp-duel-status');
    if (!el) return;
    if (data.message) {
      el.textContent = data.message;
      return;
    }
    if (!data.both_modes_ready) {
      el.textContent = 'Connecting… auto-judging starts when both riders are in.';
    } else {
      el.textContent = 'Best bike score wins. Your top camera auto-judges every ~10s.';
    }
  }

  function syncDuelTimer(data) {
    if (!data.both_modes_ready) {
      const timerEl = $('pvp-timer');
      if (timerEl) timerEl.textContent = '--:--';
      if (state.timerInterval) {
        clearInterval(state.timerInterval);
        state.timerInterval = null;
      }
      return;
    }
    if (data.seconds_remaining != null && !state.timerInterval) {
      startCountdown(data.seconds_remaining);
    } else if (data.seconds_remaining != null && state.timerInterval) {
      bumpCountdown(data.seconds_remaining);
    }
  }

  function startDuelPoll() {
    if (state.duelPollTimer) return;
    state.duelPollTimer = setInterval(async function () {
      if (!state.matchId) return;
      try {
        const st = await pvpStatus();
        if (st.status === 'complete' || st.status === 'expired') {
          stopDuelPoll();
          renderResults(st);
          return;
        }
        updateLiveScores(st);
        updateDuelStatus(st);
        if (st.status === 'active') {
          syncDuelTimer(st);
        }
        if (st.status === 'dueling') {
          showJudging(st);
        }
      } catch (e) { /* ignore */ }
    }, 2500);
  }

  function refreshDuelUi(data) {
    updateLiveScores(data);
    updateDuelStatus(data);
    syncDuelTimer(data);
    attachSelfVideos();
    attachRemoteVideos();
    updateWebRtcBanner();
  }

  async function showDuel(data) {
    stopQueueAnim();
    stopJudgeAnim();
    stopLiveScoring();
    clearModeRetryTimer();
    const incomingMatchId = (data && data.match_id) || state.matchId;
    if (incomingMatchId && state.modeConfirmedMatchId && state.modeConfirmedMatchId !== incomingMatchId) {
      resetModeLiveState();
    }
    hideAll();
    $('pvp-duel').classList.remove('hidden');
    $('pvp-quit-top').classList.remove('hidden');
    pvpDebug('info', 'enter duel', { role: state.role, matchId: state.matchId });
    if (data && data.status) state.matchStatus = data.status;
    initDuelCapture();
    applyCachedScoresToUi();
    refreshDuelUi(data);
    await ensureDuelModeLive();
    if (isModeLiveReady()) {
      maybeStartAutoJudging();
    } else {
      scheduleModeRetry();
    }
    ensureWebRtc(state.role === 'a');
    if (!state.duelPollTimer) startDuelPoll();
  }

  function showJudging(data) {
    stopQueueAnim();
    hideAll();
    $('pvp-judging').classList.remove('hidden');
    $('pvp-quit-top').classList.remove('hidden');
    updateLiveScores(data);
    attachSelfVideos();
    attachRemoteVideos();
    const waitingForOpp = !!(data.you_ready && !data.opponent_ready);
    startJudgeAnim(waitingForOpp);
  }

  function renderResults(data) {
    stopAll();
    stopLiveScoring();
    resetModeLiveState();
    stopDuelPoll();
    teardownWebRtc();
    hideAll();
    $('pvp-vs').classList.remove('hidden');
    const verdict = $('pvp-verdict');
    if (verdict) verdict.textContent = data.message || 'Round over';
    const you = data.you || {};
    const opp = data.opponent || {};
    if ($('pvp-you-score')) $('pvp-you-score').textContent = scoreText(you.score);
    if ($('pvp-opp-score')) $('pvp-opp-score').textContent = scoreText(opp.score);
    if ($('pvp-you-roast')) $('pvp-you-roast').textContent = roastDisplayText(you);
    if ($('pvp-opp-roast')) $('pvp-opp-roast').textContent = roastDisplayText(opp);
    refreshLiveStats();
  }

  function handleState(data) {
    if (!data || !data.ok) {
      showError((data && data.error && data.error.message) || 'Match error.');
      return;
    }
    state.matchId = data.match_id || state.matchId;
    state.role = data.role || state.role;
    if (data.status) state.matchStatus = data.status;

    if (data.status === 'waiting') {
      hideAll();
      $('pvp-queue').classList.remove('hidden');
      const qt = $('pvp-queue-text');
      if (qt) qt.textContent = data.message || 'Finding opponent...';
      if (!state.queueAnimTimer) startQueueAnim();
      return;
    }

    if (data.status === 'matched' || data.status === 'active') {
      const duelEl = $('pvp-duel');
      if (duelEl && !duelEl.classList.contains('hidden') && state.webrtcMatchId === state.matchId) {
        refreshDuelUi(data);
        return;
      }
      showDuel(data);
      return;
    }

    if (data.status === 'dueling') {
      if (data.you_ready) showJudging(data);
      else {
        const duelEl = $('pvp-duel');
        if (duelEl && !duelEl.classList.contains('hidden') && state.webrtcMatchId === state.matchId) {
          refreshDuelUi(data);
        } else {
          showDuel(data);
        }
      }
      if (!state.pollTimer) {
        state.pollTimer = setInterval(async function () {
          const st = await pvpStatus();
          if (st.status === 'complete' || st.status === 'expired') {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
            renderResults(st);
          } else {
            updateLiveScores(st);
            if (st.status === 'dueling' && st.you_ready) showJudging(st);
          }
        }, 2000);
      }
      return;
    }

    if (data.status === 'complete' || data.status === 'expired') {
      renderResults(data);
    }
  }

  async function quitMatch() {
    stopAll();
    stopLiveScoring();
    stopDuelPoll();
    teardownWebRtc();
    revokeBikeLastFrame();
    try { await pvpPost('leave'); } catch (e) { /* ignore */ }
    resetSession();
    hideAll();
    $('pvp-lobby').classList.remove('hidden');
    const st = $('pvp-status-text');
    if (st) st.textContent = 'Match quit. Enable camera to play again.';
    refreshLiveStats();
  }

  async function pingEvent() {
    try {
      const res = await fetch(ROAST_API + '?ping=1', { credentials: 'same-origin' });
      const data = await res.json();
      const meta = $('pvp-event-meta');
      if (data && data.event_end && meta) {
        meta.textContent = 'Match with a verified stranger, roast each other\'s builds live, and compare cred scores. Event ends ' + data.event_end + '.';
      }
      if (!data || !data.event_active) {
        hideAll();
        $('pvp-lobby').classList.remove('hidden');
        const status = $('pvp-status-text');
        if (status) status.textContent = 'This limited event is not active right now. Check back soon or shop parts at OnlyBikes.';
        const btn = $('pvp-enable-media');
        if (btn) btn.disabled = true;
        return false;
      }
      return true;
    } catch (e) {
      return true;
    }
  }

  function bindEvents() {
    var enableBtn = $('pvp-enable-media');
    if (enableBtn) enableBtn.addEventListener('click', enableMedia);
    var cancelBtn = $('pvp-cancel-btn');
    if (cancelBtn) cancelBtn.addEventListener('click', async function () {
      stopAll();
      await pvpPost('leave');
      resetSession();
      hideAll();
      $('pvp-lobby').classList.remove('hidden');
      refreshLiveStats();
    });
    var quitBtn = $('pvp-quit-btn');
    if (quitBtn) quitBtn.addEventListener('click', quitMatch);
    var quitTop = $('pvp-quit-top');
    if (quitTop) quitTop.addEventListener('click', quitMatch);
    var flipBtn = $('pvp-flip-cam');
    if (flipBtn) flipBtn.addEventListener('click', flipBikeCamera);
    var judgeNowBtn = $('pvp-judge-now');
    if (judgeNowBtn) judgeNowBtn.addEventListener('click', sendLiveFrame);
    var oppCam = $('pvp-cam-opp');
    if (oppCam) oppCam.addEventListener('click', function () {
      enableStrangerAudio(true);
    });
    var enableAudioBtn = $('pvp-enable-audio');
    if (enableAudioBtn) enableAudioBtn.addEventListener('click', function () {
      enableStrangerAudio(true);
    });
    var skipStepBtn = $('pvp-liveness-skip-step');
    if (skipStepBtn) skipStepBtn.addEventListener('click', onLivenessStuckClick);
    var skipLivenessBtn = $('pvp-skip-liveness');
    if (skipLivenessBtn && PVP_DEBUG) skipLivenessBtn.addEventListener('click', skipLivenessVerification);
    var rematchBtn = $('pvp-rematch');
    if (rematchBtn) rematchBtn.addEventListener('click', function () {
      resetSession();
      hideAll();
      $('pvp-lobby').classList.remove('hidden');
      enableMedia();
    });
    var retryBtn = $('pvp-error-retry');
    if (retryBtn) retryBtn.addEventListener('click', function () {
      resetSession();
      hideAll();
      $('pvp-lobby').classList.remove('hidden');
    });
  }

  document.addEventListener('DOMContentLoaded', async function () {
    window.RoastPvpBooted = true;
    window.RoastPvpVersion = PVP_BUILD;
    if (PVP_DEBUG) {
      window.RoastPvpDumpDebug = dumpWebRtcDebug;
      console.info('[Roast PvP] build ' + PVP_BUILD + ' (debug) — RoastPvpDumpDebug()');
      const debugWrap = $('pvp-debug-wrap');
      if (debugWrap) debugWrap.classList.remove('hidden');
      const skipBtn = $('pvp-skip-liveness');
      if (skipBtn) skipBtn.classList.remove('hidden');
    }
    getToken();
    bindEvents();
    hideAll();
    await loadPvpPublicConfig();
    applyCachedScoresToUi();
    const eventOk = await pingEvent();
    if (eventOk) {
      $('pvp-lobby').classList.remove('hidden');
      startLiveStatsPoll();
    }
  });

})();