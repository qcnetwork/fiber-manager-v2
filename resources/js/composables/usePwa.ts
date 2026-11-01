import { computed, ref } from 'vue';

// Chrome/Edge/Android fire this before showing their own install UI; it is not part of the DOM typings.
interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

// Module-level so the event is kept even if it fires before any component is mounted.
const installEvent = ref<BeforeInstallPromptEvent | null>(null);

export function registerPwa() {
    if (typeof window === 'undefined') return;

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installEvent.value = event as BeforeInstallPromptEvent;
    });
    window.addEventListener('appinstalled', () => (installEvent.value = null));
}

export function useInstallPrompt() {
    async function install() {
        const event = installEvent.value;
        if (!event) return;
        await event.prompt();
        await event.userChoice;
        // The event can only be used once.
        installEvent.value = null;
    }

    return { canInstall: computed(() => installEvent.value !== null), install };
}
