/**
 * Bedda AI Chat Widget
 * --------------------------------
 * Sleek floating chat assistant that lives on most pages of bedda.ca.
 * - Auto-routes user messages to the correct backend intent (faq, stock).
 * - Tracks every AI conversation to the analytics pipeline (ai_query events).
 * - Shows a permanent "AI-generated — verify with us" disclaimer below answers.
 *
 * Mount: add `<script src="/bedda-ai.js" defer></script>` to any page that should host the widget.
 * Opt-out: add `<body data-bedda-ai="off">` to skip a page (e.g., checkout-success).
 */
(function () {
  'use strict';

  // Don't load twice
  if (window.__BeddaAILoaded) return;
  window.__BeddaAILoaded = true;

  // Allow pages to opt out (set <body data-bedda-ai="off">)
  const optOut = () => {
    const v = document.body && document.body.getAttribute('data-bedda-ai');
    return v === 'off' || v === 'false' || v === '0';
  };

  const ENDPOINT = '/api/ai-engine.php?ajax=1';
  const LOG_ENDPOINT = '/api/log-event.php';

  // ============================================================
  // Tracking helpers — piggybacks BeddaLogger session if present
  // ============================================================
  function getSessionInfo() {
    const userId = localStorage.getItem('bedda_user_id') || ('user_' + Date.now() + '_anon');
    const sessionId = sessionStorage.getItem('bedda_session_id') || ('session_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9));
    if (!sessionStorage.getItem('bedda_session_id')) sessionStorage.setItem('bedda_session_id', sessionId);
    return { userId, sessionId };
  }

  function track(eventType, data) {
    try {
      const { userId, sessionId } = getSessionInfo();
      navigator.sendBeacon
        ? navigator.sendBeacon(LOG_ENDPOINT, new Blob([JSON.stringify({
            type: eventType,
            userId,
            sessionId,
            page: location.pathname,
            timestamp: Math.floor(Date.now() / 1000),
            data: data || {},
          })], { type: 'application/json' }))
        : fetch(LOG_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              type: eventType,
              userId,
              sessionId,
              page: location.pathname,
              timestamp: Math.floor(Date.now() / 1000),
              data: data || {},
            }),
            keepalive: true,
          }).catch(() => {});
    } catch (_) { /* swallow */ }
  }

  // ============================================================
  // Intent detector (client-side, lightweight)
  // ============================================================
  function detectIntent(message) {
    const m = message.toLowerCase();
    if (/\b(in stock|stock|available|sold out|out of stock|left|inventory)\b/.test(m)) return 'stock';
    return 'faq';
  }


  /** Offline FAQ when server DB/config is unavailable */
  function faqFallback(prompt, intent) {
    const m = (prompt || '').toLowerCase();
    if (intent === 'stock') {
      return "I can't check live inventory right now. Email orders@bedda.ca or use the product pages — popular bars include Uni, He-Man, and She-Ra. Local pickup in Vaughan is also available.";
    }
    if (/\btallow\b/.test(m)) {
      return "Bedda uses grass-fed beef tallow in our cold-process soap. Tallow is rich in skin-compatible fats and works well for sensitive or dry skin. See our Ingredients page for full details.";
    }
    if (/\bship|deliver|mail\b/.test(m)) {
      return "We ship across Canada. Rates show at checkout based on your address. Free local pickup near Vaughan/Mississauga — see Contact for pickup details.";
    }
    if (/\beczema|sensitive|rash\b/.test(m)) {
      return "Many customers with sensitive skin love our Plain Jane and sensitive-skin bundles. Patch-test any new product. We're not medical providers — see a doctor for diagnosed conditions.";
    }
    if (/\bjosie|who|story|about\b/.test(m)) {
      return "Bedda is handmade by Josie Chaves in Mississauga, Canada — small-batch tallow soap and balms for families who want simple, transparent skincare.";
    }
    if (/\bprice|cost|how much\b/.test(m)) {
      return "Most bars are around $5–6 CAD. Curated bundles save about 20%. Exact prices are on each product page and in your cart at checkout.";
    }
    if (/\breward|points\b/.test(m)) {
      return "Bedda Rewards: earn 1 point per $1 spent. Each point is worth $0.05 toward future orders. Sign in on the account icon to see your balance.";
    }
    return "I'm Bedda's assistant. For orders, ingredients, or custom requests, email orders@bedda.ca or everythingbedda@gmail.com — Josie replies personally!";
  }

  // ============================================================
  // DOM creation
  // ============================================================
  function buildWidget() {
    if (document.getElementById('bedda-ai-root')) return;

    const root = document.createElement('div');
    root.id = 'bedda-ai-root';
    root.innerHTML = `
      <style>
        #bedda-ai-root, #bedda-ai-root * { box-sizing: border-box; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .bedda-ai-btn {
          position: fixed; bottom: 24px; right: 24px; z-index: 9998;
          width: auto; min-width: 60px; height: 60px; padding: 0 22px 0 18px;
          background: linear-gradient(135deg, #7E6D58 0%, #B5A183 100%);
          color: white; border: none; border-radius: 999px;
          box-shadow: 0 10px 24px rgba(181, 161, 131, 0.35), 0 2px 6px rgba(0,0,0,0.12);
          cursor: pointer; display: flex; align-items: center; gap: 10px;
          font-weight: 600; font-size: 15px; transition: all 0.25s ease;
        }
        .bedda-ai-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(181, 161, 131, 0.45); }
        .bedda-ai-btn svg { width: 22px; height: 22px; flex-shrink: 0; }
        .bedda-ai-btn .badge {
          background: white; color: #B5A183; font-size: 10px; font-weight: 800;
          padding: 2px 7px; border-radius: 999px; letter-spacing: 0.5px; text-transform: uppercase;
        }
        .bedda-ai-panel {
          position: fixed; bottom: 100px; right: 24px; z-index: 9999;
          width: min(380px, calc(100vw - 32px)); height: min(560px, calc(100vh - 140px));
          background: #F5F3EF; border-radius: 16px; overflow: hidden;
          box-shadow: 0 24px 60px rgba(0,0,0,0.18), 0 4px 12px rgba(0,0,0,0.08);
          display: none; flex-direction: column;
          border: 1px solid rgba(0,0,0,0.06);
          animation: bedda-ai-pop 0.22s cubic-bezier(0.2, 0.9, 0.3, 1.2);
        }
        @keyframes bedda-ai-pop {
          from { opacity: 0; transform: translateY(12px) scale(0.96); }
          to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .bedda-ai-panel.open { display: flex; }
        .bedda-ai-head {
          background: linear-gradient(135deg, #7E6D58 0%, #B5A183 100%);
          color: white; padding: 14px 16px; display: flex; align-items: center; gap: 10px;
        }
        .bedda-ai-head .avatar {
          width: 36px; height: 36px; border-radius: 50%;
          background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;
        }
        .bedda-ai-head .avatar svg { width: 20px; height: 20px; }
        .bedda-ai-head .title { font-weight: 700; font-size: 15px; }
        .bedda-ai-head .subtitle { font-size: 11px; opacity: 0.85; }
        .bedda-ai-head .close { margin-left: auto; background: transparent; border: 0; color: white; cursor: pointer; padding: 4px; opacity: 0.85; }
        .bedda-ai-head .close:hover { opacity: 1; }
        .bedda-ai-messages {
          flex: 1; overflow-y: auto; padding: 16px; background: #F5F3EF;
          display: flex; flex-direction: column; gap: 10px;
        }
        .bedda-ai-msg {
          max-width: 85%; padding: 10px 14px; border-radius: 14px; font-size: 14px; line-height: 1.45;
          word-wrap: break-word;
        }
        .bedda-ai-msg.user {
          align-self: flex-end; background: #2C2C2C; color: white; border-bottom-right-radius: 4px;
        }
        .bedda-ai-msg.bot {
          align-self: flex-start; background: white; color: #2C2C2C;
          border: 1px solid #E8E4DC; border-bottom-left-radius: 4px;
          box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .bedda-ai-msg.bot.typing { font-style: italic; color: #7E6D58; }
        .bedda-ai-disclaimer {
          font-size: 10px; color: #7E6D58; padding: 6px 14px 0 2px; align-self: flex-start; max-width: 85%;
          letter-spacing: 0.1px;
        }
        .bedda-ai-disclaimer a { color: #B5A183; text-decoration: underline; }
        .bedda-ai-suggestions {
          display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 16px 0;
        }
        .bedda-ai-chip {
          background: white; border: 1px solid #E8E4DC; color: #5A4D3F;
          padding: 6px 12px; border-radius: 999px; font-size: 12px; cursor: pointer;
          transition: all 0.15s ease;
        }
        .bedda-ai-chip:hover { background: #F5F3EF; border-color: #D9CDBF; color: #5A4D3F; }
        .bedda-ai-input-wrap {
          border-top: 1px solid #E8E4DC; padding: 10px 12px; background: white; display: flex; gap: 8px;
        }
        .bedda-ai-input {
          flex: 1; border: 1px solid #E8E4DC; border-radius: 999px;
          padding: 10px 16px; font-size: 14px; outline: none; transition: border-color 0.15s;
          background: #F5F3EF;
        }
        .bedda-ai-input:focus { border-color: #9C8C73; background: white; }
        .bedda-ai-send {
          background: linear-gradient(135deg, #7E6D58 0%, #B5A183 100%);
          border: 0; color: white; border-radius: 999px;
          padding: 0 16px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px;
          transition: transform 0.1s;
        }
        .bedda-ai-send:active { transform: scale(0.95); }
        .bedda-ai-send:disabled { opacity: 0.5; cursor: not-allowed; }
        .bedda-ai-footnote {
          font-size: 10px; color: #7E6D58; text-align: center; padding: 6px 12px; background: white; border-top: 1px solid #E8E4DC;
        }
        .bedda-ai-footnote a { color: #7E6D58; text-decoration: none; }
        .bedda-ai-footnote a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
          .bedda-ai-btn { bottom: 16px; right: 16px; padding: 0 18px 0 14px; font-size: 13px; height: 54px; min-width: 54px; }
          .bedda-ai-panel { bottom: 84px; right: 8px; left: 8px; width: auto; height: calc(100vh - 110px); }
        }
      </style>
      <button class="bedda-ai-btn" id="bedda-ai-toggle" aria-label="Open Bedda AI Assistant">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 14H7v-2h4v2zm6-4H7v-2h10v2zm0-4H7V6h10v2z" fill="currentColor"/>
        </svg>
        <span>Ask Bedda</span>
        <span class="badge">AI</span>
      </button>
      <div class="bedda-ai-panel" id="bedda-ai-panel" role="dialog" aria-label="Bedda AI Assistant">
        <div class="bedda-ai-head">
          <div class="avatar">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 14H7v-2h4v2zm6-4H7v-2h10v2zm0-4H7V6h10v2z" fill="currentColor"/>
            </svg>
          </div>
          <div>
            <div class="title">Bedda AI Assistant</div>
            <div class="subtitle">Your skincare guide — products, stock, shipping &amp; more</div>
          </div>
          <button class="close" id="bedda-ai-close" aria-label="Close chat">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
          </button>
        </div>
        <div class="bedda-ai-messages" id="bedda-ai-messages"></div>
        <div class="bedda-ai-suggestions" id="bedda-ai-suggestions"></div>
        <div class="bedda-ai-input-wrap">
          <input class="bedda-ai-input" id="bedda-ai-input" type="text" placeholder="Ask anything about Bedda..." maxlength="500" autocomplete="off" />
          <button class="bedda-ai-send" id="bedda-ai-send" aria-label="Send message">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M2 21l21-9L2 3v7l15 2-15 2v7z"/></svg>
          </button>
        </div>
        <div class="bedda-ai-footnote">
          AI-generated responses. Verify important info with us at <a href="mailto:orders@bedda.ca">orders@bedda.ca</a>.
        </div>
      </div>
    `;
    document.body.appendChild(root);
  }

  // ============================================================
  // Behavior
  // ============================================================
  let conversationCount = 0;
  let messageHistory = []; // Store conversation context

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function appendMessage(text, who, opts) {
    const wrap = document.getElementById('bedda-ai-messages');
    const div = document.createElement('div');
    div.className = 'bedda-ai-msg ' + who + (opts && opts.typing ? ' typing' : '');
    div.innerHTML = escapeHtml(text);
    wrap.appendChild(div);
    if (who === 'bot' && !(opts && opts.typing)) {
      const disc = document.createElement('div');
      disc.className = 'bedda-ai-disclaimer';
      disc.innerHTML = 'AI-generated — check crucial info with us.';
      wrap.appendChild(disc);
    }
    wrap.scrollTop = wrap.scrollHeight;
    return div;
  }

  function setSuggestions(list) {
    const wrap = document.getElementById('bedda-ai-suggestions');
    if (!wrap) return;
    wrap.innerHTML = '';
    list.forEach(label => {
      const chip = document.createElement('button');
      chip.className = 'bedda-ai-chip';
      chip.textContent = label;
      chip.onclick = () => { sendMessage(label); };
      wrap.appendChild(chip);
    });
  }

  async function sendMessage(text) {
    text = (text || '').trim();
    if (!text) return;
    const input = document.getElementById('bedda-ai-input');
    const send  = document.getElementById('bedda-ai-send');
    input.value = '';
    send.disabled = true;

    appendMessage(text, 'user');
    setSuggestions([]);
    const typingNode = appendMessage('Thinking...', 'bot', { typing: true });

    const intent = detectIntent(text);
    conversationCount++;

    const startMs = performance.now();
    let reply = '';
    let ok = false;
    let payload = null;

    try {
      // Send up to the last 6 prior messages (3 turns) as context.
      // The current user message is sent separately as `prompt`.
      const historyToSend = messageHistory.slice(-6);

      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ intent, prompt: text, history: historyToSend }),
      });
      payload = await res.json();
      ok = !!payload.success;
      reply = payload.text || '';
      if (!ok || !reply) {
        const dbDown = payload && payload.message && /db config/i.test(payload.message);
        if (dbDown || !ok) {
          reply = faqFallback(text, intent);
          ok = true;
        } else {
          reply = "Sorry, I couldn't process that. Please email orders@bedda.ca and we'll help!";
        }
      }
    } catch (e) {
      reply = "Connection hiccup — please try again or email orders@bedda.ca.";
    }
    const elapsed = Math.round(performance.now() - startMs);

    // Replace typing bubble with real reply
    typingNode.remove();
    // Strip the disclaimer-clone the typing call inserted (last child)
    const msgs = document.getElementById('bedda-ai-messages');
    if (msgs.lastChild && msgs.lastChild.className === 'bedda-ai-disclaimer') msgs.lastChild.remove();
    appendMessage(reply, 'bot');
    // Add this turn to history (and keep last 6 messages / 3 turns)
    messageHistory.push({ role: 'user', content: text });
    messageHistory.push({ role: 'assistant', content: reply });
    if (messageHistory.length > 6) messageHistory = messageHistory.slice(-6);
    send.disabled = false;
    input.focus();

    track('ai_query', {
      intent,
      query: text.slice(0, 240),
      replyLen: reply.length,
      ok,
      elapsed,
      page: location.pathname,
      conversation_turn: conversationCount,
      curated: payload && payload.data && payload.data.curated ? true : false,
    });

    // Refresh contextual suggestions
    refreshSuggestions(text);
  }

  function refreshSuggestions(lastMessage) {
    const path = location.pathname;
    const base = ['What is tallow soap?', 'Do you ship to my city?', 'What is in stock?'];
    if (path.includes('products')) {
      setSuggestions(['Is Uni in stock?', 'Best soap for sensitive skin?', 'What\'s your bestseller?']);
    } else if (path.includes('ingredients')) {
      setSuggestions(['Why beef tallow?', 'Is it safe for eczema?', 'What essential oils do you use?']);
    } else if (path.includes('about')) {
      setSuggestions(['Who is Josie?', 'Where are you based?', 'How do you make your soap?']);
    } else if (path.includes('contact')) {
      setSuggestions(['How long does shipping take?', 'Can I pick up locally?', 'How do I order?']);
    } else {
      setSuggestions(base);
    }
  }

  function open() {
    const panel = document.getElementById('bedda-ai-panel');
    panel.classList.add('open');
    const msgs = document.getElementById('bedda-ai-messages');
    if (!msgs.children.length) {
      appendMessage("Hi! I'm Bedda's AI assistant. Ask me about products, ingredients, stock, shipping — anything Bedda!", 'bot');
      refreshSuggestions('');
    }
    document.getElementById('bedda-ai-input').focus();
    track('ai_widget_open', { page: location.pathname });
  }

  function close() {
    document.getElementById('bedda-ai-panel').classList.remove('open');
    track('ai_widget_close', { page: location.pathname, turns: conversationCount });
  }

  function wire() {
    document.getElementById('bedda-ai-toggle').onclick = open;
    document.getElementById('bedda-ai-close').onclick = close;
    document.getElementById('bedda-ai-send').onclick = () => sendMessage(document.getElementById('bedda-ai-input').value);
    document.getElementById('bedda-ai-input').addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); sendMessage(e.target.value); }
    });
  }

  // ============================================================
  // Init
  // ============================================================
  function init() {
    if (optOut()) return;
    buildWidget();
    wire();
    track('ai_widget_loaded', { page: location.pathname });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
