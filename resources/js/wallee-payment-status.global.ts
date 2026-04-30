/**
 * wallee Client Plugin
 * Global route that's meant to catch wallee-failed url, activate restore cart and redirect back to checkout
 * 
 * This file goes to "apps/web/app/middleware"
 */

export default defineNuxtRouteMiddleware(async (to) => {
  if (import.meta.server) return;

  const match = to.path.match(/^\/checkout\/wallee-failed\/(\d+)(?:\/(\d+))?$/);
  if (!match) return;

  const orderId = match[1];
  const transactionId = match[2];
  console.log('[wallee] failed return', { orderId, transactionId });

  if (orderId) {
    try {
      const sdk = useSdk() as any;
      await sdk.plentysystems.walleeRestoreCart({ orderId });
    } catch (e) {
      console.warn('[wallee] basket restore failed', e);
    }
  }

  if (import.meta.client) {
    sessionStorage.setItem('wallee_show_failure_notice', '1');
  }

  return navigateTo('/checkout', { replace: true });
});