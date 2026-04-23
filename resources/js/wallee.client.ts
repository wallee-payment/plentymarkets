/**
 * wallee Client Plugin
 * Intercepts doExecutePayment to handle payment redirects
 */
export default defineNuxtPlugin(() => {
  
  // Only run on client side
  if (typeof window === 'undefined') {
    return;
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
      console.log('[wallee] Intercept XMLHttpRequest');

      const xhr = this;
      const url = (xhr as any).__wallee_url || '';

      console.log('[wallee] Intercept XMLHttpRequest url: ', url);
      
      if (url.toLowerCase().includes('doexecutepayment')) {
        console.log('[wallee] Intercept XMLHttpRequest: doexecutepayment');
        
        // Use addEventListener with capture=true to run before other handlers
        xhr.addEventListener('readystatechange', function() {
          if (xhr.readyState === 4 && xhr.status === 200) {
            try {
              const data = JSON.parse(xhr.responseText);

              console.log('[wallee] Intercept XMLHttpRequest data: ', data);
              
              if (data?.data?.type === 'redirect' && data?.data?.value) {
                
                // Store in sessionStorage and localStorage as backup
                const redirectUrl = data.data.value;
                sessionStorage.setItem('wallee_pending_redirect', redirectUrl);
                localStorage.setItem('wallee_pending_redirect', redirectUrl);

                console.log('[wallee] Intercept XMLHttpRequest redirectUrl: ', redirectUrl);
                
                // Stop any pending navigation
                if ((window as any).__wallee_should_redirect) {
                  console.log('[wallee] Intercept XMLHttpRequest: Stop any pending navigation');
                  return;
                }
                (window as any).__wallee_should_redirect = true;
                
                // Create overlay to prevent interaction
                const overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.9);z-index:999999;display:flex;align-items:center;justify-content:center;font-size:24px;';
                overlay.innerHTML = '<div>Redirecting to payment page...</div>';
                document.body.appendChild(overlay);
                
                // Immediate synchronous redirect
                console.log('[wallee] Intercept XMLHttpRequest: synchronous redirect');
                window.location.href = redirectUrl;
                
                console.log('[wallee] Intercept XMLHttpRequest: other methods');
                // If that didn't work, try other methods in rapid succession
                window.location.replace(redirectUrl);
                window.location.assign(redirectUrl);
                (window as any).location = redirectUrl;
                
                // Prevent any code from continuing
                throw new Error('wallee redirect initiated');
              }
            } catch (err) {
              console.error('[wallee] Error parsing XHR response:', err);
            }
          }
        }, true); // Use capture phase to run first
      }
      console.log('[wallee] Intercept XMLHttpRequest: return originalXHRSend.call');
      return originalXHRSend.call(this, body);
    };
  }
  
  // Intercept fetch globally to catch doExecutePayment responses
  if (window.fetch && !(window as any).__wallee_interceptor_installed) {
    console.log('[wallee] Intercept fetch globally');

    (window as any).__wallee_interceptor_installed = true;
    
    const originalFetch = window.fetch;
    
    window.fetch = function(input: RequestInfo | URL, init?: RequestInit) {
      const url = input?.toString() || '';
      console.log('[wallee] Intercept fetch globally url: ', url);
      
      return originalFetch.call(this, input, init).then(async (response) => {
        // Clone response so we can read it without consuming the original
        const clonedResponse = response.clone();

        console.log('[wallee] Intercept fetch globally clonedResponse: ', clonedResponse);
        
        try {
          // Check if this is the doExecutePayment endpoint (case-insensitive)
          const urlLower = url.toLowerCase();

          console.log('[wallee] Intercept fetch globally urlLower: ', urlLower);
          
          if (urlLower.includes('doexecutepayment') || urlLower.includes('payment/execute') || urlLower.includes('executepayment')) {
            const data = await clonedResponse.json();

            console.log('[wallee] Intercept fetch globally data: ', data);
            
            // Check for VR Payment redirect
            if (data?.data?.type === 'redirect' && data?.data?.value) {
              console.log('[wallee] Intercept fetch globally: synchronous redirect');
              window.location.href = data.data.value;
              
              // Also try other methods in case location.href doesn't work
              console.log('[wallee] Intercept fetch globally: other methods');
              window.location.replace(data.data.value);
              
            }
          }
        } catch (err) {
          // Silently fail if response is not JSON or can't be parsed
          console.debug('[wallee] Could not parse response (might not be JSON):', err);
        }
        console.log('[wallee] Intercept fetch globally response: ', response);
        return response;
      });
    };
    
  }
});
