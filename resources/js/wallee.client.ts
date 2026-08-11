/**
 * wallee Client Plugin
 * Intercepts doExecutePayment to handle payment redirects
 *
 * This file goes to "apps/web/app/plugins"
 */

// Registered on the shop's i18n instance at runtime rather than shipped into
// "apps/web/app/lang", so the theme's own locale files are never overwritten
// and the plugin keeps working across theme updates. Kept inline rather than in
// sibling JSON files so the whole plugin stays a single file to copy into the PWA.
const LOCALE_MESSAGES: Record<string, any> = {
  en: {
    'wallee': {
      checkout: {
        redirecting: 'Redirecting...',
        paymentFailed: 'Your payment could not be completed. Please try again.',
      },
    },
  },
  de: {
    'wallee': {
      checkout: {
        redirecting: 'Weiterleitung läuft...',
        paymentFailed: 'Ihre Zahlung konnte nicht abgeschlossen werden. Bitte versuchen Sie es erneut.',
      },
    },
  },
  fr: {
    'wallee': {
      checkout: {
        redirecting: 'Redirection en cours...',
        paymentFailed: "Votre paiement n'a pas pu être finalisé. Veuillez réessayer.",
      },
    },
  },
  it: {
    'wallee': {
      checkout: {
        redirecting: 'Reindirizzamento in corso...',
        paymentFailed: 'Non è stato possibile completare il pagamento. Riprova.',
      },
    },
  },
};

export default defineNuxtPlugin((nuxtApp) => {

  // Only run on client side
  if (typeof window === 'undefined') {
    return;
  }

  let localesRegistered = false;

  /**
   * Translates one of this plugin's own keys through the shop's i18n instance.
   * $i18n is resolved lazily so this works regardless of whether the plugin is
   * loaded before or after @nuxtjs/i18n. Falls back to the English message when
   * i18n is unavailable, so the customer never sees a raw translation key.
   */
  function translate(key: string): string {
    const fallback = LOCALE_MESSAGES.en['wallee'].checkout[key];
    const i18n = (nuxtApp as any).$i18n;

    if (typeof i18n?.t !== 'function') {
      return fallback;
    }

    if (!localesRegistered && typeof i18n.mergeLocaleMessage === 'function') {
      Object.keys(LOCALE_MESSAGES).forEach((locale) => {
        i18n.mergeLocaleMessage(locale, LOCALE_MESSAGES[locale]);
      });
      localesRegistered = true;
    }

    // vue-i18n echoes the key back when no message is registered for it
    const fullKey = 'wallee.checkout.' + key;
    const translated = i18n.t(fullKey);
    return !translated || translated === fullKey ? fallback : translated;
  }

  // The upstream checkout flow navigates to /confirmation as soon as the order
  // is created, regardless of what doExecutePayment resolves to (it doesn't
  // check the response). That races the hard redirect below: if the router
  // navigation wins, /confirmation renders before window.location.href takes
  // effect. Once a redirect is pending, cancel any further route navigation
  // so the confirmation page never mounts.
  const router = (nuxtApp as any).$router;
  if (router?.beforeEach) {
    router.beforeEach(() => {
      if ((window as any).__wallee_should_redirect) {
        return false;
      }
    });
  }

  /**
   * Returns the language the customer is currently browsing with.
   * Prefers the nuxt-i18n locale (the source of truth for the PWA URL
   * language prefix); falls back to the <html lang> attribute. An empty
   * result is fine: the plugin endpoint falls back to the webstore
   * default language.
   */
  function getActiveLang(): string {
    const i18n = (nuxtApp as any).$i18n;
    if (i18n?.locale?.value) {
      return i18n.locale.value;
    }
    return (document.documentElement.lang || '').slice(0, 2);
  }

  function registerReturnContext() {
    walleeRegisterReturnContext(window.location.origin, getActiveLang())
      .catch((err: any) => console.error('[wallee] Context registration failed:', err));
  }

  // Register origin url + language once on startup and again on every language
  // switch, so the session values are present even when doPreparePayment is not
  // sent via XMLHttpRequest (the interception below only covers XHR).
  nuxtApp.hook('app:mounted', registerReturnContext);
  const i18n = (nuxtApp as any).$i18n;
  if (i18n?.locale) {
    watch(i18n.locale, registerReturnContext);
  }

  const url = new URL(window.location.href);

  if (url.pathname.endsWith('/checkout') && url.searchParams.get('wallee_failed') === '1') {
    const orderId = url.searchParams.get('orderId');
    if (orderId) {
      try {
        walleeRestoreCart(orderId);
      } catch (err) {
        console.error('[wallee] basket restore failed: ', err);
      }
    } else {
      console.warn('[wallee] OrderId is not present:');
    }
    try {
      const { send } = (window as any).$nuxt?.$nuxt?.useNotification?.() ?? {};
      if (send) {
        send({ type: 'negative', message: translate('paymentFailed') });
      } else {
        showFallbackBanner();
      }
    } catch(err) {
      showFallbackBanner();
    }
    url.searchParams.delete('wallee_failed');
    url.searchParams.delete('orderId');
    url.searchParams.delete('transactionId');
    window.history.replaceState({}, '', url.toString());
  }

  function showFallbackBanner() {
    const banner = document.createElement('div');
    banner.style.cssText =
      'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#fee;border:1px solid #f99;padding:12px 20px;border-radius:6px;z-index:99999;color:#900;';
    banner.textContent = translate('paymentFailed');
    document.body.appendChild(banner);
    setTimeout(() => banner.remove(), 6000);
  }

  function redirect(url: string) {
    if (!/^https?:\/\//i.test(url)) {
      console.error('[wallee] redirect value is not an absolute URL — the backend likely stored an error message as the redirect URL:', url);
    }
    sessionStorage.setItem('wallee_pending_redirect', url);
    localStorage.setItem('wallee_pending_redirect', url);
    if ((window as any).__wallee_should_redirect) {
      return;
    }
    (window as any).__wallee_should_redirect = true;

    // Create overlay to prevent interaction
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.9);z-index:999999;display:flex;align-items:center;justify-content:center;font-size:24px;';
    const message = document.createElement('div');
    message.textContent = translate('redirecting');
    overlay.appendChild(message);
    document.body.appendChild(overlay);

    // Immediate synchronous redirect
    window.location.href = url;
  }

  async function walleeRegisterReturnContext(originUrl: string, lang: string) {
    const sdk = useSdk() as any;
    await sdk.plentysystems.walleeRegisterReturnContext({
      originUrl: originUrl,
      lang: lang,
    });
  }

  async function walleeRestoreCart(orderId: string) {
    const sdk = useSdk() as any;
    await sdk.plentysystems.walleeRestoreCart({
      orderId: orderId
    });
  }
  
  // Intercept XMLHttpRequest as well (in case PWA uses axios)
  if (window.XMLHttpRequest) {
    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(this: XMLHttpRequest, method: string, url: string | URL, ...rest: any[]) {
      (this as any).__wallee_url = url.toString();
      return originalXHROpen.apply(this, [method, url, ...rest] as any);
    };
    
    XMLHttpRequest.prototype.send = function(body?: any) {
      const xhr = this;
      const url = (xhr as any).__wallee_url || '';

      if (url.toLowerCase().includes('doexecutepayment')) {
        xhr.addEventListener('readystatechange', function() {
          if (xhr.readyState !== 4) {
            return;
          }
          if (xhr.status !== 200) {
            console.error('[wallee] doExecutePayment returned non-200 status, no redirect will happen. status:', xhr.status, 'body:', xhr.responseText);
            return;
          }
          try {
            const data = JSON.parse(xhr.responseText);

            if ((data?.data?.type === 'redirect' || data?.data?.type === 'redirectUrl') && data?.data?.value) {
              redirect(data.data.value);
            } else {
              console.warn('[wallee] doExecutePayment did not return a redirect — staying in shop. Full payload:', xhr.responseText);
            }
          } catch (err) {
            console.error('[wallee] Error parsing XHR response:', err, 'body:', xhr.responseText);
          }
        }, true);
      }

      if (url.toLowerCase().includes('dopreparepayment')) {
        try {
          const originUrl = window.location.origin;
          const lang = getActiveLang();

          // Wait for context registration before sending the actual request
          walleeRegisterReturnContext(originUrl, lang)
            .catch((err: any) => console.error('[wallee] Context registration failed:', err))
            .finally(() => {
              originalXHRSend.call(xhr, body);
            });
          
          return;
        } catch (err) {
          console.error('[wallee] Error in dopreparepayment interception:', err);
        }
      }
      return originalXHRSend.call(this, body);
    };
  }
});
