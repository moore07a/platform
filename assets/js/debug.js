(function () {
  const params = new URLSearchParams(window.location.search);
  const localPref = localStorage.getItem('app-debug') === '1';
  const debugEnabled = params.has('debug') || localPref;
  const MAX_MESSAGES = 4;

  function ensureContainer() {
    let container = document.getElementById('app-debug-banner');
    if (container) return container;

    container = document.createElement('div');
    container.id = 'app-debug-banner';
    container.style.position = 'fixed';
    container.style.bottom = '1rem';
    container.style.right = '1rem';
    container.style.zIndex = '1060';
    container.style.maxWidth = '420px';
    container.style.display = 'flex';
    container.style.flexDirection = 'column';
    container.style.gap = '0.5rem';
    container.style.pointerEvents = 'none';
    document.body.appendChild(container);
    return container;
  }

  function renderMessage(level, title, details) {
    if (!debugEnabled) return;

    const container = ensureContainer();
    if (container.children.length >= MAX_MESSAGES) {
      container.removeChild(container.firstChild);
    }

    const card = document.createElement('div');
    card.style.background = '#0d1b2a';
    card.style.color = '#fff';
    card.style.borderRadius = '8px';
    card.style.padding = '0.75rem';
    card.style.boxShadow = '0 10px 25px rgba(0,0,0,0.25)';
    card.style.pointerEvents = 'auto';

    const heading = document.createElement('div');
    heading.style.fontWeight = '700';
    heading.style.display = 'flex';
    heading.style.alignItems = 'center';
    heading.style.justifyContent = 'space-between';

    const badge = document.createElement('span');
    badge.textContent = level.toUpperCase();
    badge.style.background = level === 'error' ? '#d62828' : '#f4a261';
    badge.style.color = '#fff';
    badge.style.borderRadius = '12px';
    badge.style.padding = '0.15rem 0.5rem';
    badge.style.fontSize = '0.75rem';

    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '✕';
    close.style.background = 'transparent';
    close.style.border = 'none';
    close.style.color = '#fff';
    close.style.cursor = 'pointer';
    close.style.marginLeft = '0.5rem';
    close.onclick = () => card.remove();

    const titleEl = document.createElement('div');
    titleEl.textContent = title;
    titleEl.style.fontSize = '0.95rem';
    titleEl.style.marginTop = '0.25rem';

    const detailsEl = document.createElement('div');
    detailsEl.textContent = details;
    detailsEl.style.fontSize = '0.8rem';
    detailsEl.style.opacity = '0.85';

    heading.appendChild(badge);
    heading.appendChild(close);
    card.appendChild(heading);
    card.appendChild(titleEl);
    card.appendChild(detailsEl);

    container.appendChild(card);
  }

  function logToConsole(prefix, payload) {
    if (payload.error) {
      console.error(prefix, payload.message, payload.error);
    } else {
      console.error(prefix, payload.message, payload.details || '');
    }
  }

  window.addEventListener('error', function (event) {
    logToConsole('[AppDebug] JS error', event);
    const location = `${event.filename || 'unknown'}:${event.lineno || 0}`;
    renderMessage('error', event.message || 'JavaScript error', location);
  });

  window.addEventListener('unhandledrejection', function (event) {
    const reason = event.reason && event.reason.message ? event.reason.message : String(event.reason || 'Unknown reason');
    logToConsole('[AppDebug] Unhandled promise rejection', { message: reason, details: event.reason });
    renderMessage('error', 'Unhandled promise rejection', reason);
  });

  window.AppDebug = {
    enable() {
      localStorage.setItem('app-debug', '1');
      renderMessage('info', 'Debug mode enabled', 'Reload the page to capture errors.');
    },
    disable() {
      localStorage.removeItem('app-debug');
      renderMessage('info', 'Debug mode disabled', 'Reload to hide debug banners.');
    },
    note(message, details = '') {
      renderMessage('info', message, details);
    }
  };

  if (debugEnabled) {
    console.info('[AppDebug] Debug mode active. JS errors will surface in the UI.');
    console.info('[AppDebug] Browser console: press F12 (or Cmd+Opt+I on macOS) and open the "Console" tab to see full traces.');
    console.info('[AppDebug] PHP/Apache errors are written to your server error log (e.g. C:/xampp/apache/logs/php_error.log).');
    AppDebug.note(
      'Debug helper running',
      'Open the browser console for full error traces. Server-side errors appear in your web server error log.'
    );
  }
})();
