/// <reference types="vite/client" />

/**
 * vite-env.d.ts - Phase 1 PWA type additions.
 *
 * NOTE: We do NOT reference vite-plugin-pwa/react here because usePWA.ts
 * uses workbox-window directly instead of the virtual:pwa-register/react
 * module, avoiding virtual module resolution issues.
 */

// Augment ImportMeta for Vite env vars used in the app
interface ImportMeta {
    readonly env: ImportMetaEnv;
}

interface ImportMetaEnv {
    readonly VITE_API_URL: string;
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
    // Phase 2 - VAPID public key for Web Push
    readonly VITE_VAPID_PUBLIC_KEY: string;
}

// SyncEvent is not in the standard TS DOM lib yet - used in sw.ts
interface SyncEvent extends ExtendableEvent {
    readonly tag: string;
    readonly lastChance: boolean;
}

interface ServiceWorkerRegistration {
    sync: {
        register(tag: string): Promise<void>;
        getTags(): Promise<string[]>;
    };
}