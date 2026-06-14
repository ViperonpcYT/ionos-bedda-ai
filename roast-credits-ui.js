(function (global) {
  'use strict';

  var API = '/api/runtime-credits.php';
  var state = {
    ready: false,
    loggedIn: false,
    customerId: null,
    guestId: '',
    sessionId: '',
    balance: { pvp_credits: 0, solo_credits: 0 },
    costs: { solo: 1, pvp: 1 },
    ads: {
      waterfall: ['monetag', 'adsense', 'house'],
      min_view_solo_sec: 18,
      min_view_pvp_sec: 15,
      fill_timeout_ms: 4500,
      house_promos: []
    },
    pending_unlock: {},
    pendingUnlockToken: null
  };

  function el(id) {
    return document.getElementById(id);
  }

  function injectStyles() {
    if (document.getElementById('roast-credits-styles')) return;
    var s = document.createElement('style');
    s.id = 'roast-credits-styles';
    s.textContent = [
      '#roast-credits-banner{margin-bottom:1rem;padding:.75rem 1rem;border-radius:.5rem;border:1px solid rgba(63,63,70,.8);background:rgba(24,24,27,.8);font-size:.8rem;color:#d4d4d8}',
      '#roast-credits-balance{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem}',
      '.roast-credit-pill{padding:.35rem .75rem;border-radius:9999px;background:#18181b;border:1px solid #3f3f46;font-size:.75rem}',
      '.roast-credit-pill strong{color:#4ade80}',
      '.roast-ad-modal{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.85);padding:1rem}',
      '.roast-ad-modal.open{display:flex}',
      '.roast-ad-modal-card{max-width:28rem;width:100%;background:#18181b;border:1px solid #3f3f46;border-radius:.75rem;padding:1.25rem;position:relative;z-index:1}',
      '.roast-ad-provider-tag{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#71717a}',
      '.roast-ad-house{display:flex;gap:.75rem;align-items:center;text-align:left;padding:.5rem}',
      '.roast-ad-house img{width:56px;height:56px;object-fit:cover;border-radius:.5rem;flex-shrink:0}',
      '.roast-ad-timer{font-size:2rem;font-weight:800;color:#4ade80;text-align:center;margin:.5rem 0}',
      '.roast-ad-slot{min-height:120px;background:#09090b;border:1px dashed #3f3f46;border-radius:.5rem;display:flex;align-items:center;justify-content:center;color:#71717a;font-size:.8rem;margin:.75rem 0}',
      '.roast-ad-continue{width:100%;margin-top:.75rem;padding:.75rem;border-radius:.5rem;font-weight:700;background:#22c55e;color:#09090b;border:0}',
      '.roast-ad-continue:disabled{opacity:.45;cursor:not-allowed}'
    ].join('');
    document.head.appendChild(s);
  }

  function injectMarkup() {
    if (el('roast-credits-root')) return;

    var root = document.createElement('div');
    root.id = 'roast-credits-root';

    root.innerHTML = [
      '<div id="roast-credits-banner" class="hidden">',
      '  <span id="roast-credits-banner-text"></span>',
      '  <a href="account.html" class="text-green-300 underline ml-1">Sign in</a>',
      '</div>',
      '<div id="roast-credits-balance" class="hidden">',
      '  <span class="roast-credit-pill">PvP: <strong id="roast-bal-pvp">0</strong></span>',
      '  <span class="roast-credit-pill">Solo: <strong id="roast-bal-solo">0</strong></span>',
      '</div>',
      '<div id="roast-ad-modal-short" class="roast-ad-modal" role="dialog" aria-modal="true">',
      '  <div class="roast-ad-modal-card">',
      '    <p class="text-sm text-zinc-400">Watch the timer — supports free roast plays</p>',
      '    <div class="roast-ad-timer" id="roast-ad-timer-short">--</div>',
      '    <div class="roast-ad-slot" id="roast-ad-slot-short">Loading ad…</div>',
      '    <button type="button" class="roast-ad-continue" id="roast-ad-continue-short" disabled>Continue</button>',
      '  </div>',
      '</div>',
      '<div id="roast-ad-modal-long" class="roast-ad-modal" role="dialog" aria-modal="true">',
      '  <div class="roast-ad-modal-card">',
      '    <p class="text-sm text-zinc-400">Watch the timer — supports free roast plays</p>',
      '    <div class="roast-ad-timer" id="roast-ad-timer-long">--</div>',
      '    <div class="roast-ad-slot" id="roast-ad-slot-long">Loading ad…</div>',
      '    <button type="button" class="roast-ad-continue" id="roast-ad-continue-long" disabled>Continue</button>',
      '  </div>',
      '</div>'
    ].join('');

    document.body.appendChild(root);
  }

  function updateBalanceUI() {
    var balEl = el('roast-credits-balance');
    var banner = el('roast-credits-banner');
    if (!balEl || !banner) return;

    if (state.loggedIn) {
      balEl.classList.remove('hidden');
      banner.classList.add('hidden');
      if (el('roast-bal-pvp')) el('roast-bal-pvp').textContent = String(state.balance.pvp_credits || 0);
      if (el('roast-bal-solo')) el('roast-bal-solo').textContent = String(state.balance.solo_credits || 0);
    } else {
      balEl.classList.add('hidden');
      banner.classList.remove('hidden');
      if (el('roast-credits-banner-text')) {
        el('roast-credits-banner-text').textContent =
          'Guest: watch a short ad every play. Sign in for free starting credits and shop bonuses.';
      }
    }
  }

  function mountBanner(targetSelector) {
    var target = document.querySelector(targetSelector);
    var banner = el('roast-credits-banner');
    var balance = el('roast-credits-balance');
    if (!target || !banner) return;
    if (banner.parentNode !== target) {
      target.insertBefore(banner, target.firstChild);
    }
    if (balance && balance.parentNode !== target) {
      target.insertBefore(balance, banner.nextSibling);
    }
    updateBalanceUI();
  }

  function loadHouseAd(slotEl, scope) {
    if (!slotEl) return 'house';
    var promos = (state.ads && state.ads.house_promos) || [];
    var pick = promos.length ? promos[Math.floor(Math.random() * promos.length)] : null;
    if (!pick) {
      slotEl.innerHTML = '<p class="roast-ad-provider-tag mb-2">Featured on OnlyBikes</p><a href="products.html" class="text-green-300 underline">Shop parts</a> — thanks for supporting the roast event.';
    } else {
      slotEl.innerHTML = [
        '<p class="roast-ad-provider-tag mb-2">Featured on OnlyBikes</p>',
        '<a href="' + pick.href + '" class="roast-ad-house group">',
        '<img src="' + pick.image + '" alt="">',
        '<span class="text-sm text-zinc-200 group-hover:text-green-300 font-semibold leading-tight">' + pick.title + '</span>',
        '</a>'
      ].join('');
    }
    slotEl.dataset.filled = 'house';
    return 'house';
  }

  function waitMs(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function tryAdSense(slotEl, client, slot) {
    if (!slotEl || !client) return Promise.resolve(false);
    slotEl.innerHTML = '';
    var ins = document.createElement('ins');
    ins.className = 'adsbygoogle';
    ins.style.display = 'block';
    ins.style.minHeight = '100px';
    ins.setAttribute('data-ad-client', client);
    ins.setAttribute('data-ad-format', 'auto');
    ins.setAttribute('data-full-width-responsive', 'true');
    if (slot) {
      ins.setAttribute('data-ad-slot', slot);
    }
    slotEl.appendChild(ins);
    if (!global.adsbygoogle) {
      var scr = document.createElement('script');
      scr.async = true;
      scr.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent(client);
      scr.crossOrigin = 'anonymous';
      document.head.appendChild(scr);
    }
    return new Promise(function (resolve) {
      var done = false;
      var finish = function (ok) {
        if (done) return;
        done = true;
        if (ok) slotEl.dataset.filled = 'adsense';
        resolve(ok);
      };
      var timeout = setTimeout(function () { finish(false); }, state.ads.fill_timeout_ms || 4500);
      try {
        (global.adsbygoogle = global.adsbygoogle || []).push({});
      } catch (e) {
        clearTimeout(timeout);
        finish(false);
        return;
      }
      var check = setInterval(function () {
        var iframe = slotEl.querySelector('iframe');
        var insEl = slotEl.querySelector('ins.adsbygoogle');
        var h = insEl ? insEl.offsetHeight : 0;
        if (iframe || h > 50) {
          clearInterval(check);
          clearTimeout(timeout);
          finish(true);
        }
      }, 400);
    });
  }

  function loadMonetagScript(zoneId) {
    if (!zoneId) return false;
    global._monetagZones = global._monetagZones || {};
    if (global._monetagZones[zoneId]) return true;
    var scr = document.createElement('script');
    scr.dataset.zone = String(zoneId);
    scr.src = 'https://nap5k.com/tag.min.js';
    scr.async = true;
    document.body.appendChild(scr);
    global._monetagZones[zoneId] = true;
    return true;
  }

  async function fillAdSlotWaterfall(slotEl, scope) {
    var ads = state.ads || {};
    var chain = ads.waterfall || ['house'];
    var providerUsed = 'house';
    var monetagLoaded = false;

    if (ads.monetag_zone) {
      monetagLoaded = loadMonetagScript(ads.monetag_zone);
    }

    for (var i = 0; i < chain.length; i++) {
      var tier = chain[i];
      if (tier === 'monetag') {
        if (monetagLoaded) {
          providerUsed = 'monetag';
        }
        continue;
      }
      if (tier === 'adsense' && ads.adsense_client && ads.adsense_slot) {
        var ok = await tryAdSense(slotEl, ads.adsense_client, ads.adsense_slot);
        if (ok) {
          providerUsed = 'adsense';
          break;
        }
      } else if (tier === 'house') {
        providerUsed = loadHouseAd(slotEl, scope);
        break;
      }
    }

    if (!slotEl.dataset.filled) {
      providerUsed = loadHouseAd(slotEl, scope);
    }

    if (providerUsed === 'house' && monetagLoaded) {
      providerUsed = 'monetag';
    }

    return providerUsed;
  }

  function runAdTimerModal(scope, onContinue) {
    var isPvp = scope === 'pvp';
    var modalId = isPvp ? 'roast-ad-modal-short' : 'roast-ad-modal-long';
    var timerId = isPvp ? 'roast-ad-timer-short' : 'roast-ad-timer-long';
    var slotId = isPvp ? 'roast-ad-slot-short' : 'roast-ad-slot-long';
    var btnId = isPvp ? 'roast-ad-continue-short' : 'roast-ad-continue-long';
    var minSec = isPvp ? (state.ads.min_view_pvp_sec || 15) : (state.ads.min_view_solo_sec || 18);

    var modal = el(modalId);
    var timerEl = el(timerId);
    var slotEl = el(slotId);
    var btn = el(btnId);
    if (!modal || !timerEl || !btn) {
      return Promise.reject(new Error('Ad modal missing'));
    }

    var viewStartedAt = Date.now();
    var adProvider = 'house';

    btn.disabled = true;
    modal.classList.add('open');

    var blockEscape = function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
      }
    };
    modal.addEventListener('keydown', blockEscape);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        e.preventDefault();
        e.stopPropagation();
      }
    });

    fillAdSlotWaterfall(slotEl, scope).then(function (provider) {
      adProvider = provider || 'house';
    });

    var remaining = minSec;
    timerEl.textContent = String(remaining);

    return new Promise(function (resolve, reject) {
      var interval = setInterval(function () {
        remaining -= 1;
        timerEl.textContent = String(Math.max(0, remaining));
        if (remaining <= 0) {
          clearInterval(interval);
          btn.disabled = false;
        }
      }, 1000);

      btn.onclick = async function () {
        if (btn.disabled) return;
        modal.classList.remove('open');
        modal.removeEventListener('keydown', blockEscape);
        clearInterval(interval);
        try {
          var result = await onContinue(minSec, viewStartedAt, adProvider);
          resolve(result);
        } catch (err) {
          reject(err);
        }
      };
    });
  }

  function showAdModal(scope) {
    return runAdTimerModal(scope, async function (minSec, viewStartedAt, adProvider) {
      var res = await fetch(API + '?action=ad_unlock', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          scope: scope,
          guest_id: state.guestId,
          session_id: state.sessionId,
          min_view_sec: minSec,
          view_started_at: viewStartedAt,
          ad_provider: adProvider
        })
      });
      var data = await res.json();
      if (!data.ok) {
        throw new Error(data.error || 'Ad unlock failed');
      }
      state.pendingUnlockToken = data.ad_unlock_token;
      return data.ad_unlock_token;
    });
  }

  function showBonusAdModal() {
    return runAdTimerModal('solo', async function (minSec, viewStartedAt, adProvider) {
      var res = await fetch(API + '?action=ad_claim', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          session_id: state.sessionId,
          min_view_sec: minSec,
          view_started_at: viewStartedAt,
          ad_provider: adProvider
        })
      });
      var data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Ad bonus failed');
      if (data.balance) state.balance = data.balance;
      updateBalanceUI();
      state.pendingUnlockToken = null;
      return data;
    });
  }

  function hasCredits(scope) {
    if (!state.loggedIn) return false;
    if (scope === 'pvp') return (state.balance.pvp_credits || 0) >= (state.costs.pvp || 1);
    return (state.balance.solo_credits || 0) >= (state.costs.solo || 1);
  }

  function pendingUnlockToken(scope) {
    var pending = state.pending_unlock || {};
    var row = pending[scope];
    return row && row.token ? row.token : null;
  }

  function applySessionMeta(data) {
    if (data.pending_unlock) {
      state.pending_unlock = data.pending_unlock;
      if (state.pending_unlock.pvp && state.pending_unlock.pvp.token) {
        state.pendingUnlockToken = state.pending_unlock.pvp.token;
      } else if (state.pending_unlock.solo && state.pending_unlock.solo.token) {
        state.pendingUnlockToken = state.pending_unlock.solo.token;
      }
    }
  }

  async function gate(scope) {
    await init(true);
    if (hasCredits(scope)) {
      state.pendingUnlockToken = null;
      return { method: 'balance', token: null };
    }

    var existing = pendingUnlockToken(scope) || state.pendingUnlockToken;
    if (existing) {
      state.pendingUnlockToken = existing;
      return { method: 'ad_token', token: existing };
    }

    if (state.loggedIn) {
      var watch = global.confirm(
        'Not enough ' + (scope === 'pvp' ? 'PvP' : 'solo') + ' credits. Watch an ad for bonus credits? (Cancel to shop instead)'
      );
      if (!watch) {
        global.location.href = 'products.html';
        throw new Error('Insufficient credits');
      }
      await showBonusAdModal();
      if (hasCredits(scope)) {
        return { method: 'balance', token: null };
      }
      throw new Error('Still not enough credits after ad bonus');
    }

    var token = await showAdModal(scope);
    return { method: 'ad_token', token: token };
  }

  async function init(forceRefresh) {
    if (state.ready && !forceRefresh) return state;
    injectStyles();
    injectMarkup();

    var res = await fetch(API + '?action=session', { credentials: 'include' });
    var data = await res.json();
    if (data.ok) {
      state.guestId = data.guest_id || '';
      state.sessionId = data.session_id || '';
      state.loggedIn = !!data.logged_in;
      state.customerId = data.customer_id || null;
      if (data.balance) state.balance = data.balance;
      if (data.ads) state.ads = data.ads;
      if (data.costs) state.costs = data.costs;
      applySessionMeta(data);
    }
    state.ready = true;
    updateBalanceUI();
    return state;
  }

  async function refreshBalance() {
    if (!state.loggedIn) return state.balance;
    var res = await fetch(API + '?action=balance', { credentials: 'include' });
    var data = await res.json();
    if (data.ok && data.balance) {
      state.balance = data.balance;
      updateBalanceUI();
    }
    return state.balance;
  }

  function getUnlockToken() {
    return state.pendingUnlockToken;
  }

  function clearUnlockToken() {
    state.pendingUnlockToken = null;
  }

  global.RoastCredits = {
    init: init,
    gate: gate,
    refreshBalance: refreshBalance,
    mountBanner: mountBanner,
    getUnlockToken: getUnlockToken,
    clearUnlockToken: clearUnlockToken,
    getState: function () { return state; }
  };
})(window);
