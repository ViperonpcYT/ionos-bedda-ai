import { useEffect } from 'react';

const LEGACY_SCRIPTS = [
  '/js/site-config.js',
  '/main-security.js',
  '/logger.js',
  '/main.js',
  '/js/site-layout.js',
  '/js/conversion.js',
  '/js/stock-badges.js',
] as const;

let loadPromise: Promise<void> | null = null;

function loadScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[data-ob-legacy="${src}"]`)) {
      resolve();
      return;
    }
    const el = document.createElement('script');
    el.src = src;
    el.async = false;
    el.dataset.obLegacy = src;
    el.onload = () => resolve();
    el.onerror = () => reject(new Error(`Failed to load ${src}`));
    document.body.appendChild(el);
  });
}

export function useLegacyScripts(enabled = true) {
  useEffect(() => {
    if (!enabled) return;
    if (!loadPromise) {
      loadPromise = (async () => {
        for (const src of LEGACY_SCRIPTS) {
          await loadScript(src);
        }
      })();
    }
    loadPromise.catch((err) => {
      console.warn('[OnlyBikes] Legacy checkout scripts:', err);
    });
  }, [enabled]);
}
