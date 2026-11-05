<script setup lang="ts">
import { computed } from 'vue';
import type { ThermalLine } from '@/lib/receipt/layout';

/**
 * The thermal receipt as the printer prints it (docs/PHARMACY.md §9,
 * branch 9): the lines of ReceiptLayout (server) or lib/receipt/layout.ts
 * (offline), in a monospaced font with exactly `columns` characters across
 * the paper's printable width (72 mm on 80 mm paper, 48 mm on 58 mm). Double-
 * height lines are stretched, not widened, as the printer does.
 *
 * Large print (a pharmacy, ReceiptLayout): 12.6 px at 36 columns, in the
 * bundled Liberation Mono, whose strokes a thermal head prints solid where
 * Courier New's thin ones come out as sparse dots. Everything is pure black;
 * the logo arrives as black and white (lib/receipt/logo.ts).
 */
const props = defineProps<{
    lines: ThermalLine[];
    columns: number;
    paperWidth: number;
    logoUrl?: string | null;
    largePrint?: boolean;
}>();

const printableWidth = computed(() => (props.paperWidth === 58 ? 48 : 72));

const style = computed(() => ({
    // A monospaced character is 0.6 em wide. At 48 columns that is Font A of an
    // ESC/POS printer: 1.5 mm a character.
    fontSize: `${Math.round((printableWidth.value / props.columns / 0.6) * 1000) / 1000}mm`,
    width: `${props.columns}ch`,
}));
</script>

<template>
    <article
        class="receipt thermal-receipt mx-auto bg-white text-black"
        :class="{ 'thermal-large': largePrint }"
        :style="{ width: `${paperWidth}mm` }"
        data-test="receipt"
    >
        <img
            v-if="logoUrl"
            :src="logoUrl"
            alt=""
            class="thermal-logo mx-auto mb-1 block"
            :class="{ 'max-h-16 max-w-[50%] grayscale': !largePrint }"
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
/* Unmodified Liberation Mono (SIL OFL), resources/fonts/liberation-mono/LICENSE.txt. */
@font-face {
    font-family: 'POS Receipt Mono';
    src: url('../../../fonts/liberation-mono/LiberationMono-Regular.ttf')
        format('truetype');
    font-weight: 400;
    font-style: normal;
    font-display: block;
}

@font-face {
    font-family: 'POS Receipt Mono';
    src: url('../../../fonts/liberation-mono/LiberationMono-Bold.ttf')
        format('truetype');
    font-weight: 700;
    font-style: normal;
    font-display: block;
}

.thermal-receipt {
    padding: 4mm 0;
}

/* A thermal head has no grey: nothing on the receipt may be anything but black. */
.thermal-large,
.thermal-large * {
    color: #000 !important;
    opacity: 1 !important;
}

.thermal-large .thermal-lines {
    font-family:
        'POS Receipt Mono', 'Liberation Mono', 'Courier New', monospace;
    font-synthesis: none;
}

/* The image has the head's own dots: 288 x 160 at most, 8 dots a mm. */
.thermal-large .thermal-logo {
    max-width: 36mm;
    max-height: 20mm;
    image-rendering: pixelated;
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
