// Shared types + helpers for the event browsing pages.

export interface EventImage {
    url: string;
    alt: string;
}

export interface EventCard {
    id: string;
    name: string;
    description: string | null;
    category: string;
    status: string;
    city: string | null;
    venue_name: string | null;
    min_price: number | null;
    lat: number | null;
    lng: number | null;
    starts_at_iso: string | null;
    images: EventImage[];
}

export interface FilterOptions {
    cities: string[];
    categories: string[];
    date_min: string | null;
    date_max: string | null;
}

export interface EventFilterState {
    from: string;
    to: string;
    city: string;
    category: string;
}

export function emptyFilters(): EventFilterState {
    return { from: '', to: '', city: '', category: '' };
}

export function filterParams(f: EventFilterState): URLSearchParams {
    const params = new URLSearchParams();

    if (f.from) {
        params.set('from', f.from);
    }

    if (f.to) {
        params.set('to', f.to);
    }

    if (f.city) {
        params.set('city', f.city);
    }

    if (f.category) {
        params.set('category', f.category);
    }

    return params;
}

/** Laravel sets an XSRF-TOKEN cookie; echo it back for non-GET requests. */
export function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Send a POST and always resolve to a uniform result; a network failure
 * (offline/DNS/dropped connection) resolves to { ok: false, status: 0 } rather
 * than rejecting, so callers' else-branches handle it and the UI never strands.
 */
async function post(url: string, init: RequestInit) {
    let res: Response;

    try {
        res = await fetch(url, init);
    } catch {
        return { ok: false, status: 0, data: {} };
    }

    const data = await res.json().catch(() => ({}));

    return { ok: res.ok, status: res.status, data };
}

export function postJson(url: string, body: unknown) {
    return post(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });
}

/** Multipart POST (file upload). No Content-Type; the browser sets the boundary. */
export function postForm(url: string, form: FormData) {
    return post(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: form,
    });
}

// US-style formatting (e.g. "Jun 18, 2026, 10:53 AM") since this is a US audience.
// Times still render in the viewer's own timezone (events are global UTC instants).
const LOCALE = 'en-US';

/** Render a UTC instant in US format, in the viewer's own timezone. */
export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return 'Date TBC';
    }

    return new Intl.DateTimeFormat(LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

export function formatDay(iso: string | null): string {
    if (!iso) {
        return 'TBC';
    }

    return new Intl.DateTimeFormat(LOCALE, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    }).format(new Date(iso));
}

/** Friendly short label for the viewer's timezone, e.g. "EDT", "PDT", "GMT+3". */
export function viewerTimezone(): string {
    const name = new Intl.DateTimeFormat(LOCALE, { timeZoneName: 'short' })
        .formatToParts(new Date())
        .find((part) => part.type === 'timeZoneName')?.value;

    return name ?? Intl.DateTimeFormat().resolvedOptions().timeZone;
}

export function formatPrice(price: number | null): string {
    if (price === null || price === undefined) {
        return '';
    }

    if (price === 0) {
        return 'Free';
    }

    return new Intl.NumberFormat(LOCALE, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(price);
}

export function categoryLabel(category: string): string {
    return category.charAt(0).toUpperCase() + category.slice(1);
}

/** Swap a failed image to a known-good local placeholder (once, to avoid a loop). */
export function onImgError(e: Event): void {
    const img = e.target as HTMLImageElement;

    if (img.dataset.fallback) {
        return;
    }

    img.dataset.fallback = '1';
    img.src = '/images/events/scene-a.svg';
}
