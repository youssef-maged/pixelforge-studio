import { useDebounceFn } from '@vueuse/core';
import { onScopeDispose } from 'vue';

/** A debounced search visit that's dropped if the page is left first, so it can't navigate you back. */
export function useDebouncedVisit(visit: () => unknown, ms = 350) {
    let live = true;
    onScopeDispose(() => (live = false));

    return useDebounceFn(() => {
        if (live) {
            visit();
        }
    }, ms);
}
