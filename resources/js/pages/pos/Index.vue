<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    FileText,
    History,
    Inbox,
    LayoutDashboard,
    LogOut,
    MapPin,
    Receipt,
    Repeat,
    Wallet,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import CheckoutController from '@/actions/App/Http/Controllers/Pos/CheckoutController';
import StockRequestController from '@/actions/App/Http/Controllers/Pos/StockRequestController';
import TillController from '@/actions/App/Http/Controllers/Pos/TillController';
import CartPanel from '@/components/pos/CartPanel.vue';
import CloseShiftDialog from '@/components/pos/CloseShiftDialog.vue';
import DiscountDialog from '@/components/pos/DiscountDialog.vue';
import ItemSearch from '@/components/pos/ItemSearch.vue';
import OfflineStatus from '@/components/pos/OfflineStatus.vue';
import PaymentDialog from '@/components/pos/PaymentDialog.vue';
import PrescriptionDialog from '@/components/pos/PrescriptionDialog.vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import ThermalReceipt from '@/components/pos/ThermalReceipt.vue';
import RecentSalesDialog from '@/components/pos/RecentSalesDialog.vue';
import SaleDoneDialog from '@/components/pos/SaleDoneDialog.vue';
import ShiftOpenPanel from '@/components/pos/ShiftOpenPanel.vue';
import TillPicker from '@/components/pos/TillPicker.vue';
import TotalsPanel from '@/components/pos/TotalsPanel.vue';
import { Button } from '@/components/ui/button';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { usePosCart } from '@/composables/usePosCart';
import { ApiError, postJson } from '@/lib/http';
import type { OfflineReceipt, OfflineShop } from '@/lib/offline/db';
import { encodeEscPos } from '@/lib/receipt/escpos';
import { columns, saleLayout } from '@/lib/receipt/layout';
import { receiptPrinter } from '@/lib/receiptPrinter';
import { logout } from '@/routes';
import type {
    CompletedSale,
    PosCashier,
    PosFeatures,
    PosItem,
    PosPharmacy,
    PosShift,
    PosShop,
    PosTill,
    PosTillOption,
} from '@/types/pos';

const props = withDefaults(
    defineProps<{
        shop: PosShop;
        cashier: PosCashier;
        features: PosFeatures;
        offline: { enabled: boolean; canRegister: boolean };
        tills: PosTillOption[];
        till: PosTill | null;
        shift: PosShift | null;
        /** Null when the pharmacy module is off. */
        pharmacy?: PosPharmacy;
    }>(),
    { pharmacy: null },
);

const cart = usePosCart();
const { state } = cart;
const offlinePos = useOfflinePos();

/** Receipt of a sale made offline, printed from this browser. */
const offlineReceipt = ref<{
    shop: OfflineShop;
    sale: OfflineReceipt;
} | null>(null);

/**
 * A pharmacy prints the offline receipt in the thermal layout, as the server
 * would (lib/receipt/layout.ts mirrors ReceiptLayout); retail keeps its HTML
 * receipt.
 */
const offlineThermal = computed(() => {
    const receipt = offlineReceipt.value;

    if (receipt === null || !receipt.shop.pharmacy) {
        return null;
    }

    const paperWidth = receipt.shop.receipt?.paperWidth === 58 ? 58 : 80;

    return {
        paperWidth,
        columns: columns(paperWidth),
        lines: saleLayout(receipt.shop, receipt.sale),
    };
});

const search = ref<InstanceType<typeof ItemSearch> | null>(null);
const totals = ref<InstanceType<typeof TotalsPanel> | null>(null);
const paymentOpen = ref(false);
const discountOpen = ref(false);
const closeShiftOpen = ref(false);
const recentSalesOpen = ref(false);
const completed = ref<CompletedSale | null>(null);
const submitting = ref(false);
const checkoutError = ref<string | null>(null);
const sellWithoutShift = ref(false);

/** The Prescription dialog (F7), and why the till opened it. */
const rxOpen = ref(false);
const rxMessage = ref<string | null>(null);
const rxToPayment = ref(false);
/** The sale (cart uuid) already prompted for a prescriber (rx_prescriber 'prompt'). */
const rxPromptedFor = ref<string | null>(null);
/** Open payment once the quote catches up with what the Prescription dialog changed. */
const payWhenQuoted = ref(false);

const pharmacyOn = computed(() => !!props.features.pharmacy);
// A cashier whose discount limit is 0 sees no Discount button (the server refuses one anyway).
const canDiscount = computed(() => props.cashier.canDiscount ?? true);
/** The till rings up OPD bills and this cashier may: every new cart starts as one. */
const opdTill = computed(
    () => !!props.till?.opdBilling && !!props.cashier.canOpdBill,
);
const opdBill = computed(() => state.billType === 'opd');

/** Controlled-drug register on: a controlled line needs the patient's name (the server checks too). */
const patientLine = computed(() =>
    props.pharmacy?.controlledRegister
        ? (state.lines.find((line) => line.drug?.schedule === 'controlled') ??
          null)
        : null,
);
const patientError = computed(() =>
    patientLine.value && state.customerName.trim() === ''
        ? `Enter the patient's name for ${patientLine.value.sku} (controlled drug).`
        : null,
);
/** What stops payment for the prescription: a required prescriber, a controlled drug's patient. */
const rxBlocking = computed(
    () => cart.prescriptionError.value ?? patientError.value,
);

const needsShift = computed(
    () =>
        props.shift === null &&
        (props.shop.requireOpenShift || !sellWithoutShift.value),
);
const dialogOpen = computed(
    () =>
        paymentOpen.value ||
        discountOpen.value ||
        closeShiftOpen.value ||
        recentSalesOpen.value ||
        rxOpen.value ||
        completed.value !== null,
);

function focusSearch(): void {
    void nextTick(() => search.value?.focus());
}

// Whenever the last dialog closes, the search box takes the focus back for the scanner.
watch(dialogOpen, (open) => {
    if (!open) {
        focusSearch();
    }
});

function openPayment(): void {
    if (
        state.lines.length > 0 &&
        state.quote &&
        !state.stale &&
        !state.quoteError
    ) {
        if (pharmacyOn.value && askForPrescription()) {
            return;
        }

        checkoutError.value = null;
        paymentOpen.value = true;
    }
}

/**
 * Before payment (pharmacy): a required prescriber or a controlled drug's
 * patient opens the Prescription dialog instead; with rx_prescriber
 * 'prompt' it opens once per sale and the cashier may continue without.
 * Returns true when it opened the dialog.
 */
function askForPrescription(): boolean {
    if (rxBlocking.value !== null) {
        openPrescription(rxBlocking.value, true);

        return true;
    }

    const unfilled = state.lines.find(
        (line) => cart.isRxLine(line) && cart.prescriberFor(line) === null,
    );

    if (
        props.pharmacy?.rxPrescriber === 'prompt' &&
        unfilled &&
        rxPromptedFor.value !== state.uuid
    ) {
        rxPromptedFor.value = state.uuid;
        openPrescription(
            `${unfilled.sku} is prescription only. Enter the prescriber if you have one, or continue without.`,
            true,
        );

        return true;
    }

    return false;
}

function openPrescription(message: string | null, toPayment: boolean): void {
    rxMessage.value = message;
    rxToPayment.value = toPayment;
    rxOpen.value = true;
}

function continueFromPrescription(): void {
    rxOpen.value = false;

    if (!rxToPayment.value) {
        return;
    }

    // What was typed re-prices the cart (prescribers go with the lines): pay on the fresh quote.
    if (state.stale) {
        payWhenQuoted.value = true;
    } else {
        openPayment();
    }
}

watch(
    () => [state.stale, state.quoteError] as const,
    ([stale, error]) => {
        if (error !== null) {
            payWhenQuoted.value = false;
        } else if (!stale && payWhenQuoted.value) {
            payWhenQuoted.value = false;
            openPayment();
        }
    },
);

/** Adds a found or scanned item; the search box says why when the till refuses it. */
function addItem(item: PosItem): void {
    const refused = cart.addItem(item);

    if (refused !== null) {
        search.value?.showNotice(refused);
    }
}

/** An OPD till: this sale as an itemised bill, or back to an OPD bill. */
function toggleBillType(): void {
    if (opdTill.value) {
        cart.setBillType(opdBill.value ? 'standard' : 'opd');
    }
}

async function completeSale(): Promise<void> {
    if (props.till === null || submitting.value) {
        return;
    }

    submitting.value = true;
    checkoutError.value = null;

    try {
        if (offlinePos.isOffline.value && offlinePos.canSellOffline.value) {
            await completeOffline();

            return;
        }

        // Same cart uuid on every attempt: a double click or a retry cannot record the sale twice.
        const response = await postJson<{ sale: CompletedSale }>(
            CheckoutController.url(),
            cart.checkoutBody(props.till.uuid),
        );

        offlinePos.reportConnection(true);
        offlineReceipt.value = null;
        completed.value = response.sale;
        paymentOpen.value = false;
    } catch (error) {
        if (
            error instanceof ApiError &&
            error.status === 0 &&
            offlinePos.canSellOffline.value
        ) {
            // The server could not be reached. The sale keeps its uuid, so if the
            // request did get through, the sync recognises it and records it once.
            offlinePos.reportConnection(false);

            try {
                await completeOffline();
            } catch (offlineError) {
                checkoutError.value = errorMessage(offlineError);
            }

            return;
        }

        checkoutError.value = errorMessage(error);
    } finally {
        submitting.value = false;
    }
}

function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return error.status === 0 && !offlinePos.canSellOffline.value
            ? 'The server cannot be reached and offline sales are not set up on this till.'
            : error.message;
    }

    return error instanceof Error
        ? error.message
        : 'The sale could not be recorded.';
}

/** Save the sale in this till's outbox and show its receipt straight away. */
async function completeOffline(): Promise<void> {
    const shop = await offlinePos.shop();

    if (shop === null) {
        throw new Error('The offline catalog is not ready yet.');
    }

    const { build } = await cart.offlineSale();
    const sale = await offlinePos.recordSale(build, shop.invoicePadding);

    // The till and its location for the receipt's header (not kept in the outbox).
    offlineReceipt.value = {
        shop,
        sale: {
            ...sale.receipt,
            till: props.till
                ? {
                      code: props.till.code,
                      name: props.till.name,
                      location: props.till.location?.name ?? null,
                  }
                : null,
        },
    };
    completed.value = {
        uuid: sale.uuid,
        invoice_no: sale.receipt.invoiceNo,
        grand_total: sale.receipt.grandTotal,
        change_due: sale.receipt.changeDue,
        receipt_url: null,
    };
    paymentOpen.value = false;
}

function printOfflineReceipt(): void {
    const browserPrint = () => {
        document.body.classList.add('printing-receipt');
        window.addEventListener(
            'afterprint',
            () => document.body.classList.remove('printing-receipt'),
            { once: true },
        );
        void nextTick(() => window.print());
    };

    void receiptPrinter().print({
        escpos: offlineThermal.value
            ? encodeEscPos(offlineThermal.value.lines)
            : null,
        browserPrint,
    });
}

/** Unsynced sales live only in this browser, so make signing out a deliberate choice. */
async function confirmLeaving(action: string): Promise<boolean> {
    const unsynced = await offlinePos.unsyncedSaleCount();

    return (
        unsynced === 0 ||
        window.confirm(
            `${unsynced === 1 ? '1 sale has' : `${unsynced} sales have`} not reached the server yet. ` +
                `They stay saved on this till and sync the next time it is online here. ${action} anyway?`,
        )
    );
}

async function signOut(): Promise<void> {
    if (!(await confirmLeaving('Sign out'))) {
        return;
    }

    // The cached checkout page holds this cashier's details; drop it on sign-out.
    await window.caches?.delete('pos-pages').catch(() => false);
    router.post(logout().url);
}

function startNewSale(): void {
    completed.value = null;
    cart.newCart();
    focusSearch();
}

async function changeTill(): Promise<void> {
    if (await confirmLeaving('Change till')) {
        router.delete(TillController.destroy.url());
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (props.till === null || needsShift.value) {
        return;
    }

    if (event.key === 'F2') {
        event.preventDefault();
        search.value?.focus();
    } else if (event.key === 'F4') {
        event.preventDefault();
        if (
            state.lines.length > 0 &&
            !paymentOpen.value &&
            !opdBill.value &&
            canDiscount.value
        ) {
            discountOpen.value = true;
        }
    } else if (event.key === 'F3' && opdTill.value) {
        event.preventDefault();
        if (!dialogOpen.value) {
            toggleBillType();
        }
    } else if (event.key === 'F7' && pharmacyOn.value) {
        event.preventDefault();
        if (!dialogOpen.value) {
            openPrescription(null, false);
        }
    } else if (event.key === 'F8' && opdBill.value) {
        event.preventDefault();
        if (!dialogOpen.value) {
            totals.value?.focusMedicines();
        }
    } else if (event.key === 'F6') {
        event.preventDefault();
        if (!dialogOpen.value) {
            recentSalesOpen.value = true;
        }
    } else if (
        event.key === 'F9' ||
        (event.key === 'Enter' && (event.ctrlKey || event.metaKey))
    ) {
        event.preventDefault();
        if (!dialogOpen.value) {
            openPayment();
        }
    }
}

// If something steals focus (a tap on empty space), hand it back to the search box.
function refocusIfIdle(): void {
    setTimeout(() => {
        if (!dialogOpen.value && document.activeElement === document.body) {
            search.value?.focus();
        }
    }, 0);
}

// Tell the cart which till it is on (before it starts): the quote is priced
// for the till's location, and an OPD till starts every cart as an OPD bill.
watch(
    () => ({
        deviceUuid: props.till?.uuid ?? null,
        locationId: props.till?.location?.id ?? null,
        pharmacy: pharmacyOn.value,
        opdBilling: opdTill.value,
        defaultPrescriber: props.pharmacy?.defaultPrescriber ?? null,
        rxPrescriber: props.pharmacy?.rxPrescriber ?? 'prompt',
        opdConsultation: props.pharmacy?.opdConsultation ?? null,
    }),
    (context) => cart.configure(context),
    { immediate: true, deep: true },
);

watch(
    () => [props.till?.uuid, props.till?.location?.id] as const,
    ([tillUuid, locationId]) => {
        if (tillUuid) {
            void offlinePos.start(
                tillUuid,
                props.offline.enabled,
                {
                    id: props.cashier.id,
                    name: props.cashier.name,
                    unlimitedDiscount: props.cashier.unlimitedDiscount,
                    canOverridePrice: props.cashier.canOverridePrice ?? false,
                    canBatchOverride: props.cashier.canBatchOverride ?? false,
                    canOpdBill: props.cashier.canOpdBill ?? false,
                },
                locationId ?? null,
            );
        } else {
            offlinePos.stop();
        }
    },
    { immediate: true },
);

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    document.addEventListener('focusout', refocusIfIdle);

    // The app shell (this page, scripts, styles, fonts) is cached so /pos opens without internet.
    if (import.meta.env.PROD && 'serviceWorker' in navigator) {
        void navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch(() => undefined);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('focusout', refocusIfIdle);
    offlinePos.stop();
});
</script>

<template>
    <Head title="Checkout" />

    <div class="flex h-dvh flex-col bg-background text-foreground">
        <div
            v-if="offlineReceipt"
            class="pos-print-receipt hidden print:block"
            aria-hidden="true"
        >
            <ThermalReceipt
                v-if="offlineThermal"
                :lines="offlineThermal.lines"
                :columns="offlineThermal.columns"
                :paper-width="offlineThermal.paperWidth"
            />
            <ReceiptView
                v-else
                :shop="offlineReceipt.shop"
                :sale="offlineReceipt.sale"
            />
        </div>

        <header
            class="flex min-h-14 shrink-0 flex-wrap items-center gap-x-4 gap-y-1 border-b px-4 py-2"
        >
            <h1 class="text-lg font-bold">{{ shop.name }}</h1>
            <span
                v-if="till"
                class="rounded-md bg-primary px-2.5 py-1 text-sm font-bold text-primary-foreground"
                data-test="till-code"
            >
                {{ till.code }}
            </span>
            <span
                v-if="till?.location"
                class="flex items-center gap-1 text-sm font-medium"
                data-test="till-location"
                ><MapPin class="size-4 text-muted-foreground" />
                {{ till.location.name }}</span
            >
            <span
                class="text-sm text-muted-foreground"
                data-test="cashier-name"
                >{{ cashier.name }}</span
            >
            <span
                v-if="shift"
                class="text-sm text-emerald-600 dark:text-emerald-400"
                >Shift open</span
            >

            <OfflineStatus v-if="till" :can-register="offline.canRegister" />

            <div class="ml-auto flex flex-wrap items-center gap-1">
                <Button
                    v-if="till && !needsShift"
                    variant="outline"
                    class="h-11"
                    data-test="recent-sales-button"
                    title="Recent sales (F6)"
                    :disabled="offlinePos.isOffline.value"
                    @click="recentSalesOpen = true"
                >
                    <History class="size-4" /> Recent sales
                    <kbd class="text-xs text-muted-foreground">F6</kbd>
                </Button>
                <Button
                    v-if="till && shift"
                    variant="outline"
                    class="h-11"
                    data-test="close-shift-button"
                    :disabled="
                        offlinePos.isOffline.value ||
                        offlinePos.state.pending > 0
                    "
                    :title="
                        offlinePos.isOffline.value ||
                        offlinePos.state.pending > 0
                            ? 'Sync this till\'s offline sales before closing the shift'
                            : undefined
                    "
                    @click="closeShiftOpen = true"
                >
                    <Wallet class="size-4" /> Close shift
                </Button>
                <Button
                    v-if="till"
                    variant="ghost"
                    class="h-11"
                    @click="changeTill"
                    ><Repeat class="size-4" /> Change till</Button
                >
                <Button
                    v-if="
                        cashier.canRequestStock && !offlinePos.isOffline.value
                    "
                    variant="ghost"
                    class="h-11"
                    data-test="stock-requests-link"
                    as-child
                >
                    <a :href="StockRequestController.index.url()"
                        ><Inbox class="size-4" /> Stock requests</a
                    >
                </Button>
                <Button
                    v-if="cashier.canAdmin"
                    variant="ghost"
                    class="h-11"
                    as-child
                >
                    <a href="/admin"
                        ><LayoutDashboard class="size-4" /> Admin</a
                    >
                </Button>
                <Button
                    variant="ghost"
                    class="h-11"
                    data-test="sign-out"
                    @click="signOut"
                >
                    <LogOut class="size-4" /> Sign out
                </Button>
            </div>
        </header>

        <TillPicker v-if="!till" :tills="tills" />

        <ShiftOpenPanel
            v-else-if="needsShift"
            :till="till"
            :shop="shop"
            @skip="sellWithoutShift = true"
        />

        <main
            v-else
            class="grid min-h-0 flex-1 gap-4 p-3 md:grid-cols-2 md:p-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]"
        >
            <section
                class="flex min-h-0 flex-col gap-3"
                aria-label="Find items"
            >
                <p
                    v-if="!shift"
                    class="rounded-lg bg-amber-500/10 p-2 text-sm text-amber-700 dark:text-amber-400"
                >
                    No shift is open, so this sale will not count towards a
                    drawer.
                    <button
                        type="button"
                        class="font-semibold underline"
                        @click="sellWithoutShift = false"
                    >
                        Open a shift
                    </button>
                </p>
                <ItemSearch
                    ref="search"
                    :device-uuid="till.uuid"
                    :decimals="shop.decimals"
                    :currency="shop.currency"
                    :vehicle-fitment="features.vehicleFitment"
                    :pharmacy="pharmacyOn"
                    :opd-bill="opdBill"
                    @add="addItem"
                />
            </section>

            <section
                class="flex min-h-0 flex-col gap-2 rounded-2xl border bg-muted/30 p-3"
                aria-label="Cart"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 px-1"
                >
                    <h2
                        class="flex items-center gap-2 text-lg font-bold"
                        data-test="sale-title"
                    >
                        <Receipt class="size-5" />
                        {{ opdBill ? 'OPD bill' : 'Current sale' }}
                    </h2>
                    <div class="flex flex-wrap items-center gap-1">
                        <Button
                            v-if="opdTill"
                            variant="outline"
                            class="h-10"
                            data-test="bill-type-toggle"
                            @click="toggleBillType"
                        >
                            <ArrowLeftRight class="size-4" />
                            {{ opdBill ? 'Itemised bill' : 'OPD bill' }}
                            <kbd class="text-xs text-muted-foreground">F3</kbd>
                        </Button>
                        <Button
                            v-if="pharmacyOn && cart.hasRxLines.value"
                            :variant="rxBlocking ? 'destructive' : 'outline'"
                            class="h-10"
                            data-test="prescription-button"
                            @click="openPrescription(null, false)"
                        >
                            <FileText class="size-4" /> Prescription
                            <kbd class="text-xs opacity-80">F7</kbd>
                        </Button>
                        <span class="text-sm text-muted-foreground"
                            >{{ cart.itemCount.value }} line(s)</span
                        >
                    </div>
                </div>

                <CartPanel
                    :lines="state.lines"
                    :quote="state.quote"
                    :stale="state.stale"
                    :decimals="shop.decimals"
                    :currency="shop.currency"
                    :pharmacy="pharmacyOn"
                    :opd-bill="opdBill"
                    :can-batch-override="cashier.canBatchOverride ?? false"
                    :can-override-price="cashier.canOverridePrice ?? false"
                    :can-discount="canDiscount"
                    @step="cart.step"
                    @quantity="cart.setQuantity"
                    @discount="(line, value) => (line.lineDiscount = value)"
                    @remove="cart.removeLine"
                />

                <TotalsPanel
                    ref="totals"
                    :quote="state.quote"
                    :error="state.quoteError"
                    :stale="state.stale"
                    :has-lines="state.lines.length > 0"
                    :decimals="shop.decimals"
                    :currency="shop.currency"
                    :opd-bill="opdBill"
                    :below-list-percent="
                        props.pharmacy?.opdBelowListWarningPercent ?? 100
                    "
                    @pay="openPayment"
                    :can-discount="canDiscount"
                    @discount="discountOpen = true"
                    @clear="cart.newCart()"
                    @medicines-done="focusSearch"
                />
            </section>
        </main>

        <DiscountDialog v-model:open="discountOpen" :shop="shop" />
        <PaymentDialog
            v-model:open="paymentOpen"
            :shop="shop"
            :submitting="submitting"
            :error="checkoutError"
            :reference-required="
                pharmacy?.bankTransferReferenceRequired ?? false
            "
            @complete="completeSale"
        />
        <PrescriptionDialog
            v-if="pharmacyOn"
            v-model:open="rxOpen"
            :message="rxMessage"
            :blocking="rxBlocking"
            :needs-patient="patientLine !== null"
            :to-payment="rxToPayment"
            @continue="continueFromPrescription"
        />
        <SaleDoneDialog
            :sale="completed"
            :shop="shop"
            @close="startNewSale"
            @print-offline="printOfflineReceipt"
        />
        <RecentSalesDialog
            v-if="till"
            v-model:open="recentSalesOpen"
            :till="till"
            :shop="shop"
        />
        <CloseShiftDialog
            v-if="till"
            v-model:open="closeShiftOpen"
            :till="till"
            :shop="shop"
        />
    </div>
</template>

<style>
@media print {
    body.printing-receipt * {
        visibility: hidden;
    }

    body.printing-receipt .pos-print-receipt,
    body.printing-receipt .pos-print-receipt * {
        visibility: visible;
    }

    body.printing-receipt .pos-print-receipt {
        position: absolute;
        top: 0;
        left: 0;
    }

    body.printing-receipt .receipt:not(.thermal-receipt) {
        width: 80mm !important;
        margin: 0 !important;
        padding: 2mm 3mm !important;
    }
}
</style>
