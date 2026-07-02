import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { VitePWA } from "vite-plugin-pwa";
import path from "path";

export default defineConfig({
    base: process.env.VITE_BASE_PATH ?? "/",

    plugins: [
        react(),

        VitePWA({
            // injectManifest mode: we own the SW file (src/sw.ts).
            // Workbox injects the precache manifest into it at build time
            // via the __WB_MANIFEST placeholder.
            // This is required so our push + background-sync handlers
            // live in the same SW as the precache logic.
            strategies: "injectManifest",
            srcDir: "src",
            filename: "sw.ts",

            registerType: "autoUpdate",

            // Include all static assets in the precache manifest
            includeAssets: [
                "favicon.ico",
                "images/*.svg",
                "images/*.png",
                "icons/*.png",
            ],

            // ── Web App Manifest ──────────────────────────────────────────
            manifest: {
                id: "/admin/",
                name: "Bethany House",
                short_name: "Bethany House",
                description:
                    "Bethany House operations - POS, production, inventory & comms.",
                theme_color: "#ffffff",
                background_color: "#f8f7f4",
                display: "standalone",
                orientation: "portrait-primary",
                start_url: "./",
                scope: "./",

                // Relative paths — resolve to /admin/icons/... after Vite build
                icons: [
                    { src: "icons/icon-72.png",           sizes: "72x72",   type: "image/png" },
                    { src: "icons/icon-96.png",           sizes: "96x96",   type: "image/png" },
                    { src: "icons/icon-128.png",          sizes: "128x128", type: "image/png" },
                    { src: "icons/icon-144.png",          sizes: "144x144", type: "image/png" },
                    { src: "icons/icon-152.png",          sizes: "152x152", type: "image/png" },
                    { src: "icons/icon-192.png",          sizes: "192x192", type: "image/png", purpose: "any" },
                    { src: "icons/icon-384.png",          sizes: "384x384", type: "image/png" },
                    { src: "icons/icon-512.png",          sizes: "512x512", type: "image/png" },
                    { src: "icons/icon-512-maskable.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
                ],

                // Shortcut URLs relative to scope (./ = /admin/)
                shortcuts: [
                    {
                        name: "Point of Sale",
                        short_name: "POS",
                        description: "Open the POS terminal",
                        url: "./pos",
                        icons: [{ src: "icons/shortcut-pos.png", sizes: "192x192", type: "image/png" }],
                    },
                    {
                        name: "My Tasks",
                        short_name: "Tasks",
                        description: "View assigned production tasks",
                        url: "./production/my-tasks",
                        icons: [{ src: "icons/shortcut-tasks.png", sizes: "192x192", type: "image/png" }],
                    },
                    {
                        name: "Messages",
                        short_name: "Comms",
                        description: "Open team messaging",
                        url: "./comms",
                        icons: [{ src: "icons/shortcut-comms.png", sizes: "192x192", type: "image/png" }],
                    },
                ],

                // Screenshots unlock richer install UI on Chrome Android
                screenshots: [
                    {
                        src: "icons/screenshot-wide.png",
                        sizes: "1280x720",
                        type: "image/png",
                        form_factor: "wide",
                        label: "Bethany House Operations — Dashboard",
                    },
                    {
                        src: "icons/screenshot-mobile.png",
                        sizes: "390x844",
                        type: "image/png",
                        form_factor: "narrow",
                        label: "Bethany House Operations — Mobile",
                    },
                ],

                categories: ["business", "productivity"],
            },

            // ── injectManifest config ─────────────────────────────────────
            // Replaces the workbox:{} block used in generateSW mode.
            // Workbox injects the precache manifest into src/sw.ts at build
            // time — the sw.ts file handles all caching and push logic.
            injectManifest: {
                globPatterns: ["**/*.{js,css,html,ico,png,svg,woff,woff2,ttf,eot}"],
                globIgnores: [
                    "**/*.map",
                    "**/sw.js",
                    "**/workbox-*.js",
                    "**/*.webmanifest",
                ],
            },

            devOptions: {
                enabled: false,
                type: "module",
            },
        }),
    ],

    resolve: {
        alias: { "@": path.resolve(__dirname, "./src") },
    },

    server: {
        port: 5173,
        proxy: {
            "/api": {
                target: process.env.VITE_API_URL || "http://localhost:8001",
                changeOrigin: true,
            },
        },
    },

    build: {
        outDir: "dist",
        sourcemap: false,
        rollupOptions: {
            output: {
                manualChunks: {
                    vendor: ["react", "react-dom", "react-router-dom"],
                    query: ["@tanstack/react-query"],
                },
            },
        },
    },
});