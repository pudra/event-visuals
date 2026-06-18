<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import EventCardImage from '@/components/events/EventCardImage.vue';
import EventFilters from '@/components/events/EventFilters.vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import {
    categoryLabel,
    emptyFilters,
    filterParams,
    formatDateTime,
    formatPrice,
    viewerTimezone,
} from '@/lib/events';
import type { EventCard, EventFilterState, FilterOptions } from '@/lib/events';

const options = ref<FilterOptions>({
    cities: [],
    categories: [],
    date_min: null,
    date_max: null,
});
const filters = ref<EventFilterState>(emptyFilters());
const events = ref<EventCard[]>([]);
const page = ref(0);
const lastPage = ref(1);
const total = ref(0);
const statsMs = ref(0);
const loading = ref(false);
const error = ref(false);
const selected = ref<EventCard | null>(null);

const tz = viewerTimezone();
const hasMore = computed(() => page.value < lastPage.value);

async function loadFilters() {
    const res = await fetch('/events/filters', {
        headers: { Accept: 'application/json' },
    });

    if (!res.ok) {
        throw new Error(`filters ${res.status}`);
    }

    options.value = await res.json();
}

let requestSeq = 0;
let pendingReset = false;

async function load(reset = false) {
    // If a request is in flight, queue a reset (filter change) to re-run when it
    // finishes, never silently drop a filter change.
    if (loading.value) {
        if (reset) {
            pendingReset = true;
        }

        return;
    }

    loading.value = true;
    error.value = false;

    if (reset) {
        page.value = 0;
        lastPage.value = 1;
    }

    const seq = ++requestSeq;
    const params = filterParams(filters.value);
    params.set('page', String(page.value + 1));

    try {
        const res = await fetch(`/events/listing?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            throw new Error(`listing ${res.status}`);
        }

        const payload = await res.json();

        // Ignore a stale response superseded by a newer request.
        if (seq !== requestSeq) {
            return;
        }

        events.value = reset
            ? payload.data
            : [...events.value, ...payload.data];
        page.value = payload.current_page;
        lastPage.value = payload.last_page;
        total.value = payload.total;
        statsMs.value = payload.stats.ms;
    } catch {
        // Only surface the error if this is still the active request; a stale
        // failure shouldn't blank a screen a newer request has already refilled.
        if (seq === requestSeq) {
            error.value = true;
        }
    } finally {
        loading.value = false;

        if (pendingReset) {
            pendingReset = false;
            load(true);
        }
    }
}

function applyFilters() {
    load(true);
}

onMounted(async () => {
    try {
        await loadFilters();
    } catch {
        // Non-fatal: the grid still loads; filter dropdowns just stay empty.
    }

    await load(true);
});
</script>

<template>
    <Head title="Events Gallery" />

    <div class="mx-auto w-full max-w-7xl px-4 py-6">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Browse Events
                </h1>
                <p
                    class="text-sm text-muted-foreground"
                    role="status"
                    aria-live="polite"
                >
                    {{ total.toLocaleString() }} events · times shown in
                    {{ tz }}
                </p>
            </div>
            <Link
                href="/events-visual-2"
                class="rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-muted"
            >
                Map view →
            </Link>
        </div>

        <!-- Filters -->
        <div class="mt-5 rounded-xl border border-border bg-card/40 p-4">
            <EventFilters
                v-model="filters"
                :options="options"
                @apply="applyFilters"
            />
        </div>

        <!-- Grid -->
        <div
            v-if="events.length"
            class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <article
                v-for="(event, i) in events"
                :key="event.id"
                class="group flex animate-in flex-col overflow-hidden rounded-xl border border-border bg-card fill-mode-both fade-in slide-in-from-bottom-3"
                :style="{ animationDelay: `${Math.min(i % 24, 12) * 35}ms` }"
            >
                <EventCardImage :images="event.images" />

                <div class="flex flex-1 flex-col p-4">
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary"
                        >
                            {{ categoryLabel(event.category) }}
                        </span>
                        <span class="text-sm font-semibold">{{
                            formatPrice(event.min_price)
                        }}</span>
                    </div>

                    <h3 class="mt-2 line-clamp-1 font-semibold">
                        {{ event.name }}
                    </h3>
                    <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">
                        {{ event.description }}
                    </p>

                    <dl class="mt-3 space-y-1 text-sm">
                        <div
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <span aria-hidden="true">📍</span
                            ><span class="truncate"
                                >{{ event.city }} · {{ event.venue_name }}</span
                            >
                        </div>
                        <div
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <span aria-hidden="true">🗓</span
                            ><span>{{
                                formatDateTime(event.starts_at_iso)
                            }}</span>
                        </div>
                    </dl>

                    <div class="mt-4 flex items-center gap-2 pt-1">
                        <button
                            class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90"
                            @click="selected = event"
                        >
                            Register
                        </button>
                        <Link
                            :href="`/events/${event.id}`"
                            class="rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-muted"
                        >
                            Details
                        </Link>
                    </div>
                </div>
            </article>
        </div>

        <!-- Error -->
        <div
            v-else-if="error && !loading"
            class="mt-16 text-center"
            role="alert"
        >
            <p class="text-muted-foreground">
                Something went wrong loading events.
            </p>
            <button
                type="button"
                class="mt-3 rounded-lg border border-border px-4 py-2 text-sm font-medium transition hover:bg-muted"
                @click="load(true)"
            >
                Try again
            </button>
        </div>

        <!-- Empty -->
        <div
            v-else-if="!loading"
            class="mt-16 text-center text-muted-foreground"
        >
            No events match these filters.
        </div>

        <!-- Skeletons (initial) -->
        <div
            v-if="loading && !events.length"
            class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="n in 8"
                :key="n"
                class="h-72 animate-pulse rounded-xl border border-border bg-muted/40"
            />
        </div>

        <!-- Load more -->
        <div v-if="hasMore" class="mt-8 flex justify-center">
            <button
                :disabled="loading"
                class="rounded-lg border border-border px-5 py-2.5 text-sm font-medium transition hover:bg-muted disabled:opacity-50"
                @click="load(false)"
            >
                {{ loading ? 'Loading…' : 'Load more' }}
            </button>
        </div>

        <p class="mt-6 text-center text-xs text-muted-foreground">
            query {{ statsMs }}ms
        </p>
    </div>

    <RegisterDialog :event="selected" @close="selected = null" />
</template>
