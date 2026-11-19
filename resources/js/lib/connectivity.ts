import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/** A request that never reached the server (offline, DNS, timeout) gets a message instead of a silent no-op. */
export function initializeConnectivity(): void {
    router.on('networkError', () => {
        toast.error("Couldn't reach the server", {
            id: 'network-error',
            description: navigator.onLine ? 'Check your connection and try again.' : "You're offline. Try again when you're back online.",
        });
    });
}
