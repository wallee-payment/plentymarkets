<template>
  <div class="wallee-failed-recovery">
    <div class="content">
      <div class="spinner"></div>
      <h1>Payment unsuccessful</h1>
      <p>Restoring the checkout session, please wait...</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';

const route = useRoute();
const router = useRouter();

const orderId = route.params.orderId as string;
const transactionId = route.params.transactionId as string;

console.log('[wallee] Recovery page hit', { orderId, transactionId });

onMounted(async () => {
  try {
    const sdk = useSdk() as any;
    
    console.log('[wallee] Attempting cart restoration for order:', orderId);
    
    // Call the backend to move items from the failed order back to the basket
    await sdk.plentysystems.walleeRestoreCart({ orderId });
    
    console.log('[wallee] Cart restored successfully');
  } catch (err) {
    console.error('[wallee] Failed to restore cart:', err);
  } finally {
    // Set the flag so the checkout page knows to show the error banner
    sessionStorage.setItem('wallee_show_failure_notice', '1');
    
    // Redirect back to checkout
    router.replace('/checkout');
  }
});
</script>

<style scoped>
.wallee-failed-recovery {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 80vh;
  text-align: center;
  font-family: sans-serif;
}
.spinner {
  border: 4px solid rgba(0, 0, 0, 0.1);
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border-left-color: #09f;
  animation: spin 1s ease infinite;
  margin: 0 auto 20px;
}
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
h1 { font-size: 24px; color: #333; }
p { color: #666; }
</style>
