import { create } from "zustand";
import { authApi } from "@/api/auth";
import { tokenStorage } from "@/api/client";
import { registerPush, unregisterPush } from "@/lib/pushRegistration";
import type { User, LoginCredentials } from "@/types";

interface AuthStore {
    user: User | null;
    token: string | null;
    isAuthenticated: boolean;
    isLoading: boolean;
    // Actions
    login: (
        credentials: LoginCredentials,
    ) => Promise<{ requires2fa: boolean; userId?: number }>;
    logout: () => Promise<void>;
    verify2fa: (userId: number, code: string) => Promise<void>;
    fetchMe: () => Promise<void>;
    setUser: (user: User) => void;
    clearAuth: () => void;
}

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
                set({ isLoading: false });
                return { requires2fa: true, userId: res.user_id };
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

    verify2fa: async (userId, code) => {
        set({ isLoading: true });
        try {
            const res = await authApi.verify2fa(userId, code);
            tokenStorage.set(res.token);
            set({
                user: res.user,
                token: res.token,
                isAuthenticated: true,
                isLoading: false,
            });

            // Phase 2 - register push after 2FA login
            registerPush();
        } catch (err) {
            set({ isLoading: false });
            throw err;
        }
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
        set({
            user: null,
            token: null,
            isAuthenticated: false,
            isLoading: false,
        });
    },
}));