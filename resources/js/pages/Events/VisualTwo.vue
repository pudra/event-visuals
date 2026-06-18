<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import EventFilters from '@/components/events/EventFilters.vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import {
    categoryLabel,
    emptyFilters,
    filterParams,
    formatDateTime,
    formatPrice,
    onImgError,
} from '@/lib/events';
import type { EventCard, EventFilterState, FilterOptions } from '@/lib/events';

const CATEGORY_COLORS: Record<string, string> = {
    concert: '#db2777',
    conference: '#2563eb',
    meetup: '#0d9488',
    workshop: '#d97706',
    festival: '#f59e0b',
    sports: '#16a34a',
    networking: '#7c3aed',
    exhibition: '#0891b2',
};

const options = ref<FilterOptions>({
    cities: [],
    categories: [],
    date_min: null,
    date_max: null,
});
const filters = ref<EventFilterState>(emptyFilters());
const pointList = ref<EventCard[]>([]); // backs the keyboard-accessible results list
const shown = ref(0);
const capped = ref(false);
const cap = ref(0);
const statsMs = ref(0);
const loading = ref(false);
const error = ref(false);
const selected = ref<EventCard | null>(null); // highlighted on the map / shown in side card
const registering = ref<EventCard | null>(null); // open in the register dialog

const mapEl = ref<HTMLElement | null>(null);
const selectionCard = ref<HTMLElement | null>(null);
const map = shallowRef<L.Map | null>(null);

// Selecting from the keyboard results list moves focus into the selection card so
// a keyboard user isn't stranded at the top of a long list after picking an event.
async function selectFromList(point: EventCard) {
    selected.value = point;
    await nextTick();
    selectionCard.value?.focus();
}
const markerLayer = shallowRef<L.MarkerClusterGroup | null>(null);

async function loadFilters() {
    const res = await fetch('/events/filters', {
        headers: { Accept: 'application/json' },
    });

    if (!res.ok) {
        throw new Error(`filters ${res.status}`);
    }

    options.value = await res.json();
}

let mapRequestSeq = 0;

async function loadPoints() {
    if (!markerLayer.value) {
        return;
    }

    loading.value = true;
    error.value = false;
    const seq = ++mapRequestSeq;

    try {
        const res = await fetch(
            `/events/map?${filterParams(filters.value).toString()}`,
            {
                headers: { Accept: 'application/json' },
            },
        );

        if (!res.ok) {
            throw new Error(`map ${res.status}`);
        }

        const payload = await res.json();

        // Ignore a response superseded by a newer filter change.
        if (seq !== mapRequestSeq) {
            return;
        }

        capped.value = payload.capped;
        cap.value = payload.cap;
        statsMs.value = payload.stats.ms;

        markerLayer.value.clearLayers();
        const points: EventCard[] = payload.points;
        pointList.value = points; // keep the accessible list in sync with the markers
        shown.value = points.length;

        for (const point of points) {
            if (point.lat === null || point.lng === null) {
                continue;
            }

            const marker = L.circleMarker([point.lat, point.lng], {
                radius: 6,
                color: '#ffffff',
                weight: 1,
                fillColor: CATEGORY_COLORS[point.category] ?? '#6366f1',
                fillOpacity: 0.85,
            });
            marker.on('click', () => {
                selected.value = point;
            });
            marker.addTo(markerLayer.value);
        }
    } catch {
        if (seq === mapRequestSeq) {
            error.value = true;
        }
    } finally {
        // Only the latest request clears the indicator.
        if (seq === mapRequestSeq) {
            loading.value = false;
        }
    }
}

function applyFilters() {
    selected.value = null; // a selected pin may no longer be in the filtered set
    loadPoints();
}

onMounted(async () => {
    if (!mapEl.value) {
        return;
    }

    const m = L.map(mapEl.value, { worldCopyJump: true }).setView([30, -20], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(m);
    map.value = m;
    // Cluster markers so dense metros collapse into counts instead of overlapping blobs.
    markerLayer.value = L.markerClusterGroup({
        chunkedLoading: true,
        maxClusterRadius: 50,
    }).addTo(m);

    try {
        await loadFilters();
    } catch {
        // Non-fatal: the map still loads; filter dropdowns just stay empty.
    }

    await loadPoints();
});

onBeforeUnmount(() => {
    map.value?.remove();
});
</script>

<template>
    <Head title="Events Map" />

    <div class="flex h-[calc(100dvh-4rem)] flex-col">
        <!-- Header + filters -->
        <div class="border-b border-border px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">
                        Events Map
                    </h1>
                    <p
                        class="text-xs text-muted-foreground"
                        role="status"
                        aria-live="polite"
                    >
                        Showing {{ shown.toLocaleString()
                        }}{{ capped ? '+' : '' }} events
                        <span v-if="capped">
                            (first {{ cap.toLocaleString() }}, narrow the
                            filters)</span
                        >
                        · {{ statsMs }}ms
                    </p>
                </div>
                <Link
                    href="/events-visual-1"
                    class="rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-muted"
                >
                    ← Gallery view
                </Link>
            </div>
            <div class="mt-3">
                <EventFilters
                    v-model="filters"
                    :options="options"
                    @apply="applyFilters"
                />
            </div>
        </div>

        <!-- Map -->
        <div class="relative flex-1">
            <div
                ref="mapEl"
                role="region"
                aria-label="Events map"
                class="absolute inset-0 z-0"
            />

            <!-- Keyboard/SR-accessible path to the same events the markers show:
                 selecting one drives the same selection card as clicking a pin. -->
            <ul aria-label="Event results: select one for details">
                <li v-for="point in pointList" :key="point.id">
                    <button
                        type="button"
                        class="sr-only focus:not-sr-only focus:absolute focus:top-16 focus:left-4 focus:z-[600] focus:max-w-xs focus:rounded-lg focus:border focus:border-border focus:bg-background focus:px-3 focus:py-2 focus:text-left focus:text-sm focus:shadow-lg focus:ring-2 focus:ring-ring focus:outline-none"
                        @click="selectFromList(point)"
                    >
                        {{ point.name }}, {{ categoryLabel(point.category) }},
                        {{ point.city }},
                        {{ formatDateTime(point.starts_at_iso) }}
                    </button>
                </li>
            </ul>

            <div
                v-if="loading"
                class="absolute top-4 right-4 z-[500] rounded-lg bg-background/90 px-3 py-1.5 text-sm shadow"
            >
                Loading…
            </div>

            <!-- Error + retry -->
            <div
                v-if="error && !loading"
                role="alert"
                class="absolute top-4 left-1/2 z-[500] flex -translate-x-1/2 items-center gap-3 rounded-lg border border-border bg-background/95 px-4 py-2 text-sm shadow"
            >
                <span class="text-muted-foreground"
                    >Couldn't load the map.</span
                >
                <button
                    type="button"
                    class="rounded-md border border-border px-3 py-1 font-medium transition hover:bg-muted"
                    @click="loadPoints"
                >
                    Try again
                </button>
            </div>

            <!-- Empty (parity with the gallery's empty state) -->
            <div
                v-if="!loading && !error && shown === 0"
                class="absolute top-4 left-1/2 z-[500] -translate-x-1/2 rounded-lg border border-border bg-background/95 px-4 py-2 text-sm text-muted-foreground shadow"
            >
                No events match these filters.
            </div>

            <!-- Legend -->
            <div
                class="absolute bottom-4 left-4 z-[500] rounded-lg border border-border bg-background/90 p-3 text-xs shadow"
            >
                <div class="mb-1.5 font-medium">Category</div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                    <div
                        v-for="(color, cat) in CATEGORY_COLORS"
                        :key="cat"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="inline-block h-2.5 w-2.5 rounded-full"
                            :style="{ background: color }"
                        />
                        {{ categoryLabel(cat) }}
                    </div>
                </div>
            </div>

            <!-- Selected event card -->
            <div
                v-if="selected"
                ref="selectionCard"
                tabindex="-1"
                role="group"
                aria-label="Selected event"
                class="absolute top-4 right-4 z-[500] w-72 animate-in overflow-hidden rounded-xl border border-border bg-background shadow-2xl outline-none fade-in slide-in-from-right-3 focus:ring-2 focus:ring-ring"
            >
                <img
                    :src="selected.images[0]?.url"
                    :alt="selected.name"
                    class="h-32 w-full object-cover"
                    @error="onImgError"
                />
                <button
                    type="button"
                    aria-label="Close"
                    class="absolute top-2 right-2 grid h-7 w-7 place-items-center rounded-full bg-black/50 text-white hover:bg-black/70"
                    @click="selected = null"
                >
                    <span aria-hidden="true">✕</span>
                </button>
                <div class="p-4">
                    <span
                        class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                    >
                        {{ categoryLabel(selected.category) }}
                    </span>
                    <h3 class="mt-2 leading-tight font-semibold">
                        {{ selected.name }}
                    </h3>
                    <p
                        v-if="selected.description"
                        class="mt-1 line-clamp-3 text-sm text-muted-foreground"
                    >
                        {{ selected.description }}
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        <span aria-hidden="true">📍</span> {{ selected.city }} ·
                        {{ selected.venue_name }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <span aria-hidden="true">🗓</span>
                        {{ formatDateTime(selected.starts_at_iso) }}
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ formatPrice(selected.min_price) }}
                    </p>
                    <div class="mt-3 flex gap-2">
                        <button
                            class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90"
                            @click="registering = selected"
                        >
                            Register
                        </button>
                        <Link
                            :href="`/events/${selected.id}`"
                            class="rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-muted"
                        >
                            Details
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <RegisterDialog :event="registering" @close="registering = null" />
</template>
