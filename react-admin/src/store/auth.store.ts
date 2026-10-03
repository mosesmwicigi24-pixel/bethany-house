import { create } from "zustand";
import { authApi } from "@/api/auth";
import { tokenStorage } from "@/api/client";
import { registerPush, unregisterPush } from "@/lib/pushRegistration";
import { resetSecurityPrompts } from "@/store/security.store";
import type { User, LoginCredentials, LoginResponse } from "@/types";

interface AuthStore {
    user: User | null;
    token: string | null;
    isAuthenticated: boolean;
    isLoading: boolean;
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

        set({ isLoading: true });
        try {
            const { user } = await authApi.me();
            set({ user, isAuthenticated: true, isLoading: false });

            // Phase 2 - re-register on page refresh in case the subscription
            // row was cleared from the DB (e.g. after db:fresh in dev)
            registerPush();
        } catch {
            get().clearAuth();
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
        });
    },
}));