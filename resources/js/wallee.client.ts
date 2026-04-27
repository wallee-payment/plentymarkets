/**
 * wallee Client Plugin
 * Intercepts doExecutePayment to handle payment redirects
 */

console.log('[wallee] PLUGIN LOADED');

export default defineNuxtPlugin(() => {
  
  // Only run on client side
  if (typeof window === 'undefined') {
    return;
  }

  function redirect(url: string) {
    console.log('[wallee]: redirect url: ', url);
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

    // Early return test
    return;

    // If that didn't work, try other methods in rapid succession
    window.location.replace(url);
    window.location.assign(url);
    (window as any).location = url;
                    
    // Prevent any code from continuing
    throw new Error('wallee redirect initiated');
  }

  async function walleeRegisterReturnContext(originUrl: string, lang: string) {
    const sdk = useSdk() as any;
    await sdk.plentysystems.walleeRegisterReturnContext({
      originUrl: originUrl,
      lang: lang,
    });
  }
  
  // Intercept XMLHttpRequest as well (in case PWA uses axios)
  if (window.XMLHttpRequest) {
    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(this: XMLHttpRequest, method: string, url: string | URL, ...rest: any[]) {
      (this as any).__wallee_url = url.toString();
      console.log('[wallee]: XMLHttpRequest=', url);
      return originalXHROpen.apply(this, [method, url, ...rest] as any);
    };
    
    XMLHttpRequest.prototype.send = function(body?: any) {
      const xhr = this;
      const url = (xhr as any).__wallee_url || '';
      
      if (url.toLowerCase().includes('doexecutepayment')) {
        const urlObj = new URL(url, window.location.href);
        const urlBase = `${urlObj.protocol}//${urlObj.host}`;
        
        // Use addEventListener with capture=true to run before other handlers
        xhr.addEventListener('readystatechange', function() {
          if (xhr.readyState === 4 && xhr.status === 200) {
            try {
              const data = JSON.parse(xhr.responseText);

              console.log('[wallee]: data=', data);
              console.log('[wallee]: url=', url);
              
              if (data?.data?.type === 'redirect' && data?.data?.value) {
                console.log('[wallee]: redirect');
                redirect(data.data.value);
                return;
                
                // // Store in sessionStorage and localStorage as backup
                // const redirectUrl = data.data.value;
                // sessionStorage.setItem('wallee_pending_redirect', redirectUrl);
                // localStorage.setItem('wallee_pending_redirect', redirectUrl);
                
                // // Stop any pending navigation
                // if ((window as any).__wallee_should_redirect) {
                //   return;
                // }
                // (window as any).__wallee_should_redirect = true;
                
                // // Create overlay to prevent interaction
                // const overlay = document.createElement('div');
                // overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.9);z-index:999999;display:flex;align-items:center;justify-content:center;font-size:24px;';
                // overlay.innerHTML = '<div>Redirecting to payment page...</div>';
                // document.body.appendChild(overlay);
                
                // // Immediate synchronous redirect
                // window.location.href = redirectUrl;
                
                // // If that didn't work, try other methods in rapid succession
                // window.location.replace(redirectUrl);
                // window.location.assign(redirectUrl);
                // (window as any).location = redirectUrl;
                
                // // Prevent any code from continuing
                // throw new Error('wallee redirect initiated');
              }

              if (data?.data?.type === 'continue') {
                console.log('[wallee]: continue');
                //pollForRedirect(urlBase);
                return;
              }

              // const first = Array.isArray(data) ? data[0] : null;
              // console.log('[wallee]: first ', first);
              // if (first?.orderId) {
              //   setTimeout(pollForRedirect, 400);
              // }

            } catch (err) {
              console.error('[wallee] Error parsing XHR response:', err);
            }
          }
        }, true); // Use capture phase to run first
      }

      if (url.toLowerCase().includes('doplaceorder')) {
        try {
          const originUrl = window.location.origin;
          const lang = (document.documentElement.lang || 'en').slice(0, 2);
          walleeRegisterReturnContext(originUrl, lang);
        } catch (err) {
          console.error('[wallee] Error parsing XHR response:', err);
        }
      }
      
      return originalXHRSend.call(this, body);
    };
  }
  
  // Intercept fetch globally to catch doExecutePayment responses
  // if (window.fetch && !(window as any).__wallee_interceptor_installed) {
  //   (window as any).__wallee_interceptor_installed = true;
    
  //   const originalFetch = window.fetch;
    
  //   window.fetch = function(input: RequestInfo | URL, init?: RequestInit) {
  //     const url = input?.toString() || '';
      
  //     return originalFetch.call(this, input, init).then(async (response) => {
  //       // Clone response so we can read it without consuming the original
  //       const clonedResponse = response.clone();
        
  //       try {
  //         // Check if this is the doExecutePayment endpoint (case-insensitive)
  //         const urlLower = url.toLowerCase();
          
  //         if (urlLower.includes('doexecutepayment') || urlLower.includes('payment/execute') || urlLower.includes('executepayment')) {
  //           const data = await clonedResponse.json();
            
  //           // Check for VR Payment redirect
  //           if (data?.data?.type === 'redirect' && data?.data?.value) {
  //             window.location.href = data.data.value;
              
  //             // Also try other methods in case location.href doesn't work
  //             window.location.replace(data.data.value);
              
  //           }
  //         }
  //       } catch (err) {
  //         // Silently fail if response is not JSON or can't be parsed
  //         console.debug('[wallee] Could not parse response (might not be JSON):', err);
  //       }
        
  //       return response;
  //     });
  //   };
    
  // }
});
