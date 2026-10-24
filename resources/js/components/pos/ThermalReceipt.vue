<script setup lang="ts">
import { computed } from 'vue';
import type { ThermalLine } from '@/lib/receipt/layout';

/**
 * The thermal receipt as the printer prints it (docs/PHARMACY.md §9,
 * branch 9): the lines of ReceiptLayout (server) or lib/receipt/layout.ts
 * (offline), in a monospaced font with exactly `columns` characters across
 * the paper's printable width (72 mm on 80 mm paper, 48 mm on 58 mm), so the
 * browser's print and an ESC/POS printer look the same. Double-height lines
 * are stretched, not widened, as the printer does.
 */
const props = defineProps<{
    lines: ThermalLine[];
    columns: number;
    paperWidth: number;
    logoUrl?: string | null;
}>();

const style = computed(() => ({
    // Font A of an ESC/POS printer: 1.5 mm a character (0.6 em in Courier).
    fontSize: '2.5mm',
    width: `${props.columns}ch`,
}));
</script>

<template>
    <article
        class="receipt thermal-receipt mx-auto bg-white text-black"
        :style="{ width: `${paperWidth}mm` }"
        data-test="receipt"
    >
        <img
            v-if="logoUrl"
            :src="logoUrl"
            alt=""
            class="mx-auto mb-1 block max-h-16 max-w-[50%] grayscale"
        />
        <div
            class="thermal-lines mx-auto"
            :style="style"
            data-test="thermal-lines"
        >
            <div
                v-for="(line, index) in lines"
                :key="index"
                :class="{ 'font-bold': line.bold, 'thermal-tall': line.tall }"
            >
                {{ line.text || '\u00a0' }}
            </div>
        </div>
    </article>
</template>

<style>
.thermal-receipt {
    padding: 4mm 0;
}

.thermal-lines {
    font-family:
        'Courier New', 'DejaVu Sans Mono', 'Liberation Mono', monospace;
    line-height: 1.25;
    white-space: pre;
    overflow: hidden;
}

/* Double height, same width (ESC/POS GS ! 0x01). */
.thermal-tall {
    height: 2.5em;
    transform: scaleY(2);
    transform-origin: top;
}
</style>
