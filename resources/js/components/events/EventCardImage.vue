<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { onImgError } from '@/lib/events';
import type { EventImage } from '@/lib/events';

const props = defineProps<{ images: EventImage[] }>();

const active = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;

// Cycle through the event's images while hovered, a light way to surface that
// every event carries more than one image.
function start() {
    if (props.images.length < 2 || timer) {
        return;
    }

    // Don't auto-cycle for viewers who asked for reduced motion.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    timer = setInterval(() => {
        active.value = (active.value + 1) % props.images.length;
    }, 1100);
}

function stop() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

onBeforeUnmount(stop);
</script>

<template>
    <div
        class="group/img relative h-44 overflow-hidden bg-muted"
        @mouseenter="start"
        @mouseleave="stop"
    >
        <img
            v-for="(image, i) in images"
            :key="image.url"
            :src="image.url"
            :alt="image.alt"
            loading="lazy"
            class="absolute inset-0 h-full w-full object-cover transition-all duration-700 ease-out group-hover/img:scale-105"
            :class="i === active ? 'opacity-100' : 'opacity-0'"
            @error="onImgError"
        />
        <div
            v-if="images.length > 1"
            class="absolute bottom-2 left-1/2 flex -translate-x-1/2 gap-1.5"
        >
            <button
                v-for="(image, i) in images"
                :key="image.url"
                type="button"
                class="h-1.5 rounded-full bg-white/70 transition-all"
                :class="i === active ? 'w-5 bg-white' : 'w-1.5'"
                :aria-label="`Show image ${i + 1} of ${images.length}`"
                :aria-current="i === active"
                @click.stop="active = i"
            />
        </div>
    </div>
</template>
