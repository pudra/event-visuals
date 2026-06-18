<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import {
    categoryLabel,
    formatDateTime,
    formatPrice,
    onImgError,
    postForm,
} from '@/lib/events';
import type { EventCard, EventImage } from '@/lib/events';

interface EventDetail extends EventCard {
    ends_at_iso: string | null;
    payload: Record<string, unknown>;
}

const props = defineProps<{ event: EventDetail }>();

// Local copy so newly uploaded images appear without a full page reload.
const images = ref<EventImage[]>([...props.event.images]);
const activeImage = ref(0);
const registering = ref(false);
const mapEl = ref<HTMLElement | null>(null);
const map = shallowRef<L.Map | null>(null);

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadError = ref('');

async function onFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    const files = input.files;

    if (!files || files.length === 0) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    const form = new FormData();

    for (const file of Array.from(files)) {
        form.append('images[]', file);
    }

    try {
        const { ok, data } = await postForm(
            `/events/${props.event.id}/images`,
            form,
        );

        if (ok) {
            const uploaded = ((data.images ?? []) as { url: string }[]).map(
                (im, i) => ({
                    url: im.url,
                    alt: `${props.event.name}, image ${i + 1}`,
                }),
            );
            // Real uploads win over placeholders: replace the placeholder set on
            // the first upload, append to any earlier real uploads after that.
            const isPlaceholder = (im: EventImage) =>
                im.url.startsWith('/images/events/');
            const onlyPlaceholders = images.value.every(isPlaceholder);
            const next = onlyPlaceholders
                ? uploaded
                : [...images.value, ...uploaded];
            // Keep the "2+ images" invariant in the live view (mirrors the server's
            // top-up) so a single-file upload never transiently shows just one image.
            const placeholders = props.event.images.filter(isPlaceholder);
            images.value =
                next.length >= 2 ? next : [...next, ...placeholders].slice(0, 2);
            activeImage.value = 0;
        } else {
            const errors = (data.errors ?? {}) as Record<string, string[]>;
            uploadError.value =
                Object.values(errors)[0]?.[0] ??
                'Upload failed. Please try again.';
        }
    } finally {
        uploading.value = false;
        input.value = ''; // let the same file be re-picked after an error
    }
}

const organizer = (
    props.event.payload?.organizer as { name?: string } | undefined
)?.name;
const capacity = (
    props.event.payload?.venue as { capacity?: string | number } | undefined
)?.capacity;
const tags = (props.event.payload?.tags as string[] | undefined) ?? [];

const statusStyles: Record<string, string> = {
    published: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    sold_out: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    cancelled: 'bg-red-500/15 text-red-700 dark:text-red-400',
    draft: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-300',
};

onMounted(() => {
    if (!mapEl.value || props.event.lat === null || props.event.lng === null) {
        return;
    }

    const instance = L.map(mapEl.value, {
        scrollWheelZoom: false,
        attributionControl: false,
    }).setView([props.event.lat, props.event.lng], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
    }).addTo(instance);
    L.circleMarker([props.event.lat, props.event.lng], {
        radius: 9,
        color: '#fff',
        weight: 2,
        fillColor: '#6366f1',
        fillOpacity: 0.9,
    }).addTo(instance);
    map.value = instance;
});

// Tear the Leaflet instance down on SPA navigation away to avoid a leak.
onBeforeUnmount(() => map.value?.remove());
</script>

<template>
    <Head :title="event.name" />

    <div class="mx-auto w-full max-w-5xl px-4 py-6">
        <Link
            href="/events-visual-1"
            class="text-sm text-muted-foreground hover:text-foreground"
            >← Back to events</Link
        >

        <!-- Hero -->
        <div class="mt-4 overflow-hidden rounded-2xl border border-border">
            <div class="relative h-72 bg-muted sm:h-96">
                <img
                    :src="images[activeImage]?.url"
                    :alt="images[activeImage]?.alt ?? event.name"
                    class="h-full w-full object-cover transition-all duration-500"
                    @error="onImgError"
                />
                <span
                    class="absolute top-4 left-4 rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur"
                >
                    {{ categoryLabel(event.category) }}
                </span>
            </div>
            <!-- Thumbnails + upload -->
            <div
                class="flex flex-wrap items-center gap-2 border-t border-border bg-card p-3"
            >
                <button
                    v-for="(image, i) in images"
                    :key="image.url"
                    class="h-14 w-20 overflow-hidden rounded-lg ring-2 transition"
                    :class="
                        i === activeImage
                            ? 'ring-primary'
                            : 'ring-transparent hover:ring-border'
                    "
                    @click="activeImage = i"
                >
                    <img
                        :src="image.url"
                        :alt="image.alt"
                        class="h-full w-full object-cover"
                        @error="onImgError"
                    />
                </button>

                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    multiple
                    class="sr-only"
                    @change="onFiles"
                />
                <button
                    type="button"
                    :disabled="uploading"
                    class="h-14 rounded-lg border border-dashed border-border px-4 text-sm font-medium text-muted-foreground transition hover:bg-muted disabled:opacity-50"
                    @click="fileInput?.click()"
                >
                    {{ uploading ? 'Uploading…' : '+ Add images' }}
                </button>
            </div>
            <p
                v-if="uploadError"
                role="alert"
                class="border-t border-border bg-card px-3 pb-3 text-sm text-red-600"
            >
                {{ uploadError }}
            </p>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <!-- Main -->
            <div class="lg:col-span-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ event.name }}
                    </h1>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="statusStyles[event.status]"
                    >
                        {{ event.status.replace('_', ' ') }}
                    </span>
                </div>
                <p class="mt-3 text-muted-foreground">
                    {{ event.description }}
                </p>

                <div v-if="tags.length" class="mt-4 flex flex-wrap gap-2">
                    <span
                        v-for="tag in tags"
                        :key="tag"
                        class="rounded-full bg-muted px-2.5 py-0.5 text-xs"
                        >#{{ tag }}</span
                    >
                </div>

                <div
                    v-if="event.lat !== null && event.lng !== null"
                    ref="mapEl"
                    class="mt-6 h-64 overflow-hidden rounded-xl border border-border"
                />
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <div class="rounded-xl border border-border bg-card p-5">
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Starts</dt>
                            <dd class="font-medium">
                                {{ formatDateTime(event.starts_at_iso) }}
                            </dd>
                        </div>
                        <div v-if="event.ends_at_iso">
                            <dt class="text-muted-foreground">Ends</dt>
                            <dd class="font-medium">
                                {{ formatDateTime(event.ends_at_iso) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Location</dt>
                            <dd class="font-medium">{{ event.city }}</dd>
                            <dd class="text-muted-foreground">
                                {{ event.venue_name }}
                            </dd>
                        </div>
                        <div v-if="organizer">
                            <dt class="text-muted-foreground">Organizer</dt>
                            <dd class="font-medium">{{ organizer }}</dd>
                        </div>
                        <div v-if="capacity">
                            <dt class="text-muted-foreground">Capacity</dt>
                            <dd class="font-medium">
                                {{ Number(capacity).toLocaleString() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">From</dt>
                            <dd class="text-lg font-semibold">
                                {{ formatPrice(event.min_price) }}
                            </dd>
                        </div>
                    </dl>

                    <button
                        class="mt-5 w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground transition hover:opacity-90"
                        @click="registering = true"
                    >
                        Register
                    </button>
                </div>
            </aside>
        </div>
    </div>

    <RegisterDialog
        :event="registering ? event : null"
        @close="registering = false"
    />
</template>
