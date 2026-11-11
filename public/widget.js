/*!
 * Chatbot SaaS website widget.
 *
 * Install: <script async src="https://YOUR-APP/widget.js" data-bot="BOT_PUBLIC_KEY"></script>
 *
 * Plain JavaScript, no dependencies. Everything is drawn inside a Shadow DOM
 * so the host website's CSS cannot break the widget and the widget's CSS
 * cannot leak into the website.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;

  var botKey = script.getAttribute('data-bot');
  if (!botKey) {
    console.warn('[chat widget] Missing data-bot attribute on the script tag.');
    return;
  }

  // One widget per bot per page, even if the script tag is pasted twice.
  window.__chatWidgetLoaded = window.__chatWidgetLoaded || {};
  if (window.__chatWidgetLoaded[botKey]) return;
  window.__chatWidgetLoaded[botKey] = true;

  var apiBase = new URL(script.src, window.location.href).origin + '/api/widget/' + encodeURIComponent(botKey);

  // ---------------------------------------------------------------- storage
  // Browser storage can be blocked (private mode, strict settings). Fall back
  // to memory so the chat still works for the current page view.
  var memory = {};

  function read(store, key) {
    try { return window[store].getItem(key); } catch (e) { return memory[key] || null; }
  }

  function write(store, key, value) {
    try { window[store].setItem(key, value); } catch (e) { memory[key] = value; }
  }

  function randomId() {
    var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    var out = 'v_';
    var bytes = new Uint8Array(24);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    for (var i = 0; i < bytes.length; i++) out += chars[bytes[i] % chars.length];
    return out;
  }

  var visitorKey = 'chat_widget_visitor_' + botKey;
  var conversationKey = 'chat_widget_conversation_' + botKey;

  var visitorId = read('localStorage', visitorKey);
  if (!visitorId) {
    visitorId = randomId();
    write('localStorage', visitorKey, visitorId);
  }

  var conversationId = read('sessionStorage', conversationKey);

  function timezone() {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || null; } catch (e) { return null; }
  }

  // ---------------------------------------------------------------- helpers
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (name) {
      if (name === 'text') node.textContent = attrs[name];
      else node.setAttribute(name, attrs[name]);
    });
    (children || []).forEach(function (child) { node.appendChild(child); });
    return node;
  }

  function safeColor(value) {
    return /^#[0-9a-fA-F]{6}$/.test(value || '') ? value : '#2563eb';
  }

  // White or near-black text, whichever is easier to read on the brand colour.
  function textOn(hex) {
    var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) > 160 ? '#111827' : '#ffffff';
  }

  // Message text is never inserted as HTML. Links are turned into real <a>
  // elements one by one; everything else stays a text node.
  function fillText(target, text) {
    target.textContent = '';
    var pattern = /(https?:\/\/[^\s<>"')\]]+)/g;
    var last = 0, match;
    while ((match = pattern.exec(text)) !== null) {
      if (match.index > last) target.appendChild(document.createTextNode(text.slice(last, match.index)));
      var link = el('a', { href: match[0], target: '_blank', rel: 'noopener noreferrer nofollow', text: match[0] });
      target.appendChild(link);
      last = match.index + match[0].length;
    }
    if (last < text.length) target.appendChild(document.createTextNode(text.slice(last)));
  }

  var ICON_CHAT = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>';
  var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  var ICON_SEND = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

  var CSS = [
    ':host{all:initial}',
    '*{box-sizing:border-box}',
    '.wrap{position:fixed;bottom:20px;z-index:2147483000;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans",Arial,sans-serif;font-size:15px;line-height:1.45;color:#111827}',
    '.wrap.right{right:20px}.wrap.left{left:20px}',
    '.launcher{width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:var(--brand);color:var(--on-brand);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(0,0,0,.25)}',
    '.launcher:focus-visible,.send:focus-visible,.close:focus-visible{outline:3px solid #111827;outline-offset:2px}',
    '.panel{position:absolute;bottom:72px;width:380px;max-width:calc(100vw - 40px);height:560px;max-height:calc(100vh - 110px);background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.28);display:none;flex-direction:column;overflow:hidden}',
    '.right .panel{right:0}.left .panel{left:0}',
    '.open .panel{display:flex}',
    '.head{background:var(--brand);color:var(--on-brand);padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}',
    '.title{font-weight:600;font-size:16px;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.close{background:transparent;border:0;color:inherit;cursor:pointer;padding:4px;border-radius:8px;display:flex}',
    '.list{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#f9fafb}',
    '.msg{max-width:85%;padding:10px 13px;border-radius:14px;white-space:pre-wrap;overflow-wrap:anywhere}',
    '.msg.bot{align-self:flex-start;background:#fff;border:1px solid #e5e7eb;border-bottom-left-radius:4px}',
    '.msg.user{align-self:flex-end;background:var(--brand);color:var(--on-brand);border-bottom-right-radius:4px}',
    '.msg.error{align-self:flex-start;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}',
    '.msg a{color:inherit;text-decoration:underline}',
    '.dots{display:inline-flex;gap:4px;align-items:center;height:1.45em}',
    '.dots i{width:6px;height:6px;border-radius:50%;background:#9ca3af;animation:blink 1.2s infinite ease-in-out}',
    '.dots i:nth-child(2){animation-delay:.2s}.dots i:nth-child(3){animation-delay:.4s}',
    '@keyframes blink{0%,80%,100%{opacity:.3}40%{opacity:1}}',
    '.form{display:flex;gap:8px;padding:12px;border-top:1px solid #e5e7eb;background:#fff;align-items:flex-end}',
    '.input{flex:1;resize:none;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px;font:inherit;color:inherit;max-height:120px;min-height:42px;background:#fff}',
    '.input:focus{outline:2px solid var(--brand);outline-offset:0;border-color:transparent}',
    '.send{width:42px;height:42px;flex:none;border-radius:12px;border:0;cursor:pointer;background:var(--brand);color:var(--on-brand);display:flex;align-items:center;justify-content:center}',
    '.send:disabled{opacity:.5;cursor:default}',
    '.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
    '@media (max-width:480px){.wrap{bottom:16px}.wrap.right{right:16px}.wrap.left{left:16px}.open .panel{position:fixed;inset:0;width:100%;max-width:none;height:100%;max-height:none;border-radius:0}.open .launcher{display:none}}',
    '@media (prefers-reduced-motion:reduce){.dots i{animation:none;opacity:.7}}'
  ].join('');

  // ---------------------------------------------------------------- build UI
  function build(config) {
    var brand = safeColor(config.color);
    var side = config.position === 'left' ? 'left' : 'right';
    var name = config.name || 'Chat';

    var host = el('div', { 'data-chat-widget': botKey });
    var root = host.attachShadow({ mode: 'open' });

    var style = el('style', { text: CSS });

    var launcher = el('button', { class: 'launcher', type: 'button', 'aria-label': 'Open chat', 'aria-expanded': 'false' });
    launcher.innerHTML = ICON_CHAT;

    var close = el('button', { class: 'close', type: 'button', 'aria-label': 'Close chat' });
    close.innerHTML = ICON_CLOSE;

    var list = el('div', { class: 'list', role: 'log', 'aria-live': 'polite', 'aria-label': 'Chat messages' });

    var input = el('textarea', { class: 'input', rows: '1', placeholder: 'Type your message…', 'aria-label': 'Your message', maxlength: '2000' });
    var send = el('button', { class: 'send', type: 'submit', 'aria-label': 'Send message' });
    send.innerHTML = ICON_SEND;
    var form = el('form', { class: 'form' }, [input, send]);

    var panel = el('div', { class: 'panel', role: 'dialog', 'aria-label': name }, [
      el('div', { class: 'head' }, [el('p', { class: 'title', text: name }), close]),
      list,
      form
    ]);

    var wrap = el('div', { class: 'wrap ' + side }, [panel, launcher]);
    wrap.style.setProperty('--brand', brand);
    wrap.style.setProperty('--on-brand', textOn(brand));

    root.appendChild(style);
    root.appendChild(wrap);
    document.body.appendChild(host);

    var started = false;
    var sending = false;

    function addMessage(kind, text) {
      var bubble = el('div', { class: 'msg ' + kind, dir: 'auto' });
      fillText(bubble, text);
      list.appendChild(bubble);
      list.scrollTop = list.scrollHeight;
      return bubble;
    }

    function setOpen(open) {
      wrap.classList.toggle('open', open);
      launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
      launcher.setAttribute('aria-label', open ? 'Close chat' : 'Open chat');
      launcher.innerHTML = open ? ICON_CLOSE : ICON_CHAT;

      if (open) {
        if (!started) { started = true; start(); }
        input.focus();
      } else {
        launcher.focus();
      }
    }

    // First open: bring back this visitor's conversation, or show the greeting.
    function start() {
      if (!conversationId) {
        if (config.greeting) addMessage('bot', config.greeting);
        return;
      }

      fetch(apiBase + '/conversations/' + encodeURIComponent(conversationId) + '/messages?visitor_id=' + encodeURIComponent(visitorId), { headers: { Accept: 'application/json' } })
        .then(function (response) { return response.ok ? response.json() : { messages: [] }; })
        .then(function (data) {
          var earlier = data.messages || [];
          if (config.greeting) addMessage('bot', config.greeting);
          earlier.forEach(function (message) { addMessage(message.role === 'user' ? 'user' : 'bot', message.content); });
        })
        .catch(function () { if (config.greeting) addMessage('bot', config.greeting); });
    }

    function setSending(value) {
      sending = value;
      send.disabled = value;
    }

    function submit() {
      var text = input.value.trim();
      if (!text || sending) return;

      input.value = '';
      input.style.height = 'auto';
      addMessage('user', text);

      var bubble = el('div', { class: 'msg bot', dir: 'auto' });
      bubble.innerHTML = '<span class="dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="sr">Typing…</span>';
      list.appendChild(bubble);
      list.scrollTop = list.scrollHeight;

      setSending(true);

      var reply = '';

      function fail(message) {
        bubble.className = 'msg error';
        fillText(bubble, message || 'Sorry, something went wrong. Please try again.');
      }

      function handle(event, data) {
        if (event === 'meta' && data.conversation_id) {
          conversationId = data.conversation_id;
          write('sessionStorage', conversationKey, conversationId);
        } else if (event === 'delta') {
          reply += data.text || '';
          fillText(bubble, reply);
          list.scrollTop = list.scrollHeight;
        } else if (event === 'error') {
          fail(data.message);
        }
      }

      fetch(apiBase + '/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
        body: JSON.stringify({
          message: text,
          visitor_id: visitorId,
          conversation_id: conversationId || null,
          page_url: window.location.href.slice(0, 2000),
          timezone: timezone()
        })
      }).then(function (response) {
        if (!response.ok) {
          return response.json().catch(function () { return {}; }).then(function (body) { fail(body.message); });
        }

        // Fallback for very old browsers without streaming: read it in one go.
        if (!response.body || !response.body.getReader) {
          return response.text().then(function (all) { parse(all + '\n\n'); });
        }

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function parse(chunk) {
          buffer += chunk;
          var blocks = buffer.split('\n\n');
          buffer = blocks.pop();
          blocks.forEach(function (block) {
            var event = (block.match(/^event: (.+)$/m) || [])[1];
            var data = (block.match(/^data: (.+)$/m) || [])[1];
            if (!event || !data) return;
            try { handle(event, JSON.parse(data)); } catch (e) { /* skip a broken line */ }
          });
        }

        function pump() {
          return reader.read().then(function (result) {
            if (result.done) { parse('\n\n'); return; }
            parse(decoder.decode(result.value, { stream: true }));
            return pump();
          });
        }

        return pump();
      }).catch(function () {
        fail();
      }).then(function () {
        if (bubble.className === 'msg bot' && !reply) fail();
        setSending(false);
        input.focus();
      });
    }

    launcher.addEventListener('click', function () { setOpen(!wrap.classList.contains('open')); });
    close.addEventListener('click', function () { setOpen(false); });
    form.addEventListener('submit', function (event) { event.preventDefault(); submit(); });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); submit(); }
    });

    input.addEventListener('input', function () {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    wrap.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && wrap.classList.contains('open')) setOpen(false);
    });
  }

  // ---------------------------------------------------------------- start
  function init() {
    fetch(apiBase + '/config', { headers: { Accept: 'application/json' } })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (config) { if (config && config.active) build(config); })
      .catch(function () { /* the site must keep working even if the widget cannot load */ });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
