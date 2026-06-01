<template>
  <div class="wallee-payment-selection">
    <!-- Loading state -->
    <div v-if="isLoading" class="loading-container">
      <div class="spinner"></div>
      <p>Loading payment options...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="errorMessage" class="error-container">
      <div class="error-icon">⚠</div>
      <h1>Something went wrong</h1>
      <p>{{ errorMessage }}</p>
      <p class="redirect-notice">Redirecting you shortly...</p>
    </div>

    <!-- Payment selection UI -->
    <div v-else class="payment-container">
      <h1>Choose Payment Method</h1>
      <p class="subtitle">
        Your previous payment for order <strong>#{{ orderId }}</strong> was not completed.
        Please select a payment method to try again.
      </p>

      <!-- Order summary -->
      <div v-if="orderData" class="order-summary">
        <h2>Order Summary</h2>
        <div class="order-items">
          <div
            v-for="(item, index) in productItems"
            :key="index"
            class="order-item"
          >
            <span class="item-name">{{ item.orderItemName }}</span>
            <span class="item-qty">× {{ item.quantity }}</span>
          </div>
        </div>
        <div v-if="orderTotalGross !== undefined" class="order-total">
          <span>Total</span>
          <strong>{{ formatCurrency(orderTotalGross, orderCurrency) }}</strong>
        </div>
      </div>

      <!-- Payment methods -->
      <div class="payment-methods">
        <h2>Payment Method</h2>
        <div
          v-for="method in paymentMethods"
          :key="method.id"
          class="payment-method-option"
          :class="{ selected: selectedPaymentMethod === String(method.id) }"
        >
          <label :for="'pm-' + method.id" class="payment-label">
            <input
              :id="'pm-' + method.id"
              v-model="selectedPaymentMethod"
              type="radio"
              name="paymentMethod"
              :value="String(method.id)"
            />
            <img
              v-if="method.icon"
              :src="method.icon"
              :alt="method.name"
              class="payment-icon"
            />
            <div class="payment-info">
              <span class="payment-name">{{ method.name }}</span>
              <span v-if="method.description" class="payment-desc">{{ method.description }}</span>
            </div>
          </label>
        </div>
      </div>

      <!-- Submit button -->
      <button
        class="submit-button"
        :disabled="!selectedPaymentMethod || isSubmitting"
        @click="submitPayment"
      >
        <span v-if="isSubmitting" class="btn-spinner"></span>
        <span v-else>Complete Payment</span>
      </button>

      <!-- Submission error feedback -->
      <div v-if="submitError" class="submit-error">
        {{ submitError }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';

const route = useRoute();
const router = useRouter();

const orderId = ref<string>('');
const isLoading = ref<boolean>(true);
const isSubmitting = ref<boolean>(false);
const errorMessage = ref<string>('');
const submitError = ref<string>('');
const orderData = ref<any>(null);
const paymentMethods = ref<any[]>([]);
const selectedPaymentMethod = ref<string>('');

/**
 * Filter order items to only show product items (typeId 1) in the summary.
 * Shipping, coupons, and other item types are excluded from the display.
 */
const productItems = computed(() => {
  if (!orderData.value?.orderItems) {
    return [];
  }
  return orderData.value.orderItems.filter((item: any) => item.typeId === 1);
});

/**
 * Extract the order currency from the amounts array for formatting.
 * We look for the non-system currency (which represents the customer's purchase currency)
 * to avoid displaying the default store system currency (e.g. CHF) instead of the order currency (e.g. GBP).
 */
const orderCurrency = computed(() => {
  if (!orderData.value?.amounts?.length) {
    return 'EUR';
  }
  const nonSystemAmount = orderData.value.amounts.find(
    (amount: any) => amount.isSystemCurrency === false || amount.isSystemCurrency === 0 || amount.isSystemCurrency === 'false'
  );
  return (nonSystemAmount || orderData.value.amounts[0])?.currency || 'EUR';
});

/**
 * Get the total gross amount of the order in the customer's selected currency.
 */
const orderTotalGross = computed(() => {
  if (!orderData.value?.amounts?.length) {
    return 0;
  }
  const nonSystemAmount = orderData.value.amounts.find(
    (amount: any) => amount.isSystemCurrency === false || amount.isSystemCurrency === 0 || amount.isSystemCurrency === 'false'
  );
  return (nonSystemAmount || orderData.value.amounts[0])?.grossTotal ?? (orderData.value.totals?.totalGross || 0);
});

/**
 * Format a numeric amount with its currency symbol.
 */
function formatCurrency(amount: number | undefined, currency: string): string {
  if (amount === undefined || amount === null) {
    return '';
  }
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currency,
    }).format(amount);
  } catch {
    return `${amount.toFixed(2)} ${currency}`;
  }
}

onMounted(async () => {
  // Read orderId from route params instead of query string to support restful URLs
  const paramsOrderId = route.params.orderId as string;
  
  if (!paramsOrderId) {
    errorMessage.value = 'No order specified.';
    isLoading.value = false;
    redirectAway();
    return;
  }
  orderId.value = paramsOrderId;

  try {
    const sdk = useSdk() as any;
    const result = await sdk.plentysystems.walleeGetOrderCheckoutData({
      orderId: paramsOrderId,
    });

    const responseData = result?.data || result;

    if (!responseData || responseData.error) {
      errorMessage.value = responseData?.error || 'Failed to load order data.';
      isLoading.value = false;
      redirectAway();
      return;
    }

    if (!responseData.allowRetry) {
      /**
       * IMPORTANT — Why we do NOT restore the cart here:
       *
       * The original Plentymarkets order is still active with an "Unpaid" payment
       * status. Its line items continue to hold inventory reservations in the shop
       * system. If we were to automatically copy those items into a new basket (as
       * the old restoreCart flow did), the customer would proceed through checkout
       * and create a SECOND order for the same products — causing double inventory
       * deduction for the merchant.
       *
       * To safely restore a cart, the original order would first need to be fully
       * canceled (status 8.0), which releases its stock reservations. However,
       * canceling the order defeats the purpose of the retry architecture: we
       * explicitly keep the order alive so the customer can retry payment on it.
       *
       * Therefore, when retry is not allowed (order too old, already paid, or
       * ineligible status), we simply redirect the customer away. The merchant can
       * handle these stale unpaid orders through their normal order management
       * workflows (e.g., automatic cancellation after X days).
       */
      errorMessage.value = 'Payment retry is no longer available for this order.';
      isLoading.value = false;
      redirectAway();
      return;
    }

    orderData.value = responseData.orderData;
    paymentMethods.value = responseData.paymentMethods || [];

    // Pre-select the current payment method if it's in the list
    if (responseData.currentPaymentMethodId) {
      const currentId = String(responseData.currentPaymentMethodId);
      const exists = paymentMethods.value.some(
        (m: any) => String(m.id) === currentId,
      );
      if (exists) {
        selectedPaymentMethod.value = currentId;
      }
    }

    // If only one method is available, auto-select it
    if (!selectedPaymentMethod.value && paymentMethods.value.length === 1) {
      selectedPaymentMethod.value = String(paymentMethods.value[0].id);
    }
  } catch (err: any) {
    console.error('[wallee] Failed to load order checkout data:', err);
    errorMessage.value = 'Could not load payment options. Please try again later.';
    redirectAway();
  } finally {
    isLoading.value = false;
  }
});

/**
 * Redirect the user away from this page when retry is not possible.
 */
function redirectAway(): void {
  setTimeout(() => {
    router.replace('/');
  }, 3000);
}

/**
 * Submit the payment retry request to the backend.
 * On success, redirects the browser to the Wallee payment page.
 */
async function submitPayment(): Promise<void> {
  if (!selectedPaymentMethod.value || isSubmitting.value) {
    return;
  }

  isSubmitting.value = true;
  submitError.value = '';

  try {
    const sdk = useSdk() as any;
    const result = await sdk.plentysystems.walleePayOrderRest({
      orderId: orderId.value,
      paymentMethodId: selectedPaymentMethod.value,
    });

    const responseData = result?.data || result;

    if (responseData?.redirectUrl) {
      // Navigate to the Wallee payment page
      window.location.href = responseData.redirectUrl;
      return;
    }

    if (responseData?.error) {
      submitError.value = responseData.error;
    } else if (responseData?.status === 'continue') {
      // Payment method doesn't require redirect, send user to confirmation
      router.replace(`/confirmation/${orderId.value}`);
    } else {
      submitError.value = 'Unexpected response from payment server.';
    }
  } catch (err: any) {
    console.error('[wallee] Payment retry failed:', err);
    submitError.value = 'Payment could not be processed. Please try again.';
  } finally {
    isSubmitting.value = false;
  }
}
</script>

<style scoped>
.wallee-payment-selection {
  max-width: 600px;
  margin: 40px auto;
  padding: 0 20px;
  font-family: system-ui, -apple-system, sans-serif;
}

/* Loading */
.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 60vh;
  text-align: center;
  color: #666;
}

/* Error */
.error-container {
  text-align: center;
  padding: 60px 20px;
}
.error-icon {
  font-size: 48px;
  margin-bottom: 16px;
}
.error-container h1 {
  font-size: 22px;
  color: #333;
  margin-bottom: 8px;
}
.error-container p {
  color: #666;
  margin-bottom: 4px;
}
.redirect-notice {
  font-style: italic;
  font-size: 14px;
  color: #999;
  margin-top: 12px;
}

/* Payment container */
.payment-container h1 {
  font-size: 24px;
  color: #1a1a1a;
  margin-bottom: 8px;
}
.subtitle {
  color: #555;
  margin-bottom: 28px;
  line-height: 1.5;
}

/* Order summary */
.order-summary {
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 8px;
  padding: 20px;
  margin-bottom: 28px;
}
.order-summary h2 {
  font-size: 16px;
  color: #333;
  margin-bottom: 12px;
}
.order-item {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 14px;
  color: #555;
}
.item-name {
  flex: 1;
  margin-right: 12px;
}
.item-qty {
  white-space: nowrap;
  color: #888;
}
.order-total {
  display: flex;
  justify-content: space-between;
  border-top: 1px solid #dee2e6;
  margin-top: 12px;
  padding-top: 12px;
  font-size: 16px;
}

/* Payment methods */
.payment-methods {
  margin-bottom: 24px;
}
.payment-methods h2 {
  font-size: 16px;
  color: #333;
  margin-bottom: 12px;
}
.payment-method-option {
  border: 2px solid #e9ecef;
  border-radius: 8px;
  margin-bottom: 10px;
  transition: border-color 0.15s ease;
}
.payment-method-option.selected {
  border-color: #0d6efd;
  background: #f0f6ff;
}
.payment-label {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  cursor: pointer;
  width: 100%;
}
.payment-label input[type="radio"] {
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  accent-color: #0d6efd;
}
.payment-icon {
  width: 40px;
  height: 28px;
  object-fit: contain;
  flex-shrink: 0;
}
.payment-info {
  display: flex;
  flex-direction: column;
}
.payment-name {
  font-weight: 500;
  color: #1a1a1a;
}
.payment-desc {
  font-size: 13px;
  color: #888;
  margin-top: 2px;
}

/* Submit button */
.submit-button {
  width: 100%;
  padding: 14px;
  background: #0d6efd;
  color: white;
  border: none;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.submit-button:hover:not(:disabled) {
  background: #0b5ed7;
}
.submit-button:disabled {
  background: #b0c4de;
  cursor: not-allowed;
}

/* Submission error */
.submit-error {
  margin-top: 12px;
  padding: 12px 16px;
  background: #fff3f3;
  border: 1px solid #f5c6cb;
  border-radius: 6px;
  color: #842029;
  font-size: 14px;
}

/* Spinners */
.spinner {
  border: 4px solid rgba(0, 0, 0, 0.1);
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border-left-color: #0d6efd;
  animation: spin 0.8s linear infinite;
  margin-bottom: 16px;
}
.btn-spinner {
  display: inline-block;
  width: 20px;
  height: 20px;
  border: 3px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-left-color: #fff;
  animation: spin 0.8s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
