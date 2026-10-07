import { create } from "zustand";
import { authApi } from "@/api/auth";
import { tokenStorage } from "@/api/client";
import { registerPush, unregisterPush } from "@/lib/pushRegistration";
import { resetSecurityPrompts } from "@/store/security.store";
import type { User, LoginCredentials, LoginResponse, ApiError } from "@/types";

/**
 * Why the signed-in user could not be loaded at start-up, when the session
 * itself was never refused: no connection, or the server failed or was busy.
 * The token is kept — only the server can end a session (401/403).
 */
export type StartupError = "offline" | "server";

interface AuthStore {
    user: User | null;
    token: string | null;
    isAuthenticated: boolean;
    isLoading: boolean;
    startupError: StartupError | null;
    // Actions
    login: (
        credentials: LoginCredentials,
    ) => Promise<{ requires2fa: boolean; requires2faSetup?: boolean; userId?: number }>;
    logout: () => Promise<void>;
    verify2fa: (userId: number, code: string, useRecoveryCode?: boolean) => Promise<LoginResponse>;
    /** Staged 2FA rollout: a secret + QR for the person setting 2FA up at sign-in. */
    start2faSetup: (userId: number) => Promise<{ secret_key: string; qr_code_url: string }>;
    /** Confirms the first code. Returns the session WITHOUT applying it, so the
     *  recovery codes can be shown first; completeLogin() applies it. */
    confirm2faSetup: (userId: number, code: string) => Promise<LoginResponse>;
    completeLogin: (res: LoginResponse) => void;
    fetchMe: () => Promise<void>;
    setUser: (user: User) => void;
    clearAuth: () => void;
}

// The password step's one-time proof, held only until the code is entered.
// Kept out of the store state so it never reaches persistence or devtools.
let pending2faChallenge: string | null = null;
// Same for the staged-rollout setup step's proof.
let pending2faSetup: string | null = null;

export const useAuthStore = create<AuthStore>((set, get) => ({
    user: null,
    token: tokenStorage.get(),
    isAuthenticated: !!tokenStorage.get(),
    isLoading: false,
    startupError: null,

    login: async (credentials) => {
        set({ isLoading: true });
        try {
            const res = await authApi.login(credentials);

            if (res.requires_2fa && res.user_id) {
                pending2faChallenge = res.challenge ?? null;
                set({ isLoading: false });
                return { requires2fa: true, userId: res.user_id };
            }

            if (res.requires_2fa_setup && res.user_id) {
                pending2faSetup = res.setup_token ?? null;
                set({ isLoading: false });
                return { requires2fa: false, requires2faSetup: true, userId: res.user_id };
            }

            tokenStorage.set(res.token);
            set({
                user: res.user,
                token: res.token,
                isAuthenticated: true,
                isLoading: false,
            });

            // Phase 2 - register push subscription for this device
            registerPush();

            return { requires2fa: false };
        } catch (err) {
            set({ isLoading: false });
            throw err;
        }
    },

    verify2fa: async (userId, code, useRecoveryCode = false) => {
        set({ isLoading: true });
        try {
            const res = await authApi.verify2fa(userId, code, pending2faChallenge ?? '', useRecoveryCode);
            pending2faChallenge = null;
            get().completeLogin(res);
            return res;
        } catch (err) {
            set({ isLoading: false });
            throw err;
        }
    },

    start2faSetup: async (userId) => {
        return authApi.setup2fa(userId, pending2faSetup ?? '');
    },

    confirm2faSetup: async (userId, code) => {
        set({ isLoading: true });
        try {
            const res = await authApi.confirm2faSetup(userId, pending2faSetup ?? '', code);
            pending2faSetup = null;
            set({ isLoading: false });
            return res;
        } catch (err) {
            set({ isLoading: false });
            throw err;
        }
    },

    completeLogin: (res) => {
        tokenStorage.set(res.token);
        set({
            user: res.user,
            token: res.token,
            isAuthenticated: true,
            isLoading: false,
        });

        // Phase 2 - register push after login
        registerPush();
    },

    logout: async () => {
        // Phase 2 - unregister push best-effort.
        // MUST be in its own try/catch — if it throws (no SW, push not
        // supported, network error) it must NOT block clearAuth().
        try {
            await unregisterPush();
        } catch {
            // Non-critical — always continue to clear auth
        }

        // A shared tablet: the next person must not open the last one's
        // session or task list from the offline cache (sw.ts).
        try {
            await Promise.all([caches.delete("api-my-tasks"), caches.delete("api-session")]);
        } catch {
            // No Cache Storage (private mode, old browser) — nothing cached.
        }

        try {
            await authApi.logout();
        } catch {
            // Swallow — clear locally regardless
        } finally {
            get().clearAuth();
        }
    },

    fetchMe: async () => {
        const token = tokenStorage.get();
        if (!token) return;
        // RequireAuth and HomeRedirect can both ask on the same render.
        if (get().isLoading) return;

        // startupError stays until an attempt succeeds, so a retry shows
        // "trying again" on the problem screen rather than a blank spinner.
        set({ isLoading: true });
        try {
            const { user } = await authApi.me();
            set({ user, isAuthenticated: true, isLoading: false, startupError: null });

            // Phase 2 - re-register on page refresh in case the subscription
            // row was cleared from the DB (e.g. after db:fresh in dev)
            registerPush();
        } catch (err) {
            const status = (err as ApiError | undefined)?.status;
            // Only the server can end a session: 401 (token gone, expired or
            // idle-ended — SessionPolicy) or 403 (no longer active staff —
            // EnsureStaff). No connection, a 5xx or a 429 says nothing about
            // the session, so the token stays and the person can try again
            // instead of retyping their password on a shop-floor tablet.
            if (status === 401 || status === 403) {
                get().clearAuth();
                return;
            }
            const offline = status === undefined
                && typeof navigator !== "undefined" && navigator.onLine === false;
            set({ isLoading: false, startupError: offline ? "offline" : "server" });
        }
    },

    setUser: (user) => set({ user }),

    clearAuth: () => {
        tokenStorage.remove();
        // Nothing waits on a PIN or a step-up once signed out.
        resetSecurityPrompts();
        set({
            user: null,
            token: null,
            isAuthenticated: false,
            isLoading: false,
            startupError: null,
        });
    },
}));