<script setup lang="ts">
// A native date input: a real calendar selector that is keyboard- and
// screen-reader-complete for free (platform date grid, Arrow/Home/End/PageUp-Down
// navigation, locale formatting) and consistent with the native <select> filters
// alongside it. The model is already 'YYYY-MM-DD', which is exactly the native
// input's value format, so no parsing/formatting layer is needed.
defineProps<{
    min?: string | null;
    max?: string | null;
    placeholder?: string;
    ariaLabel?: string;
}>();

const model = defineModel<string>({ default: '' }); // 'YYYY-MM-DD' or ''
</script>

<template>
    <input
        type="date"
        :value="model"
        :min="min ?? undefined"
        :max="max ?? undefined"
        :aria-label="ariaLabel ?? 'Choose date'"
        class="rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
        @input="model = ($event.target as HTMLInputElement).value"
    />
</template>
