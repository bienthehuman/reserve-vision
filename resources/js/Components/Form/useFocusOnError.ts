import { useRef } from 'react';

/**
 * A ref for the ErrorSummary and an onError callback that moves focus to it, so a
 * failed submit is announced and the user starts at the list of problems.
 */
export function useFocusOnError<T extends HTMLElement = HTMLDivElement>() {
    const ref = useRef<T>(null);

    const onError = () => {
        // After React has rendered the summary.
        window.setTimeout(() => ref.current?.focus(), 0);
    };

    return { ref, onError };
}
