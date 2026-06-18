<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime, postJson } from '@/lib/events';
import type { EventCard } from '@/lib/events';

const props = defineProps<{ event: EventCard | null }>();
const emit = defineEmits<{ close: [] }>();

const form = reactive({ name: '', email: '' });
const errors = reactive<{ name: string | null; email: string | null }>({
    name: null,
    email: null,
});
const submitting = ref(false);
const touched = ref(false);

// Reset whenever a different event opens.
watch(
    () => props.event?.id,
    () => {
        form.name = '';
        form.email = '';
        errors.name = null;
        errors.email = null;
        touched.value = false;
    },
);

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Client-side validation mirroring the server rules; returns whether it's valid. */
function validate(): boolean {
    errors.name = form.name.trim() ? null : 'Please enter your name.';
    errors.email = !form.email.trim()
        ? 'Please enter your email.'
        : EMAIL_RE.test(form.email.trim())
          ? null
          : 'Enter a valid email address.';

    return !errors.name && !errors.email;
}

watch(form, () => touched.value && validate());

const canSubmit = computed(
    () => !submitting.value && form.name.trim() && form.email.trim(),
);

async function submit() {
    if (!props.event) {
        return;
    }

    touched.value = true;

    if (!validate()) {
        return;
    }

    submitting.value = true;

    try {
        const { ok, status, data } = await postJson(
            `/events/${props.event.id}/register`,
            {
                name: form.name.trim(),
                email: form.email.trim(),
            },
        );

        if (ok) {
            toast.success(data.message ?? "You're on the list.");
            emit('close');

            return;
        }

        if (status === 422 && data.errors) {
            errors.name = data.errors.name?.[0] ?? null;
            errors.email = data.errors.email?.[0] ?? null;
        } else {
            toast.error('Something went wrong. Please try again.');
        }
    } finally {
        submitting.value = false;
    }
}

const fieldClass = (hasError: boolean) =>
    `w-full rounded-lg border bg-transparent px-3 py-2 text-sm outline-none focus:ring-2 ${
        hasError
            ? 'border-red-500 focus:ring-red-500'
            : 'border-input focus:ring-ring'
    }`;
</script>

<template>
    <!-- reka-ui Dialog: focus trap, Escape-to-close, focus restore, role=dialog + aria-modal,
         and aria-labelledby/aria-describedby wired from DialogTitle/DialogDescription. -->
    <Dialog
        :open="!!event"
        @update:open="(open: boolean) => !open && emit('close')"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Register for {{ event?.name }}</DialogTitle>
                <DialogDescription>
                    {{ event?.city ?? 'Location TBC' }} ·
                    {{ formatDateTime(event?.starts_at_iso ?? null) }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" novalidate @submit.prevent="submit">
                <div class="space-y-1.5">
                    <label class="text-sm font-medium" for="reg-name"
                        >Name</label
                    >
                    <input
                        id="reg-name"
                        v-model="form.name"
                        :class="fieldClass(!!errors.name)"
                        placeholder="Your name"
                        autocomplete="name"
                        maxlength="255"
                        :aria-invalid="!!errors.name"
                    />
                    <p v-if="errors.name" class="text-xs text-red-600">
                        {{ errors.name }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <label class="text-sm font-medium" for="reg-email"
                        >Email</label
                    >
                    <input
                        id="reg-email"
                        v-model="form.email"
                        type="email"
                        :class="fieldClass(!!errors.email)"
                        placeholder="you@example.com"
                        autocomplete="email"
                        maxlength="255"
                        :aria-invalid="!!errors.email"
                    />
                    <p v-if="errors.email" class="text-xs text-red-600">
                        {{ errors.email }}
                    </p>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground hover:bg-muted"
                        @click="emit('close')"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90 disabled:opacity-50"
                    >
                        {{ submitting ? 'Adding…' : "I'm attending" }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
