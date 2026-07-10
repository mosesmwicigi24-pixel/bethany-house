import { useState, useCallback } from "react";
import React from "react";
import { useToastStore } from "@/store/toast.store";
import { tokenStorage } from "@/api/client";

// ── Types ─────────────────────────────────────────────────────────────────────

export type PdfDocumentType =
    | "purchase-orders"
    | "grn"
    | "purchase-returns"
    | "orders"
    | "returns"
    | "shipments"
    | "production-orders"
    | "stock-transfers"
    | "stock-adjustments"
    | "expenses"
    | "quotations";

export type PdfDocumentSubtype = "invoice" | undefined;

// ── Hook ──────────────────────────────────────────────────────────────────────

interface UsePdfDownloadResult {
    download: (
        type: PdfDocumentType,
        id: number,
        subtype?: PdfDocumentSubtype
    ) => Promise<boolean>;
    loading: boolean;
}

export function usePdfDownload(): UsePdfDownloadResult {
    const [loading, setLoading] = useState(false);
    const toast = useToastStore();

    const download = useCallback(
        async (
            type: PdfDocumentType,
            id: number,
            subtype?: PdfDocumentSubtype
        ): Promise<boolean> => {
            setLoading(true);
            try {
                const base =
                    import.meta.env.VITE_API_URL ?? "http://localhost:8000/api";
                const sub = subtype ? `/${subtype}` : "";
                const url = `${base}/v1/admin/pdf/${type}/${id}${sub}`;

                const token = tokenStorage.get() ?? "";

                const res = await fetch(url, {
                    method: "GET",
                    headers: {
                        Authorization: `Bearer ${token}`,
                        Accept: "application/pdf",
                    },
                });

                if (!res.ok) {
                    const err = await res
                        .json()
                        .catch(() => ({ message: "PDF generation failed." }));
                    toast.error(err.message ?? "Failed to generate PDF.");
                    return false;
                }

                const blob = await res.blob();
                const blobUrl = URL.createObjectURL(blob);

                const filename = deriveFilename(
                    res.headers.get("Content-Disposition"),
                    type,
                    id,
                    subtype
                );

                const a = document.createElement("a");
                a.href = blobUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(() => URL.revokeObjectURL(blobUrl), 5000);

                return true;
            } catch (err: any) {
                toast.error(err?.message ?? "An error occurred while downloading the PDF.");
                return false;
            } finally {
                setLoading(false);
            }
        },
        [toast]
    );

    return { download, loading };
}

// ── helpers ───────────────────────────────────────────────────────────────────

function deriveFilename(
    contentDisposition: string | null,
    type: string,
    id: number,
    subtype?: string
): string {
    if (contentDisposition) {
        const match = contentDisposition.match(/filename="?([^";\n]+)"?/i);
        if (match?.[1]) return match[1];
    }
    const label =
        subtype === "invoice"
            ? "Invoice"
            : type
                  .split("-")
                  .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
                  .join("-");
    return `${label}-${id}.pdf`;
}

// ── PdfDownloadButton component ───────────────────────────────────────────────

interface PdfDownloadButtonProps {
    type: PdfDocumentType;
    id: number;
    subtype?: PdfDocumentSubtype;
    label?: string;
    className?: string;
    iconOnly?: boolean;
}

export function PdfDownloadButton({
    type,
    id,
    subtype,
    label = "Download PDF",
    className = "",
    iconOnly = false,
}: PdfDownloadButtonProps) {
    const { download, loading } = usePdfDownload();

    return (
        <button
            type="button"
            className={`btn-secondary btn-sm flex items-center gap-1.5 ${className}`}
            disabled={loading}
            onClick={() => download(type, id, subtype)}
            title={iconOnly ? label : undefined}
        >
            {loading ? (
                <svg
                    className="w-3.5 h-3.5 animate-spin"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle
                        className="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        strokeWidth="4"
                    />
                    <path
                        className="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8v8z"
                    />
                </svg>
            ) : (
                <svg
                    className="w-3.5 h-3.5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a1 1 0 001 1h16a1 1 0 001-1v-3"
                    />
                </svg>
            )}
            {!iconOnly && (
                <span>{loading ? "Generating…" : label}</span>
            )}
        </button>
    );
}