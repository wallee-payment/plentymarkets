<template>
  <div class="wallee-payment-selection">
    <!-- Loading state -->
    <div v-if="isLoading" class="loading-container">
      <div class="spinner"></div>
      <p>{{ texts.loading }}</p>
    </div>

    <!-- Error state -->
    <div v-else-if="errorMessage" class="error-container">
      <div class="error-icon">⚠</div>
      <h1>{{ texts.errorTitle }}</h1>
      <p>{{ errorMessage }}</p>
      <p class="redirect-notice">{{ texts.redirectNotice }}</p>
    </div>

    <!-- Payment selection UI -->
    <div v-else class="payment-container">
      <h1>{{ texts.title }}</h1>
      <p class="subtitle"><template v-for="(part, index) in subtitleParts" :key="index">{{ part }}<strong v-if="index < subtitleParts.length - 1">#{{ orderId }}</strong></template></p>

      <!-- Order summary -->
      <div v-if="orderData" class="order-summary">
        <h2>{{ texts.orderSummaryTitle }}</h2>
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
          <span>{{ texts.orderTotalLabel }}</span>
          <strong>{{ formatCurrency(orderTotalGross, orderCurrency) }}</strong>
        </div>
      </div>

      <!-- Payment methods -->
      <div class="payment-methods">
        <h2>{{ texts.paymentMethodTitle }}</h2>
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
        <span v-else>{{ texts.submitButton }}</span>
      </button>

      <!-- Back to shop button -->
      <button class="cancel-button" @click="router.replace(props.shopPath)">
        {{ texts.cancelButton }}
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

/**
 * All user-facing text in this component, so a host app can translate or
 * reword it without forking the component. Any subset can be overridden
 * via the `texts` prop; omitted keys fall back to `DEFAULT_TEXTS`.
 *
 * `subtitle` supports a `{orderId}` placeholder, which is replaced with the
 * order id rendered inside its own <strong> tag.
 */
interface PaymentSelectionTexts {
  loading: string;
  errorTitle: string;
  redirectNotice: string;
  title: string;
  subtitle: string;
  orderSummaryTitle: string;
  orderTotalLabel: string;
  paymentMethodTitle: string;
  submitButton: string;
  cancelButton: string;
  errorNoOrder: string;
  errorLoadFailed: string;
  errorRetryNotAllowed: string;
  errorLoadGeneric: string;
  errorUnexpectedResponse: string;
  errorSubmitFailed: string;
}

const DEFAULT_TEXTS: PaymentSelectionTexts = {
  loading: 'Loading payment options...',
  errorTitle: 'Something went wrong',
  redirectNotice: 'Redirecting you shortly...',
  title: 'Payment canceled',
  subtitle: 'The order {orderId} was submitted, but the payment was canceled. Please choose a different payment method and try again.',
  orderSummaryTitle: 'Order Summary',
  orderTotalLabel: 'Total',
  paymentMethodTitle: 'Payment Method',
  submitButton: 'Complete Payment',
  cancelButton: 'Return to shop',
  errorNoOrder: 'No order specified.',
  errorLoadFailed: 'Failed to load order data.',
  errorRetryNotAllowed: 'Payment retry is no longer available for this order.',
  errorLoadGeneric: 'Could not load payment options. Please try again later.',
  errorUnexpectedResponse: 'Unexpected response from payment server.',
  errorSubmitFailed: 'Payment could not be processed. Please try again.',
};

const props = withDefaults(
  defineProps<{
    texts?: Partial<PaymentSelectionTexts>;
    /** Route to navigate to when the customer leaves this page (e.g. on error, or via "Return to shop"). */
    shopPath?: string;
    /** Route to navigate to once the payment retry succeeds without a redirect. Supports an `{orderId}` placeholder. */
    confirmationPath?: string;
  }>(),
  {
    shopPath: '/',
    confirmationPath: '/confirmation/{orderId}',
  },
);

const texts = computed<PaymentSelectionTexts>(() => ({
  ...DEFAULT_TEXTS,
  ...props.texts,
}));

const subtitleParts = computed(() => texts.value.subtitle.split('{orderId}'));

/** A single amount entry from a Plentymarkets order, in either the system or customer currency. */
interface OrderAmount {
  currency: string;
  isSystemCurrency: boolean | number | string;
  grossTotal: number;
}

/** A line item of an order, as returned by walleeGetOrderCheckoutData. */
interface OrderItem {
  orderItemName: string;
  quantity: number;
  typeId: number;
}

/** Order summary data used to render the order overview. */
interface OrderData {
  orderItems: OrderItem[];
  amounts: OrderAmount[];
  totals?: {
    totalGross?: number;
  };
}

/** A Wallee payment method available for the order. */
interface PaymentMethod {
  id: number | string;
  name: string;
  description?: string;
  icon?: string;
}

/** Response shape of walleeGetOrderCheckoutData. */
interface CheckoutDataResponse {
  error?: string;
  allowRetry: boolean;
  currentPaymentMethodId?: number | string;
  paymentMethods?: PaymentMethod[];
  orderData?: OrderData;
}

/** Response shape of walleePayOrderRest. */
interface PayOrderResponse {
  redirectUrl?: string;
  error?: string;
  status?: 'continue';
}

/** Order item typeId that identifies a regular product line item (as opposed to shipping, coupons, etc.). */
const PRODUCT_ITEM_TYPE_ID = 1;

/** Delay before redirecting the customer away when the page can't proceed. */
const REDIRECT_DELAY_MS = 3000;

const route = useRoute();
const router = useRouter();

const orderId = ref<string>('');
const isLoading = ref<boolean>(true);
const isSubmitting = ref<boolean>(false);
const errorMessage = ref<string>('');
const submitError = ref<string>('');
const orderData = ref<OrderData | null>(null);
const paymentMethods = ref<PaymentMethod[]>([]);
const selectedPaymentMethod = ref<string>('');

/**
 * Filter order items to only show product items (typeId 1) in the summary.
 * Shipping, coupons, and other item types are excluded from the display.
 */
const productItems = computed(() => {
  if (!orderData.value?.orderItems) {
    return [];
  }
  return orderData.value.orderItems.filter((item: OrderItem) => item.typeId === PRODUCT_ITEM_TYPE_ID);
});

/**
 * Find the non-system currency amount (the customer's purchase currency),
 * falling back to the first amount entry if none is marked as such.
 * We avoid displaying the default store system currency (e.g. CHF) instead
 * of the order currency (e.g. GBP).
 */
const customerAmount = computed<OrderAmount | undefined>(() => {
  if (!orderData.value?.amounts?.length) {
    return undefined;
  }
  const nonSystemAmount = orderData.value.amounts.find(
    (amount: OrderAmount) => amount.isSystemCurrency === false || amount.isSystemCurrency === 0 || amount.isSystemCurrency === 'false'
  );
  return nonSystemAmount || orderData.value.amounts[0];
});

/**
 * Extract the order currency for formatting.
 */
const orderCurrency = computed(() => customerAmount.value?.currency || 'EUR');

/**
 * Get the total gross amount of the order in the customer's selected currency.
 */
const orderTotalGross = computed(() => {
  return customerAmount.value?.grossTotal ?? (orderData.value?.totals?.totalGross || 0);
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
    errorMessage.value = texts.value.errorNoOrder;
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

    const responseData: CheckoutDataResponse = result?.data || result;

    if (!responseData || responseData.error) {
      errorMessage.value = responseData?.error || texts.value.errorLoadFailed;
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
      errorMessage.value = texts.value.errorRetryNotAllowed;
      isLoading.value = false;
      redirectAway();
      return;
    }

    orderData.value = responseData.orderData ?? null;
    paymentMethods.value = responseData.paymentMethods || [];

    // Pre-select the current payment method if it's in the list
    if (responseData.currentPaymentMethodId) {
      const currentId = String(responseData.currentPaymentMethodId);
      const exists = paymentMethods.value.some(
        (m: PaymentMethod) => String(m.id) === currentId,
      );
      if (exists) {
        selectedPaymentMethod.value = currentId;
      }
    }

    // If only one method is available, auto-select it
    if (!selectedPaymentMethod.value && paymentMethods.value.length === 1) {
      selectedPaymentMethod.value = String(paymentMethods.value[0].id);
    }
  } catch (err: unknown) {
    console.error('[wallee] Failed to load order checkout data:', err);
    errorMessage.value = texts.value.errorLoadGeneric;
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
    router.replace(props.shopPath);
  }, REDIRECT_DELAY_MS);
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

    const responseData: PayOrderResponse = result?.data || result;

    if (responseData?.redirectUrl) {
      // Navigate to the Wallee payment page
      window.location.href = responseData.redirectUrl;
      return;
    }

    if (responseData?.error) {
      submitError.value = responseData.error;
    } else if (responseData?.status === 'continue') {
      // Payment method doesn't require redirect, send user to confirmation
      router.replace(props.confirmationPath.replace('{orderId}', orderId.value));
    } else {
      submitError.value = texts.value.errorUnexpectedResponse;
    }
  } catch (err: unknown) {
    console.error('[wallee] Payment retry failed:', err);
    submitError.value = texts.value.errorSubmitFailed;
  } finally {
    isSubmitting.value = false;
  }
}
</script>

<style scoped>
/*
 * Theming
 * -------
 * This component can be restyled by a host app without touching this file.
 * Every color, radius and font declared below falls back to the defaults
 * shown here, but can be overridden by setting the matching CSS custom
 * property on any ancestor element (e.g. `:root`, `body` or a wrapper
 * around this component):
 *
 *   :root {
 *     --wallee-color-primary: #ff6600;
 *     --wallee-font-family: 'Inter', sans-serif;
 *   }
 *
 * Color palette:
 *   --wallee-color-primary           accent color (selected state, buttons, radios, spinner)
 *   --wallee-color-primary-hover     submit button hover background
 *   --wallee-color-primary-disabled  submit button disabled background
 *   --wallee-color-primary-bg        background tint for the selected payment method
 *   --wallee-color-heading           primary heading text color
 *   --wallee-color-text              default body text color
 *   --wallee-color-text-secondary    secondary heading/text color
 *   --wallee-color-text-muted        muted/secondary text (e.g. quantities, descriptions)
 *   --wallee-color-text-faint        faint text (e.g. redirect notice)
 *   --wallee-color-text-light        light text (e.g. loading state)
 *   --wallee-color-border            default border color
 *   --wallee-color-border-strong     stronger border/divider color
 *   --wallee-color-border-hover      border color on hover (cancel button)
 *   --wallee-color-surface           surface background (order summary box)
 *   --wallee-color-error-bg          submission error background
 *   --wallee-color-error-border      submission error border
 *   --wallee-color-error-text        submission error text color
 *
 * Layout & typography:
 *   --wallee-font-family             base font family
 *   --wallee-max-width                max width of the component
 *   --wallee-radius                  default border radius (cards, buttons)
 *   --wallee-radius-sm               smaller border radius (error box)
 *   --wallee-payment-icon-width      payment method icon width
 *   --wallee-payment-icon-height     payment method icon height
 */
.wallee-payment-selection {
  max-width: var(--wallee-max-width, 600px);
  margin: 40px auto;
  padding: 0 20px;
  font-family: var(--wallee-font-family, system-ui, -apple-system, sans-serif);
}

/* Loading */
.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 60vh;
  text-align: center;
  color: var(--wallee-color-text-light, #666);
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
  color: var(--wallee-color-text-secondary, #333);
  margin-bottom: 8px;
}
.error-container p {
  color: var(--wallee-color-text-light, #666);
  margin-bottom: 4px;
}
.redirect-notice {
  font-style: italic;
  font-size: 14px;
  color: var(--wallee-color-text-faint, #999);
  margin-top: 12px;
}

/* Payment container */
.payment-container h1 {
  font-size: 24px;
  color: var(--wallee-color-heading, #1a1a1a);
  margin-bottom: 8px;
}
.subtitle {
  color: var(--wallee-color-text, #555);
  margin-bottom: 28px;
  line-height: 1.5;
}

/* Order summary */
.order-summary {
  background: var(--wallee-color-surface, #f8f9fa);
  border: 1px solid var(--wallee-color-border, #e9ecef);
  border-radius: var(--wallee-radius, 8px);
  padding: 20px;
  margin-bottom: 28px;
}
.order-summary h2 {
  font-size: 16px;
  color: var(--wallee-color-text-secondary, #333);
  margin-bottom: 12px;
}
.order-item {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 14px;
  color: var(--wallee-color-text, #555);
}
.item-name {
  flex: 1;
  margin-right: 12px;
}
.item-qty {
  white-space: nowrap;
  color: var(--wallee-color-text-muted, #888);
}
.order-total {
  display: flex;
  justify-content: space-between;
  border-top: 1px solid var(--wallee-color-border-strong, #dee2e6);
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
  color: var(--wallee-color-text-secondary, #333);
  margin-bottom: 12px;
}
.payment-method-option {
  border: 2px solid var(--wallee-color-border, #e9ecef);
  border-radius: var(--wallee-radius, 8px);
  margin-bottom: 10px;
  transition: border-color 0.15s ease;
}
.payment-method-option.selected {
  border-color: var(--wallee-color-primary, #0d6efd);
  background: var(--wallee-color-primary-bg, #f0f6ff);
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
  accent-color: var(--wallee-color-primary, #0d6efd);
}
.payment-icon {
  width: var(--wallee-payment-icon-width, 40px);
  height: var(--wallee-payment-icon-height, 28px);
  object-fit: contain;
  flex-shrink: 0;
}
.payment-info {
  display: flex;
  flex-direction: column;
}
.payment-name {
  font-weight: 500;
  color: var(--wallee-color-heading, #1a1a1a);
}
.payment-desc {
  font-size: 13px;
  color: var(--wallee-color-text-muted, #888);
  margin-top: 2px;
}

/* Submit button */
.submit-button {
  width: 100%;
  padding: 14px;
  background: var(--wallee-color-primary, #0d6efd);
  color: white;
  border: none;
  border-radius: var(--wallee-radius, 8px);
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
  background: var(--wallee-color-primary-hover, #0b5ed7);
}
.submit-button:disabled {
  background: var(--wallee-color-primary-disabled, #b0c4de);
  cursor: not-allowed;
}

/* Cancel / back button */
.cancel-button {
  width: 100%;
  margin-top: 10px;
  padding: 12px;
  background: transparent;
  color: var(--wallee-color-text, #555);
  border: 2px solid var(--wallee-color-border-strong, #dee2e6);
  border-radius: var(--wallee-radius, 8px);
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  transition: border-color 0.15s ease, color 0.15s ease;
}
.cancel-button:hover {
  border-color: var(--wallee-color-border-hover, #adb5bd);
  color: var(--wallee-color-text-secondary, #333);
}

/* Submission error */
.submit-error {
  margin-top: 12px;
  padding: 12px 16px;
  background: var(--wallee-color-error-bg, #fff3f3);
  border: 1px solid var(--wallee-color-error-border, #f5c6cb);
  border-radius: var(--wallee-radius-sm, 6px);
  color: var(--wallee-color-error-text, #842029);
  font-size: 14px;
}

/* Spinners */
.spinner {
  border: 4px solid rgba(0, 0, 0, 0.1);
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border-left-color: var(--wallee-color-primary, #0d6efd);
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
