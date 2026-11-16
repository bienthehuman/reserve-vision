import { useEffect, useRef, useState } from 'react';

export type PlaceListStatus = 'idle' | 'loading' | 'ready' | 'error';

export interface PlaceList<T> {
    status: PlaceListStatus;
    items: T[];
    /** After an error: fetch the same list again. */
    retry: () => void;
}

/**
 * One dependent dropdown's list (slice 4.4): GETs `url` (a `/places/…` endpoint) and keeps each answer,
 * so going back to a province shows its cities at once. `seed` holds lists the page already has
 * (an existing property's), so the form renders filled on the server and on first paint. A null `url`
 * means the dropdown above it has no choice yet. A failed request is an `error` with a `retry`,
 * never an empty list.
 */
export function usePlaceList<T>(url: string | null, seed: Record<string, T[]> = {}): PlaceList<T> {
    const cache = useRef(new Map<string, T[]>(Object.entries(seed)));
    const [failed, setFailed] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const [, setLoaded] = useState(0);

    useEffect(() => {
        if (url === null || cache.current.has(url)) {
            return;
        }

        const controller = new AbortController();
        fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json() as Promise<T[]>;
            })
            .then((items) => {
                cache.current.set(url, items);
                setLoaded((n) => n + 1);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(url);
                }
            });

        return () => controller.abort();
    }, [url, attempt]);

    const retry = () => {
        setFailed(null);
        setAttempt((n) => n + 1);
    };

    if (url === null) {
        return { status: 'idle', items: [], retry };
    }

    const items = cache.current.get(url);
    if (items !== undefined) {
        return { status: 'ready', items, retry };
    }

    return { status: failed === url ? 'error' : 'loading', items: [], retry };
}
