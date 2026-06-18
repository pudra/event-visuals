<script setup lang="ts">
import { computed } from 'vue';
import DatePicker from '@/components/events/DatePicker.vue';
import { categoryLabel } from '@/lib/events';
import type { EventFilterState, FilterOptions } from '@/lib/events';

const model = defineModel<EventFilterState>({ required: true });
const props = defineProps<{ options: FilterOptions }>();
const emit = defineEmits<{ apply: [] }>();

function set<K extends keyof EventFilterState>(
    key: K,
    value: EventFilterState[K],
) {
    model.value = { ...model.value, [key]: value };
    emit('apply');
}

function clear() {
    model.value = { from: '', to: '', city: '', category: '' };
    emit('apply');
}

// "From" can't be after "To"; "To" can't be before "From". Each bound also
// respects the dataset's own min/max so you can't filter outside the data.
const fromMax = computed(() => model.value.to || props.options.date_max);
const toMin = computed(() => model.value.from || props.options.date_min);

const hasActive = () => Object.values(model.value).some(Boolean);
const selectClass =
    'rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring';
</script>

<template>
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-muted-foreground"
                >From</label
            >
            <DatePicker
                :model-value="model.from"
                :min="options.date_min"
                :max="fromMax"
                placeholder="Any date"
                aria-label="From date"
                @update:model-value="set('from', $event)"
            />
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-muted-foreground">To</label>
            <DatePicker
                :model-value="model.to"
                :min="toMin"
                :max="options.date_max"
                placeholder="Any date"
                aria-label="To date"
                @update:model-value="set('to', $event)"
            />
        </div>
        <div class="flex flex-col gap-1">
            <label
                class="text-xs font-medium text-muted-foreground"
                for="filter-city"
                >Location</label
            >
            <select
                id="filter-city"
                :value="model.city"
                :class="selectClass"
                @change="
                    set('city', ($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">All cities</option>
                <option v-for="c in options.cities" :key="c" :value="c">
                    {{ c }}
                </option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label
                class="text-xs font-medium text-muted-foreground"
                for="filter-category"
                >Category</label
            >
            <select
                id="filter-category"
                :value="model.category"
                :class="selectClass"
                @change="
                    set('category', ($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">All types</option>
                <option v-for="c in options.categories" :key="c" :value="c">
                    {{ categoryLabel(c) }}
                </option>
            </select>
        </div>
        <button
            v-if="hasActive()"
            class="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-muted"
            @click="clear"
        >
            Clear
        </button>
    </div>
</template>
