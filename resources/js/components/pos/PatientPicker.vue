<script setup lang="ts">
import { Plus, UserRound, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import PatientSearchController from '@/actions/App/Http/Controllers/Pos/PatientSearchController';
import { Button } from '@/components/ui/button';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { getJson } from '@/lib/http';
import { searchPatients } from '@/lib/offline/patients';
import { formatAge, parseAge } from '@/lib/patientAge';
import type { PosPatient, SalePatient } from '@/types/pos';

/**
 * The sale's patient (docs/PHARMACY.md, branch 13): type the name to find
 * them (online on the server, offline in the till's own copy), or add them
 * with name and age. Keyboard: ↑ ↓ pick a match, Enter takes it; Enter on a
 * new name moves to the age, Enter there adds the patient; Enter with no
 * name continues (the dialog decides whether that is allowed).
 */
const props = defineProps<{
    modelValue: SalePatient | null;
    required: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: SalePatient | null];
    /** Enter where there is nothing left to pick: continue. */
    submit: [];
}>();

const offlinePos = useOfflinePos();
const nameInput = ref<HTMLInputElement | null>(null);
const ageInput = ref<HTMLInputElement | null>(null);
const name = ref('');
const age = ref('');
const results = ref<PosPatient[]>([]);
/** The highlighted option: a match, or results.length for "New patient"; -1 = none. */
const highlight = ref(-1);
const ageError = ref<string | null>(null);
/** The chosen patient's age as typed (a correction updates the sale). */
const chosenAge = ref('');

let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

const canAdd = computed(() => name.value.trim().length >= 2);
const options = computed(() => results.value.length + (canAdd.value ? 1 : 0));
const AGE_HINT = 'Enter the age in years (34) or months (8m).';

watch(
    () => props.modelValue,
    (patient) => {
        chosenAge.value =
            formatAge(patient?.ageMonths ?? null)?.replace(' ', '') ?? '';
        ageError.value = null;
    },
    { immediate: true },
);

watch(name, (term) => {
    clearTimeout(timer);
    highlight.value = -1;

    if (term.trim().length < 2) {
        results.value = [];

        return;
    }

    timer = setTimeout(() => void search(term), 200);
});

async function search(term: string): Promise<void> {
    const mine = ++sequence;
    let found: PosPatient[];

    if (offlinePos.isOffline.value) {
        found = await searchPatients(term);
    } else {
        try {
            found = (
                await getJson<{ patients: PosPatient[] }>(
                    PatientSearchController.url({ query: { q: term } }),
                )
            ).patients;
        } catch {
            // The server could not answer: the till's own copy.
            found = await searchPatients(term);
        }
    }

    if (mine === sequence) {
        results.value = found;
    }
}

function choose(patient: PosPatient): void {
    emit('update:modelValue', {
        uuid: patient.uuid,
        name: patient.name,
        ageMonths: patient.age_months,
        isNew: false,
    });
    reset();
}

/** Adds the patient typed; false when the name or age is not right yet. */
function addNew(): boolean {
    const typed = name.value.trim().replace(/\s+/g, ' ');

    if (typed.length < 2) {
        nameInput.value?.focus();

        return false;
    }

    const months = age.value.trim() === '' ? null : parseAge(age.value);

    if (age.value.trim() !== '' && months === null) {
        ageError.value = AGE_HINT;
        ageInput.value?.focus();

        return false;
    }

    emit('update:modelValue', {
        uuid: crypto.randomUUID(),
        name: typed,
        ageMonths: months,
        isNew: true,
    });
    reset();

    return true;
}

function reset(): void {
    name.value = '';
    age.value = '';
    results.value = [];
    highlight.value = -1;
    ageError.value = null;
}

function onNameKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' && options.value > 0) {
        event.preventDefault();
        highlight.value = (highlight.value + 1) % options.value;
    } else if (event.key === 'ArrowUp' && options.value > 0) {
        event.preventDefault();
        highlight.value =
            highlight.value <= 0 ? options.value - 1 : highlight.value - 1;
    } else if (event.key === 'Enter') {
        event.preventDefault();

        if (highlight.value >= 0 && highlight.value < results.value.length) {
            choose(results.value[highlight.value]);
        } else if (highlight.value === results.value.length && canAdd.value) {
            ageInput.value?.focus();
        } else if (name.value.trim() === '') {
            emit('submit');
        } else {
            ageInput.value?.focus();
        }
    }
}

function onAgeKey(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();

        if (addNew()) {
            emit('submit');
        }
    }
}

/** A correction of the chosen patient's age (re-estimates an estimated date of birth). */
function onChosenAge(): void {
    if (props.modelValue === null) {
        return;
    }

    const typed = chosenAge.value.trim();
    const months = typed === '' ? null : parseAge(typed);

    if (typed !== '' && months === null) {
        ageError.value = AGE_HINT;

        return;
    }

    ageError.value = null;
    emit('update:modelValue', { ...props.modelValue, ageMonths: months });
}

function clear(): void {
    emit('update:modelValue', null);
    void nextTick(() => nameInput.value?.focus());
}

function focus(): void {
    void nextTick(() => {
        if (props.modelValue === null) {
            nameInput.value?.focus();
        }
    });
}

defineExpose({ focus });
</script>

<template>
    <div class="grid gap-2" data-test="patient-picker">
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-sm font-medium">Patient</span>
            <span
                class="text-xs"
                :class="required ? 'text-destructive' : 'text-muted-foreground'"
                data-test="patient-required"
                >{{ required ? 'Required' : 'Optional' }}</span
            >
        </div>

        <div
            v-if="modelValue"
            class="flex min-w-0 flex-wrap items-center gap-2 rounded-lg border bg-muted/40 p-2"
            data-test="patient-chosen"
        >
            <UserRound class="size-5 shrink-0 text-muted-foreground" />
            <span class="min-w-0 flex-1 truncate font-medium">{{
                modelValue.name
            }}</span>
            <span
                v-if="modelValue.isNew"
                class="shrink-0 rounded bg-primary/10 px-1.5 py-0.5 text-xs text-primary"
                >New</span
            >
            <label class="flex shrink-0 items-center gap-1 text-sm">
                Age
                <input
                    v-model="chosenAge"
                    type="text"
                    inputmode="text"
                    maxlength="5"
                    autocomplete="off"
                    placeholder="34 or 8m"
                    class="h-10 w-20 rounded-lg border bg-background px-2 text-base"
                    data-test="patient-chosen-age"
                    @change="onChosenAge"
                    @keydown.enter.prevent="
                        onChosenAge();
                        if (ageError === null) emit('submit');
                    "
                />
            </label>
            <Button
                type="button"
                variant="ghost"
                class="h-10 shrink-0"
                data-test="patient-change"
                @click="clear"
            >
                <X class="size-4" /> Change
            </Button>
        </div>

        <template v-else>
            <div class="grid grid-cols-[minmax(0,1fr)_6rem] gap-2">
                <input
                    ref="nameInput"
                    v-model="name"
                    type="text"
                    maxlength="255"
                    autocomplete="off"
                    placeholder="Type the name to find or add"
                    role="combobox"
                    aria-label="Patient name"
                    aria-autocomplete="list"
                    :aria-expanded="options > 0"
                    aria-controls="patient-options"
                    class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base"
                    data-test="patient-name"
                    @keydown="onNameKey"
                />
                <input
                    ref="ageInput"
                    v-model="age"
                    type="text"
                    maxlength="5"
                    autocomplete="off"
                    placeholder="Age"
                    aria-label="Age (34, or 8m for months)"
                    class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base"
                    data-test="patient-age"
                    @keydown="onAgeKey"
                />
            </div>

            <ul
                v-if="options > 0"
                id="patient-options"
                role="listbox"
                class="grid max-h-52 overflow-y-auto rounded-lg border text-sm"
                data-test="patient-options"
            >
                <li v-for="(result, index) in results" :key="result.uuid">
                    <button
                        type="button"
                        role="option"
                        :aria-selected="index === highlight"
                        class="flex w-full min-w-0 items-center gap-2 px-3 py-2 text-left hover:bg-muted"
                        :class="index === highlight ? 'bg-muted' : ''"
                        data-test="patient-result"
                        @click="choose(result)"
                    >
                        <span class="min-w-0 flex-1 truncate font-medium">{{
                            result.name
                        }}</span>
                        <span class="shrink-0 text-muted-foreground">{{
                            formatAge(result.age_months) ?? 'age not known'
                        }}</span>
                    </button>
                </li>
                <li v-if="canAdd">
                    <button
                        type="button"
                        role="option"
                        :aria-selected="highlight === results.length"
                        class="flex w-full min-w-0 items-center gap-2 border-t px-3 py-2 text-left text-primary hover:bg-muted"
                        :class="highlight === results.length ? 'bg-muted' : ''"
                        data-test="patient-add"
                        @click="addNew"
                    >
                        <Plus class="size-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate"
                            >New patient: {{ name.trim()
                            }}{{ age.trim() ? `, ${age.trim()}` : '' }}</span
                        >
                    </button>
                </li>
            </ul>
        </template>

        <p
            v-if="ageError"
            class="text-sm text-destructive"
            role="alert"
            data-test="patient-age-error"
        >
            {{ ageError }}
        </p>
    </div>
</template>
