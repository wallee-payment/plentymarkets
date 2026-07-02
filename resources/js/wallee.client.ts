/**
 * wallee Client Plugin
 * Intercepts doExecutePayment to handle payment redirects
 * 
 * This file goes to "apps/web/app/plugins"
 */

export default defineNuxtPlugin((nuxtApp) => {

  // Only run on client side
  if (typeof window === 'undefined') {
    return;
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
        send({ type: 'negative', message: 'Your payment could not be completed. Please try again.' });
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
    banner.textContent = 'Your payment could not be completed. Please try again.';
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
    overlay.innerHTML = '<div>Redirecting to payment page...</div>';
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
