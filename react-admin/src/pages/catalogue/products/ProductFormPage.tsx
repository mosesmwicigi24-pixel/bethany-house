import React, { useState, useEffect, useRef, useCallback, useMemo } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm, useFieldArray, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useEditor, EditorContent } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import {
    DndContext,
    closestCenter,
    PointerSensor,
    useSensor,
    useSensors,
    type DragEndEvent,
} from "@dnd-kit/core";
import {
    SortableContext,
    horizontalListSortingStrategy,
    useSortable,
    arrayMove,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { productsApi } from "@/api/products";
import { get } from "@/api/client";
import type { ProductImage, ProductVariant } from "@/api/products";
import { categoriesApi } from "@/api/categories";
import { currenciesApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import {
    Field, useFieldAriaProps,
    Toggle,
    ConfirmDialog,
    FieldInput, FieldSelect, FieldTextarea
} from "@/components/setup/FormComponents";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import BomTab from "./components/BomTab";
import TaxRateSelector from "@/components/products/TaxRateSelector";
import { settingsApi } from "@/api/setup";
import type { ApiError } from "@/types";
import type { ProductTaxRate } from "@/api/products";
import { clsx } from "clsx";

// ── Schema ────────────────────────────────────────────────────────────────────

const schema = z.object({
    sku: z.string().min(1, "SKU required"),
    slug: z.string().optional(),
    category_id: z.number().nullable(),
    product_type: z.enum(["simple", "variable", "made_to_order"]),
    status: z.enum(["draft", "active", "inactive", "archived"]),
    is_featured: z.boolean(),
    is_producible: z.boolean(),
    brand: z.string().optional(),
    tax_class: z.string().default("standard"),
    low_stock_threshold: z.coerce.number().int().min(0),
    weight: z.coerce.number().min(0).nullable().optional(),
    length: z.coerce.number().min(0).nullable().optional(),
    width: z.coerce.number().min(0).nullable().optional(),
    height: z.coerce.number().min(0).nullable().optional(),
    translations: z
        .array(
            z.object({
                language_code: z.string(),
                name: z.string().min(1, "Name required"),
                description: z
                    .string()
                    .refine(
                        (val) => {
                            // Strip HTML tags and trim whitespace — an empty editor emits "<p></p>"
                            const text = val.replace(/<[^>]*>/g, "").trim();
                            return text.length > 0;
                        },
                        { message: "Description required" },
                    ),
                short_description: z.string().optional(),
            }),
        )
        .min(1),
    prices: z
        .array(
            z.object({
                currency_code: z.string(),
                regular_price: z.coerce.number().min(0),
                sale_price: z.coerce.number().min(0).nullable().optional(),
                cost_price: z.coerce.number().min(0).nullable().optional(),
                sale_start_date: z.string().nullable().optional(),
                sale_end_date: z.string().nullable().optional(),
            }),
        )
        .min(1),
    seo: z
        .array(
            z.object({
                language_code: z.string(),
                meta_title: z.string().max(60).optional(),
                meta_description: z.string().max(160).optional(),
                meta_keywords: z.string().optional(),
                canonical_url: z.string().optional(),
                og_title: z.string().optional(),
                og_description: z.string().optional(),
            }),
        )
        .optional(),
    measurements: z
        .array(
            z.object({
                name: z.string().min(1, "Name required"),
                unit: z.string().optional(),
                required: z.boolean(),
            }),
        )
        .optional(),
    tax_rate_ids: z.array(z.number()).optional().default([]),
    production_stage_ids: z.array(z.number()).optional().default([]),
});

type FormValues = z.infer<typeof schema>;
type Tab = "content" | "media" | "pricing" | "variants" | "seo" | "measurements" | "stages" | "bom";

const BASE_TABS: { id: Tab; label: string }[] = [
    { id: "content", label: "Content" },
    { id: "media", label: "Images" },
    { id: "pricing", label: "Pricing" },
    { id: "variants", label: "Variants" },
    { id: "seo", label: "SEO" },
];

const PRODUCTION_TABS: { id: Tab; label: string }[] = [
    { id: "measurements", label: "Measurements" },
    { id: "stages", label: "Production Stages" },
    { id: "bom", label: "Bill of Materials" },
];

// ── SKU generator ─────────────────────────────────────────────────────────────

function generateSku(
    categoryName: string,
    productName: string,
    existingSkus: string[] = [],
): string {
    // Category code - first 3 letters of first word
    const catCode = categoryName
        ? categoryName
              .replace(/[^a-zA-Z\s]/g, "")
              .split(/\s+/)[0]
              .slice(0, 3)
              .toUpperCase()
        : "GEN";

    // Name initials - up to 3 words
    const nameCode = productName
        ? productName
              .replace(/[^a-zA-Z\s]/g, "")
              .split(/\s+/)
              .slice(0, 3)
              .map((w) => w[0])
              .join("")
              .toUpperCase()
        : "PRD";

    const base = `${catCode}-${nameCode}`;

    // Find next sequential number
    let seq = 1;
    while (existingSkus.includes(`${base}-${String(seq).padStart(3, "0")}`)) {
        seq++;
    }

    return `${base}-${String(seq).padStart(3, "0")}`;
}

// ── WYSIWYG editor ────────────────────────────────────────────────────────────

interface RichEditorProps {
    value: string;
    onChange: (html: string) => void;
    placeholder?: string;
    error?: boolean;
    minHeight?: number;
}

function RichEditor({
    value,
    onChange,
    placeholder,
    error,
    minHeight = 160,
}: RichEditorProps) {
    const editor = useEditor({
        extensions: [
            StarterKit,
            Placeholder.configure({
                placeholder: placeholder ?? "Start typing…",
            }),
        ],
        content: value || "",
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: {
            attributes: {
                class: "prose prose-sm max-w-none focus:outline-none px-3 py-2.5",
                style: `min-height: ${minHeight}px`,
            },
        },
    });

    // Sync external value changes (e.g. when form resets)
    useEffect(() => {
        if (editor && value !== undefined) {
            const current = editor.getHTML();
            if (current !== value && (value === "" || value === "<p></p>")) {
                editor.commands.setContent(value);
            }
        }
    }, [value, editor]);

    if (!editor) return null;

    return (
        <div
            className={clsx(
                "border rounded-xl overflow-hidden bg-white transition-colors",
                error
                    ? "border-danger"
                    : "border-surface-200 focus-within:border-brand-400",
            )}
        >
            {/* Toolbar */}
            <div className="flex items-center gap-0.5 px-2 py-1.5 border-b border-surface-100 bg-surface-50 flex-wrap">
                {[
                    {
                        title: "Bold",
                        cmd: () => editor.chain().focus().toggleBold().run(),
                        active: editor.isActive("bold"),
                        icon: <span className="font-bold text-sm">B</span>,
                    },
                    {
                        title: "Italic",
                        cmd: () => editor.chain().focus().toggleItalic().run(),
                        active: editor.isActive("italic"),
                        icon: <span className="italic text-sm">I</span>,
                    },
                ].map((btn) => (
                    <button
                        key={btn.title}
                        title={btn.title}
                        type="button"
                        onClick={btn.cmd}
                        className={clsx(
                            "w-7 h-7 rounded flex items-center justify-center transition-colors text-sm",
                            btn.active
                                ? "bg-brand-100 text-brand-700"
                                : "text-surface-500 hover:bg-surface-200",
                        )}
                    >
                        {btn.icon}
                    </button>
                ))}
                <div className="w-px h-4 bg-surface-200 mx-0.5" />
                {[
                    {
                        title: "Bullet list",
                        cmd: () =>
                            editor.chain().focus().toggleBulletList().run(),
                        active: editor.isActive("bulletList"),
                        icon: (
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
                                    d="M4 6h16M4 10h16M4 14h16M4 18h16"
                                />
                            </svg>
                        ),
                    },
                    {
                        title: "Ordered list",
                        cmd: () =>
                            editor.chain().focus().toggleOrderedList().run(),
                        active: editor.isActive("orderedList"),
                        icon: (
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
                                    d="M9 5h11M9 12h11M9 19h11M4 5v.01M4 12v.01M4 19v.01"
                                />
                            </svg>
                        ),
                    },
                ].map((btn) => (
                    <button
                        key={btn.title}
                        title={btn.title}
                        type="button"
                        onClick={btn.cmd}
                        className={clsx(
                            "w-7 h-7 rounded flex items-center justify-center transition-colors",
                            btn.active
                                ? "bg-brand-100 text-brand-700"
                                : "text-surface-500 hover:bg-surface-200",
                        )}
                    >
                        {btn.icon}
                    </button>
                ))}
                <div className="w-px h-4 bg-surface-200 mx-0.5" />
                <button
                    title="Clear formatting"
                    type="button"
                    onClick={() =>
                        editor
                            .chain()
                            .focus()
                            .clearNodes()
                            .unsetAllMarks()
                            .run()
                    }
                    className="w-7 h-7 rounded flex items-center justify-center text-surface-400 hover:bg-surface-200 transition-colors text-xs"
                >
                    ✕
                </button>
            </div>
            <EditorContent editor={editor} />
        </div>
    );
}

// ── Sortable image ────────────────────────────────────────────────────────────

function SortableImage({
    image,
    onSetPrimary,
    onDelete,
    onZoom,
}: {
    image: ProductImage;
    onSetPrimary: (id: number) => void;
    onDelete: (id: number) => void;
    onZoom: (image: ProductImage) => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: image.id });
    const { can } = usePermissions();
    const canEdit = can("products.edit");
    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
                opacity: isDragging ? 0.3 : 1,
            }}
            className={clsx(
                "relative rounded-xl overflow-hidden border-2 group aspect-square",
                image.is_primary ? "border-brand-400" : "border-surface-200",
            )}
        >
            {canEdit && (
            <div
                {...attributes}
                {...listeners}
                className="absolute top-1 left-1 z-10 cursor-grab touch-none opacity-0 group-hover:opacity-100 transition-opacity"
            >
                <div className="w-5 h-5 bg-black/50 rounded flex items-center justify-center">
                    <svg
                        className="w-3 h-3 text-white"
                        fill="currentColor"
                        viewBox="0 0 16 16"
                    >
                        <circle cx="5" cy="4" r="1" />
                        <circle cx="5" cy="8" r="1" />
                        <circle cx="5" cy="12" r="1" />
                        <circle cx="11" cy="4" r="1" />
                        <circle cx="11" cy="8" r="1" />
                        <circle cx="11" cy="12" r="1" />
                    </svg>
                </div>
            </div>
            )}
            <img
                src={image.image_url}
                alt=""
                onClick={() => onZoom(image)}
                className="w-full h-full object-cover cursor-zoom-in"
            />
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-all flex flex-col items-center justify-center gap-1.5">
                {image.is_primary ? (
                    <span className="absolute bottom-1.5 left-1.5 text-2xs bg-brand-500 text-white px-1.5 py-0.5 rounded-full font-medium">
                        Primary
                    </span>
                ) : canEdit ? (
                    <button
                        onClick={() => onSetPrimary(image.id)}
                        className="opacity-0 group-hover:opacity-100 text-2xs bg-white text-surface-700 px-2 py-1 rounded-lg font-medium transition-opacity shadow"
                    >
                        Set primary
                    </button>
                ) : null}
                {/* Zoom trigger - distinct from the image-itself click so it's
                    discoverable even before the user realizes the thumbnail
                    is clickable, and works the same when clicked directly.
                    Bottom-right is the only corner not already occupied:
                    top-left is the drag handle, top-right is delete, and
                    bottom-left is the primary badge / "Set primary" button. */}
                <button
                    onClick={() => onZoom(image)}
                    aria-label="View full image"
                    className="opacity-0 group-hover:opacity-100 w-6 h-6 bg-white/90 rounded-full flex items-center justify-center transition-opacity absolute bottom-1.5 right-1.5"
                >
                    <svg className="w-3.5 h-3.5 text-surface-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" />
                    </svg>
                </button>
                {canEdit && (
                <button
                    onClick={() => onDelete(image.id)}
                    className="opacity-0 group-hover:opacity-100 w-6 h-6 bg-danger rounded-full flex items-center justify-center transition-opacity absolute top-1.5 right-1.5"
                    aria-label="Close"
                >
                    <svg
                        className="w-3 h-3 text-white"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={3}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
                )}
            </div>
        </div>
    );
}

// ── Image zoom modal ──────────────────────────────────────────────────────────
// Full-size preview of a single product image. Uses the shared Modal
// primitive already used throughout this page, sized large with no padding
// around the image itself so it can fill the available space.

function ImageZoomModal({
    images,
    index,
    onNavigate,
    onClose,
}: {
    images: ProductImage[];
    /** Index into `images` of the currently-zoomed image, or null when closed. */
    index: number | null;
    onNavigate: (index: number) => void;
    onClose: () => void;
}) {
    const hasMultiple = images.length > 1;

    const goPrev = useCallback(() => {
        if (index === null) return;
        onNavigate(index === 0 ? images.length - 1 : index - 1);
    }, [index, images.length, onNavigate]);

    const goNext = useCallback(() => {
        if (index === null) return;
        onNavigate(index === images.length - 1 ? 0 : index + 1);
    }, [index, images.length, onNavigate]);

    // Arrow-key navigation while the modal is open - lets the user flip
    // through the whole gallery without touching the mouse. Modal itself
    // already handles Escape-to-close, so we only need Left/Right here.
    useEffect(() => {
        if (index === null) return;
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === "ArrowLeft") goPrev();
            else if (e.key === "ArrowRight") goNext();
        };
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, [index, goPrev, goNext]);

    if (index === null) return null;
    const image = images[index];
    if (!image) return null;

    return (
        <Modal open title="" onClose={onClose} size="lg">
            {/* Negative margins cancel out Modal's fixed body padding
                (px-6 py-5) since title="" suppresses the header entirely and
                this content should sit close to the modal's edges instead of
                floating in a large empty frame. */}
            <div className="relative -mx-6 -my-5 flex items-center justify-center bg-surface-50 p-2 rounded-2xl overflow-hidden">
                {/* Close button - Modal's own header close button only
                    renders when `title` is set, and this modal deliberately
                    has none, so a dedicated one lives here instead. */}
                <button
                    onClick={onClose}
                    aria-label="Close"
                    className="absolute top-2 right-2 w-8 h-8 bg-white/90 hover:bg-white rounded-full flex items-center justify-center shadow transition-colors z-20"
                >
                    <svg className="w-4 h-4 text-surface-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                {hasMultiple && (
                    <button
                        onClick={goPrev}
                        aria-label="Previous image"
                        className="absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-white/90 hover:bg-white rounded-full flex items-center justify-center shadow transition-colors z-10"
                    >
                        <svg className="w-4 h-4 text-surface-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </button>
                )}

                <img
                    src={image.image_url}
                    alt=""
                    className="max-w-full max-h-[75vh] object-contain rounded-lg"
                />

                {hasMultiple && (
                    <button
                        onClick={goNext}
                        aria-label="Next image"
                        className="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-white/90 hover:bg-white rounded-full flex items-center justify-center shadow transition-colors z-10"
                    >
                        <svg className="w-4 h-4 text-surface-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                )}

                {hasMultiple && (
                    <span className="absolute bottom-2 left-1/2 -translate-x-1/2 text-2xs font-medium text-surface-500 bg-white/90 px-2 py-0.5 rounded-full">
                        {index + 1} / {images.length}
                    </span>
                )}
            </div>
        </Modal>
    );
}

// ── Attribute-based variant generator ────────────────────────────────────────

interface AttributeGroup {
    type: string;
    values: string[];
}

interface VariantGeneratorProps {
    open: boolean;
    onClose: () => void;
    productId: number;
    currencies: any[];
    productSku: string;
    productName: string;
    onSaved: () => void;
    /** Product-level prices from the main form — used to pre-fill variant prices */
    productPrices?: { currency_code: string; regular_price: number; sale_price?: number | null; cost_price?: number | null }[];
}

// Colour leads, the rest explains — mirror of the backend
// ProductVariant::composeName (the server is authoritative on save; this is the
// live preview). "White Princes Cassock + Black Piping, Buttons and Pleats".
function joinWithAnd(items: string[]): string {
    if (items.length <= 1) return items[0] ?? "";
    if (items.length === 2) return `${items[0]} and ${items[1]}`;
    return `${items.slice(0, -1).join(", ")} and ${items[items.length - 1]}`;
}

const isSizeKey = (k: string) => ["size", "sizes"].includes(k.trim().toLowerCase());
const isColourKey = (k: string) => ["colour", "color"].includes(k.trim().toLowerCase());
const isFeaturesKey = (k: string) => ["features", "feature"].includes(k.trim().toLowerCase());

function composeVariantName(productName: string, attrs: Record<string, string>): string {
    const base = (productName.replace(/\s+[\u2013\u2014-]\s+\S.*$/u, "").trim() || productName.trim());

    // Size is a pick-your-size option, never part of the name.
    const entries = Object.entries(attrs).filter(
        ([k, v]) => typeof v === "string" && v.trim() !== "" && !isSizeKey(k),
    );
    if (entries.length === 0) return base;

    // Features role: the colour colours the features, the garment leads —
    // "White Princes Cassock + Black Piping, Pleats and Buttons".
    const featuresIdx = entries.findIndex(([k]) => isFeaturesKey(k));
    if (featuresIdx >= 0) {
        const colour = entries.find(([k]) => isColourKey(k))?.[1].trim() ?? "";
        const coloured = `${colour} ${entries[featuresIdx][1].trim()}`.trim();
        const extra = entries
            .filter(([k], i) => i !== featuresIdx && !isColourKey(k))
            .map(([, v]) => v.trim());
        return `${base} + ${[coloured, ...extra].join(", ")}`.trim();
    }

    // No features: the colour leads as the body colour.
    const colourIdx = entries.findIndex(([k]) => isColourKey(k));
    const mainIdx = colourIdx >= 0 ? colourIdx : 0;
    const mainValue = entries[mainIdx][1].trim();
    const lead = `${mainValue} ${base}`.trim();

    const groups: { value: string; labels: string[] }[] = [];
    entries.forEach(([label, value], i) => {
        if (i === mainIdx) return;
        const v = value.trim();
        const g = groups.find((x) => x.value === v);
        if (g) g.labels.push(label);
        else groups.push({ value: v, labels: [label] });
    });
    if (groups.length === 0) return lead;

    const parts = groups.map((g) =>
        groups.length === 1 && g.labels.length === 1
            ? g.value
            : `${g.value} ${joinWithAnd(g.labels)}`.trim(),
    );
    return `${lead} + ${parts.join(", ")}`;
}

function VariantGenerator({
    open,
    onClose,
    productId,
    currencies,
    productSku,
    productName,
    onSaved,
    productPrices = [],
}: VariantGeneratorProps) {
    const toast = useToastStore();
    const [attributes, setAttributes] = useState<AttributeGroup[]>([
        { type: "Colour", values: [] },
        { type: "Features", values: [] },
        { type: "Size", values: [] },
    ]);
    const [newAttrType, setNewAttrType] = useState("");
    const [valueInputs, setValueInputs] = useState<Record<string, string>>({});
    const [generatedVariants, setGeneratedVariants] = useState<any[]>([]);
    const [step, setStep] = useState<"attributes" | "prices">("attributes");

    // Role-aware generation:
    //  · Colour and Size are the axes → a variant per colour × size (each a
    //    distinct sellable SKU).
    //  · Features are COLLAPSED into one coloured descriptor (all features take
    //    the colour) — never a cartesian axis, so no "S / Grey / Piping".
    //  · Size is kept out of the name but present as its own attribute + in SKU.
    const combineAttributes = (
        groups: AttributeGroup[],
    ): Record<string, string>[] => {
        const colour = groups.find((g) => isColourKey(g.type) && g.values.length > 0);
        const size = groups.find((g) => isSizeKey(g.type) && g.values.length > 0);
        // Every non-colour, non-size value is a feature; join them once.
        const featureValues = groups
            .filter((g) => !isColourKey(g.type) && !isSizeKey(g.type))
            .flatMap((g) => g.values);
        const featuresText = joinWithAnd(featureValues);

        const colourVals = colour ? colour.values : [""];
        const sizeVals = size ? size.values : [""];
        if (!colour && !size && featureValues.length === 0) return [];

        const out: Record<string, string>[] = [];
        for (const c of colourVals) {
            for (const sz of sizeVals) {
                const combo: Record<string, string> = {};
                if (c) combo["Colour"] = c;
                if (featuresText) combo["Features"] = featuresText;
                if (sz) combo["Size"] = sz;
                out.push(combo);
            }
        }
        return out;
    };

    const combinations = useMemo(
        () => combineAttributes(attributes),
        [attributes],
    );

    const addValue = (attrType: string) => {
        const val = (valueInputs[attrType] ?? "").trim();
        if (!val) return;
        setAttributes((prev) =>
            prev.map((a) =>
                a.type === attrType
                    ? { ...a, values: [...new Set([...a.values, val])] }
                    : a,
            ),
        );
        setValueInputs((prev) => ({ ...prev, [attrType]: "" }));
    };

    const removeValue = (attrType: string, val: string) => {
        setAttributes((prev) =>
            prev.map((a) =>
                a.type === attrType
                    ? { ...a, values: a.values.filter((v) => v !== val) }
                    : a,
            ),
        );
    };

    const removeAttr = (attrType: string) => {
        setAttributes((prev) => prev.filter((a) => a.type !== attrType));
    };

    const addAttrGroup = () => {
        const t = newAttrType.trim();
        if (!t || attributes.some((a) => a.type === t)) return;
        setAttributes((prev) => [...prev, { type: t, values: [] }]);
        setNewAttrType("");
    };

    const goToStep2 = () => {
        if (combinations.length === 0) {
            toast.error(
                "Add at least one attribute value to generate variants.",
            );
            return;
        }
        const variants = combinations.map((attrs, i) => ({
            attrs,
            name: composeVariantName(productName, attrs),
            // SKU carries the axes that vary the stock — colour + size — not the
            // fixed features text.
            sku: `${productSku}-${[attrs["Colour"], attrs["Size"]]
                .filter(Boolean)
                .map((v) => v.slice(0, 3).toUpperCase())
                .join("-")}`,
            is_default: i === 0,
            prices: currencies.map((c) => {
                const productPrice = productPrices.find(
                    (p) => p.currency_code === c.code,
                );
                return {
                    currency_code: c.code,
                    regular_price: productPrice ? Number(productPrice.regular_price) : 0,
                    sale_price: productPrice?.sale_price ? Number(productPrice.sale_price) : null,
                    cost_price: productPrice?.cost_price ? Number(productPrice.cost_price) : null,
                };
            }),
        }));
        setGeneratedVariants(variants);
        setStep("prices");
    };

    const saveMutation = useMutation({
        mutationFn: async () => {
            for (const v of generatedVariants) {
                await productsApi.createVariant(productId, {
                    sku: v.sku.toUpperCase(),
                    // Server composes the canonical colour-led name from the
                    // product + attributes (ProductVariant::composeName).
                    auto_name: true,
                    attributes: v.attrs,
                    is_default: v.is_default,
                    is_active: true,
                    prices: v.prices,
                });
            }
        },
        onSuccess: () => {
            toast.success(`${generatedVariants.length} variant(s) created.`);
            onSaved();
            onClose();
            setStep("attributes");
            setAttributes([
                { type: "Size", values: [] },
                { type: "Colour", values: [] },
            ]);
            setGeneratedVariants([]);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const PRESET_SIZES = ["XS", "S", "M", "L", "XL", "XXL"];
    const PRESET_COLOURS = ["Black", "White", "Navy", "Red", "Green", "Grey"];

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Generate Variants from Attributes"
            size="lg"
            footer={
                <>
                    <button
                        onClick={() => {
                            if (step === "prices") setStep("attributes");
                            else onClose();
                        }}
                        className="btn-secondary btn-sm"
                    >
                        {step === "prices" ? "← Back" : "Cancel"}
                    </button>
                    {step === "attributes" ? (
                        <button
                            onClick={goToStep2}
                            className="btn-primary btn-sm"
                            disabled={combinations.length === 0}
                        >
                            Generate {combinations.length} Variant
                            {combinations.length !== 1 ? "s" : ""} →
                        </button>
                    ) : (
                        <button
                            onClick={() => saveMutation.mutate()}
                            disabled={saveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {saveMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Save {generatedVariants.length} Variants
                        </button>
                    )}
                </>
            }
        >
            {step === "attributes" ? (
                <div className="space-y-4">
                    <p className="text-xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                        Define attribute types (Size, Colour, Material…) and
                        their values. All combinations will be generated
                        automatically.
                    </p>

                    {attributes.map((attr) => (
                        <div
                            key={attr.type}
                            className="border border-surface-100 rounded-xl p-3 space-y-2"
                        >
                            <div className="flex items-center justify-between">
                                <p className="text-sm font-semibold text-surface-800">
                                    {attr.type}
                                </p>
                                <button
                                    onClick={() => removeAttr(attr.type)}
                                    className="text-xs text-danger hover:underline"
                                >
                                    Remove
                                </button>
                            </div>

                            {/* Preset chips */}
                            {attr.type === "Size" && (
                                <div className="flex gap-1 flex-wrap">
                                    {PRESET_SIZES.map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() =>
                                                setAttributes((prev) =>
                                                    prev.map((a) =>
                                                        a.type === "Size"
                                                            ? {
                                                                  ...a,
                                                                  values: [
                                                                      ...new Set(
                                                                          [
                                                                              ...a.values,
                                                                              s,
                                                                          ],
                                                                      ),
                                                                  ],
                                                              }
                                                            : a,
                                                    ),
                                                )
                                            }
                                            className={clsx(
                                                "text-xs px-2 py-0.5 rounded-full border transition-colors",
                                                attr.values.includes(s)
                                                    ? "bg-brand-100 border-brand-300 text-brand-700"
                                                    : "border-surface-200 text-surface-500 hover:border-brand-300",
                                            )}
                                        >
                                            {s}
                                        </button>
                                    ))}
                                </div>
                            )}
                            {attr.type === "Colour" && (
                                <div className="flex gap-1 flex-wrap">
                                    {PRESET_COLOURS.map((c) => (
                                        <button
                                            key={c}
                                            type="button"
                                            onClick={() =>
                                                setAttributes((prev) =>
                                                    prev.map((a) =>
                                                        a.type === "Colour"
                                                            ? {
                                                                  ...a,
                                                                  values: [
                                                                      ...new Set(
                                                                          [
                                                                              ...a.values,
                                                                              c,
                                                                          ],
                                                                      ),
                                                                  ],
                                                              }
                                                            : a,
                                                    ),
                                                )
                                            }
                                            className={clsx(
                                                "text-xs px-2 py-0.5 rounded-full border transition-colors",
                                                attr.values.includes(c)
                                                    ? "bg-brand-100 border-brand-300 text-brand-700"
                                                    : "border-surface-200 text-surface-500 hover:border-brand-300",
                                            )}
                                        >
                                            {c}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {/* Current values */}
                            <div className="flex gap-1.5 flex-wrap">
                                {attr.values.map((v) => (
                                    <span
                                        key={v}
                                        className="flex items-center gap-1 text-xs bg-brand-50 text-brand-700 border border-brand-200 px-2 py-0.5 rounded-full"
                                    >
                                        {v}
                                        <button
                                            onClick={() =>
                                                removeValue(attr.type, v)
                                            }
                                            className="text-brand-400 hover:text-brand-700 leading-none"
                                        >
                                            ×
                                        </button>
                                    </span>
                                ))}
                            </div>

                            {/* Add value */}
                            <div className="flex gap-2">
                                <input
                                    className="input text-sm flex-1"
                                    placeholder={`Add ${attr.type.toLowerCase()} value…`}
                                    value={valueInputs[attr.type] ?? ""}
                                    onChange={(e) =>
                                        setValueInputs((prev) => ({
                                            ...prev,
                                            [attr.type]: e.target.value,
                                        }))
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === "Enter") {
                                            e.preventDefault();
                                            addValue(attr.type);
                                        }
                                    }}
                                />
                                <button
                                    onClick={() => addValue(attr.type)}
                                    className="btn-secondary btn-sm px-3"
                                >
                                    Add
                                </button>
                            </div>
                        </div>
                    ))}

                    {/* Add attribute type */}
                    <div className="flex gap-2 pt-1">
                        <input
                            className="input text-sm flex-1"
                            placeholder="New attribute type (e.g. Material, Length)…"
                            value={newAttrType}
                            onChange={(e) => setNewAttrType(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === "Enter") {
                                    e.preventDefault();
                                    addAttrGroup();
                                }
                            }}
                        />
                        <button
                            onClick={addAttrGroup}
                            className="btn-secondary btn-sm"
                        >
                            + Add
                        </button>
                    </div>

                    {/* Preview */}
                    {combinations.length > 0 && (
                        <div className="bg-surface-50 rounded-xl p-3 border border-surface-100">
                            <p className="text-xs font-semibold text-surface-500 mb-2">
                                Preview - {combinations.length} variant{combinations.length !== 1 ? "s" : ""}
                                <span className="font-normal text-surface-400"> · colour leads, features are coloured, size is a separate option</span>
                            </p>
                            <div className="flex flex-col gap-1 max-h-28 overflow-y-auto">
                                {combinations.map((combo, i) => (
                                    <span
                                        key={i}
                                        className="text-xs text-surface-600 flex items-center gap-2"
                                    >
                                        <span className="font-medium text-surface-800">{composeVariantName(productName, combo)}</span>
                                        {combo["Size"] && (
                                            <span className="text-2xs bg-white border border-surface-200 text-surface-400 px-1.5 py-0.5 rounded-full shrink-0">
                                                size {combo["Size"]}
                                            </span>
                                        )}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            ) : (
                <div className="space-y-3">
                    <p className="text-xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                        Set pricing for each generated variant. SKUs are
                        auto-generated - edit them if needed.
                        {productPrices.some((p) => Number(p.regular_price) > 0) && (
                            <span className="ml-1 text-brand-600 font-medium">
                                Prices pre-filled from the product — adjust per variant if needed.
                            </span>
                        )}
                    </p>
                    <div className="max-h-96 overflow-y-auto space-y-3 pr-1">
                        {generatedVariants.map((v, vi) => (
                            <div
                                key={vi}
                                className="border border-surface-100 rounded-xl overflow-hidden"
                            >
                                {/* Merchandising name — colour leads, the rest explains */}
                                <div className="px-3 pt-2 pb-1 bg-surface-50">
                                    <p className="text-sm font-semibold text-surface-800">
                                        {v.name}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3 px-3 py-2 bg-surface-50 border-b border-surface-100">
                                    <div className="flex gap-1 flex-wrap flex-1">
                                        {Object.entries(v.attrs).map(
                                            ([k, val]) => (
                                                <span
                                                    key={k}
                                                    className="text-xs bg-white border border-surface-200 text-surface-600 px-1.5 py-0.5 rounded"
                                                >
                                                    {String(val)}
                                                </span>
                                            ),
                                        )}
                                        {vi === 0 && (
                                            <span className="text-2xs bg-brand-100 text-brand-600 px-1.5 py-0.5 rounded-full">
                                                Default
                                            </span>
                                        )}
                                    </div>
                                    <input
                                        className="input text-xs font-mono w-40 py-1"
                                        value={v.sku}
                                        onChange={(e) =>
                                            setGeneratedVariants((prev) =>
                                                prev.map((x, i) =>
                                                    i === vi
                                                        ? {
                                                              ...x,
                                                              sku: e.target.value.toUpperCase(),
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                                <div
                                    className="grid gap-2 px-3 py-2"
                                    style={{
                                        gridTemplateColumns: `repeat(${currencies.length * 3}, 1fr)`,
                                    }}
                                >
                                    {currencies.map((c: any, ci: number) => (
                                        <div key={c.code} className="contents">
                                            <div
                                                className="col-span-3 text-xs font-mono font-semibold text-surface-500 pb-0.5 border-b border-surface-50"
                                                style={{
                                                    gridColumn: `${ci * 3 + 1} / ${ci * 3 + 4}`,
                                                }}
                                            >
                                                {c.code}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div
                                    className="grid px-3 pb-2.5"
                                    style={{
                                        gridTemplateColumns: `repeat(${currencies.length}, 1fr)`,
                                        gap: "8px",
                                    }}
                                >
                                    {(() => {
                                        const baseCur = currencies.find((c: any) => c.is_base || c.is_default);
                                        const baseRate = (baseCur?.exchange_rate ?? 1) as number;
                                        return currencies.map((c: any, ci: number) => {
                                            const isBase = c.is_base || c.is_default;
                                            const thisRate = (c.exchange_rate ?? 1) as number;
                                            return (
                                                <div key={c.code} className={`space-y-1 rounded p-1.5 ${isBase ? "bg-brand-50 border border-brand-200" : ""}`}>
                                                    <p className={`text-2xs font-mono font-semibold ${isBase ? "text-brand-600" : "text-surface-400"}`}>
                                                        {c.code}{isBase && <span className="ml-1 normal-case font-normal">★</span>}
                                                    </p>
                                                    <input
                                                        className="input text-xs py-1"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        placeholder="Price"
                                                        value={v.prices[ci]?.regular_price ?? ""}
                                                        onChange={(e) => {
                                                            const newVal = Number(e.target.value);
                                                            setGeneratedVariants((prev) =>
                                                                prev.map((x, i) => {
                                                                    if (i !== vi) return x;
                                                                    let newPrices = x.prices.map((p: any, j: number) =>
                                                                        j === ci ? { ...p, regular_price: newVal } : p
                                                                    );
                                                                    // If this is base currency, auto-convert others
                                                                    if (isBase && baseRate > 0) {
                                                                        newPrices = newPrices.map((p: any, j: number) => {
                                                                            if (j === ci) return p;
                                                                            const otherRate = (currencies[j].exchange_rate ?? 1) as number;
                                                                            if (otherRate > 0) {
                                                                                return { ...p, regular_price: Math.round((newVal / baseRate) * otherRate * 100) / 100 };
                                                                            }
                                                                            return p;
                                                                        });
                                                                    }
                                                                    return { ...x, prices: newPrices };
                                                                })
                                                            );
                                                        }}
                                                    />
                                                </div>
                                            );
                                        });
                                    })()}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Modal>
    );
}

// ── Edit single variant modal ─────────────────────────────────────────────────

const variantSchema = z.object({
    sku: z.string().min(1, "SKU required"),
    variant_name: z.string().min(1, "Name required"),
    attributes: z.record(z.string()),
    weight: z.coerce.number().min(0).nullable().optional(),
    is_default: z.boolean(),
    is_active: z.boolean(),
    prices: z
        .array(
            z.object({
                currency_code: z.string(),
                regular_price: z.coerce.number().min(0),
                sale_price: z.coerce.number().min(0).nullable().optional(),
                cost_price: z.coerce.number().min(0).nullable().optional(),
            }),
        )
        .min(1),
});
type VariantForm = z.infer<typeof variantSchema>;

// ── Variant image gallery ────────────────────────────────────────────────────
// Each colourway showcases its own look. The first (or starred) image is the
// variant's hero on the storefront; the rest fill its gallery. Scoped entirely
// to this variant — independent of the product's generic photos.

function VariantImagesSection({
    productId,
    variant,
    onChanged,
}: {
    productId: number;
    variant: ProductVariant;
    onChanged: () => void;
}) {
    const toast = useToastStore();
    const [images, setImages] = useState<ProductImage[]>(variant.images ?? []);
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        setImages(variant.images ?? []);
    }, [variant.id]);

    const upload = useCallback(
        async (files: FileList) => {
            setUploading(true);
            try {
                const { smartCompressImage } = await import("@/utils/compressImage");
                const optimized = await Promise.all(
                    Array.from(files).map((f) => smartCompressImage(f).catch(() => f)),
                );
                const res = await productsApi.uploadVariantImages(productId, variant.id, optimized);
                setImages((prev) => [...prev, ...res.images]);
                toast.success(`${res.images.length} image(s) added to ${variant.variant_name}.`);
                onChanged();
            } catch (e: any) {
                toast.error(e.message ?? "Upload failed.");
            } finally {
                setUploading(false);
                if (fileRef.current) fileRef.current.value = "";
            }
        },
        [productId, variant.id, variant.variant_name, toast, onChanged],
    );

    const setPrimary = useCallback(
        async (imageId: number) => {
            await productsApi.setPrimaryImage(productId, imageId);
            setImages((prev) => prev.map((img) => ({ ...img, is_primary: img.id === imageId })));
            onChanged();
        },
        [productId, onChanged],
    );

    const remove = useCallback(
        async (imageId: number) => {
            await productsApi.deleteImage(productId, imageId);
            setImages((prev) => {
                const next = prev.filter((img) => img.id !== imageId);
                // Mirror the server: if the hero went, promote the first survivor.
                if (!next.some((i) => i.is_primary) && next[0]) next[0].is_primary = true;
                return [...next];
            });
            onChanged();
        },
        [productId, onChanged],
    );

    return (
        <div>
            <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                    Images
                    <span className="ml-1.5 normal-case font-normal text-surface-400">
                        shown for this colourway on the website
                    </span>
                </p>
                <span className="text-2xs text-surface-400">{images.length} photo{images.length === 1 ? "" : "s"}</span>
            </div>

            <div className="grid grid-cols-4 sm:grid-cols-5 gap-2">
                {images
                    .slice()
                    .sort((a, b) => (b.is_primary ? 1 : 0) - (a.is_primary ? 1 : 0) || a.sort_order - b.sort_order)
                    .map((img) => (
                        <div
                            key={img.id}
                            className={clsx(
                                "relative group aspect-square rounded-lg overflow-hidden border-2",
                                img.is_primary ? "border-brand-500" : "border-surface-100",
                            )}
                        >
                            <img
                                src={img.thumbnail_url || img.image_url}
                                alt={img.alt_text ?? ""}
                                className="w-full h-full object-cover"
                            />
                            {img.is_primary && (
                                <span className="absolute top-1 left-1 text-2xs font-bold bg-brand-500 text-white px-1 py-0.5 rounded">
                                    Hero
                                </span>
                            )}
                            <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5">
                                {!img.is_primary && (
                                    <button
                                        type="button"
                                        onClick={() => setPrimary(img.id)}
                                        title="Make hero image"
                                        className="w-7 h-7 rounded-full bg-white/90 flex items-center justify-center hover:bg-white"
                                    >
                                        <svg className="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                        </svg>
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => remove(img.id)}
                                    title="Remove image"
                                    className="w-7 h-7 rounded-full bg-white/90 flex items-center justify-center hover:bg-white"
                                >
                                    <svg className="w-3.5 h-3.5 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    ))}

                {/* Upload tile */}
                <button
                    type="button"
                    onClick={() => fileRef.current?.click()}
                    disabled={uploading}
                    className="aspect-square rounded-lg border-2 border-dashed border-surface-200 flex flex-col items-center justify-center gap-1 text-surface-400 hover:border-brand-300 hover:text-brand-500 transition-colors disabled:opacity-50"
                >
                    {uploading ? (
                        <Spinner size="xs" />
                    ) : (
                        <>
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                            </svg>
                            <span className="text-2xs font-medium">Add</span>
                        </>
                    )}
                </button>
            </div>

            <input
                ref={fileRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="hidden"
                onChange={(e) => e.target.files?.length && upload(e.target.files)}
            />
        </div>
    );
}

function EditVariantModal({
    open,
    onClose,
    productId,
    editing,
    currencies,
    onSaved,
    productPrices = [],
}: {
    open: boolean;
    onClose: () => void;
    productId: number;
    editing: ProductVariant;
    currencies: any[];
    onSaved: () => void;
    /** Product-level prices — used to pre-fill when a variant has no price set for a currency */
    productPrices?: { currency_code: string; regular_price: number; sale_price?: number | null; cost_price?: number | null }[];
}) {
    const toast = useToastStore();
    const form = useForm<VariantForm>({
        resolver: zodResolver(variantSchema),
        mode: "onSubmit",
        reValidateMode: "onSubmit",
        defaultValues: {
            sku: "",
            variant_name: "",
            attributes: {},
            weight: null,
            is_default: false,
            is_active: true,
            prices: [],
        },
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    useEffect(() => {
        if (!open) return;
        reset({
            sku: editing.sku,
            variant_name: editing.variant_name,
            attributes: editing.attributes ?? {},
            weight: editing.weight ?? null,
            is_default: editing.is_default,
            is_active: editing.is_active,
            prices: currencies.map((c) => {
                const p = editing.prices?.find(
                    (x) => x.currency_code === c.code,
                );
                // Fall back to product-level price when the variant has no
                // price for this currency (e.g. newly added currency).
                const fallback = productPrices.find(
                    (x) => x.currency_code === c.code,
                );
                return {
                    currency_code: c.code,
                    regular_price: p
                        ? Number(p.regular_price)
                        : fallback
                          ? Number(fallback.regular_price)
                          : 0,
                    sale_price: p?.sale_price
                        ? Number(p.sale_price)
                        : (fallback?.sale_price ? Number(fallback.sale_price) : null),
                    cost_price: p?.cost_price
                        ? Number(p.cost_price)
                        : (fallback?.cost_price ? Number(fallback.cost_price) : null),
                };
            }),
        });
    }, [editing, open]);

    const mutation = useMutation({
        mutationFn: (v: VariantForm) =>
            productsApi.updateVariant(productId, editing.id, {
                sku: v.sku.toUpperCase(),
                variant_name: v.variant_name,
                attributes: v.attributes,
                weight: v.weight ?? null,
                is_default: v.is_default,
                is_active: v.is_active,
                prices: v.prices.map((p) => ({
                    ...p,
                    sale_price: p.sale_price ?? null,
                    cost_price: p.cost_price ?? null,
                })),
            }),
        onSuccess: () => {
            toast.success("Variant updated.");
            onSaved();
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const attrs = watch("attributes") ?? {};

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`Edit - ${editing?.variant_name}`}
            size="md"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={handleSubmit((v) => mutation.mutate(v))}
                        disabled={mutation.isPending}
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Save
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="SKU" error={errors.sku?.message} required>
                        <FieldInput
                            className={`input font-mono uppercase ${errors.sku ? "input-error" : ""}`}
                            {...register("sku")}
                        />
                    </Field>
                    <Field label="Variant Name">
                        <FieldInput
                            className="input"
                            {...register("variant_name")}
                        />
                    </Field>
                </div>

                {/* Images — the colourway's own showcase */}
                <VariantImagesSection
                    productId={productId}
                    variant={editing}
                    onChanged={onSaved}
                />

                {/* Attributes */}
                <div>
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                        Attributes
                    </p>
                    <div className="grid grid-cols-3 gap-2">
                        {Object.entries(attrs).map(([k, v]) => (
                            <Field key={k} label={k}>
                                <FieldInput
                                    className="input text-sm"
                                    value={String(v)}
                                    onChange={(e) =>
                                        setValue("attributes", {
                                            ...attrs,
                                            [k]: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                </div>

                {/* Pricing */}
                <div>
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                        Pricing
                    </p>
                    {currencies.map((currency, i) => {
                        const baseCur    = currencies.find((c: any) => c.is_base || c.is_default);
                        const isBase     = currency.code === baseCur?.code;
                        const baseRate   = (baseCur?.exchange_rate ?? 1) as number;
                        const thisRate   = (currency.exchange_rate ?? 1) as number;

                        return (
                            <div
                                key={currency.code}
                                className={`grid grid-cols-4 gap-2 items-end rounded-lg p-2.5 mb-2 ${isBase ? "bg-brand-50 border border-brand-200" : "bg-surface-50"}`}
                            >
                                <div className={`text-xs font-mono font-semibold ${isBase ? "text-brand-700" : "text-surface-600"}`}>
                                    {currency.code}
                                    {isBase && <span className="block text-2xs font-normal text-brand-500 normal-case">Default</span>}
                                    {!isBase && baseCur && <span className="block text-2xs font-normal text-surface-400 normal-case">{(thisRate / baseRate).toFixed(4)}</span>}
                                </div>
                                <Field label="Regular">
                                    <FieldInput
                                        className="input text-sm"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        {...register(`prices.${i}.regular_price`)}
                                    />
                                </Field>
                                <Field label="Sale">
                                    <FieldInput
                                        className="input text-sm"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        {...register(`prices.${i}.sale_price`)}
                                        placeholder="-"
                                    />
                                </Field>
                                <Field label="Cost">
                                    <FieldInput
                                        className="input text-sm"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        {...register(`prices.${i}.cost_price`)}
                                        placeholder="-"
                                    />
                                </Field>
                            </div>
                        );
                    })}
                </div>

                <div className="flex gap-4 pt-2 border-t border-surface-100">
                    <Toggle
                        checked={watch("is_default")}
                        onChange={(v) => setValue("is_default", v)}
                        label="Default"
                        description=""
                    />
                    <Toggle
                        checked={watch("is_active")}
                        onChange={(v) => setValue("is_active", v)}
                        label="Active"
                        description=""
                    />
                </div>
            </div>
        </Modal>
    );
}

// ── Measurements Tab ─────────────────────────────────────────────────────────

interface MeasurementField {
    name: string;
    unit?: string;
    required: boolean;
}

// ── Production Stages tab ─────────────────────────────────────────────────────
// Which stages this product's orders pass through. A keyholder never visits
// Embroidery; a chasuble does. No selection = every active stage (the default
// every product had before templates existed).

function ProductionStagesTab({
    selected,
    onChange,
}: {
    selected: number[];
    onChange: (ids: number[]) => void;
}) {
    const { data: stages, isLoading } = useQuery<{ id: number; name: string; order: number; active?: boolean }[]>({
        queryKey: ["production-stages"],
        queryFn: () => get<any>("/v1/admin/product-stages"),
        select: (d: any) => [...(Array.isArray(d) ? d : d?.data ?? [])].sort((a, b) => (a.order ?? 0) - (b.order ?? 0)),
        staleTime: 60_000,
    });

    const toggle = (id: number) =>
        onChange(selected.includes(id) ? selected.filter((s) => s !== id) : [...selected, id]);

    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    const list = stages ?? [];

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h3 className="text-sm font-bold text-surface-900">Production Stages</h3>
                    <p className="text-xs text-surface-500 mt-1 max-w-xl">
                        Pick the stages this product actually needs — its production orders will carry
                        only these, in this order, and tailors unlock them one after another.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button type="button" onClick={() => onChange(list.map((s) => s.id))}
                        className="text-2xs font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-full px-2.5 py-1 hover:bg-brand-100 transition-colors">
                        Select all
                    </button>
                    <button type="button" onClick={() => onChange([])}
                        className="text-2xs font-bold text-surface-500 bg-surface-100 border border-surface-200 rounded-full px-2.5 py-1 hover:bg-surface-200 transition-colors">
                        Clear
                    </button>
                </div>
            </div>

            {selected.length === 0 && (
                <div className="text-xs text-surface-600 bg-brand-50 border border-brand-100 rounded-xl px-3 py-2">
                    No stages selected — orders for this product will go through <b>all</b> active stages.
                </div>
            )}

            <div className="space-y-1.5">
                {list.map((stage) => {
                    const on = selected.includes(stage.id);
                    // Position among the SELECTED stages, in catalogue order — this is
                    // the sequence the order's tasks will actually be stamped with.
                    const seq = on
                        ? list.filter((s) => selected.includes(s.id)).findIndex((s) => s.id === stage.id) + 1
                        : null;
                    return (
                        <label key={stage.id}
                            className={clsx(
                                "flex items-center gap-3 px-3 py-2.5 rounded-xl border cursor-pointer transition-colors",
                                on ? "bg-brand-50/60 border-brand-200" : "bg-white border-surface-200 hover:border-surface-300",
                            )}>
                            <input type="checkbox" checked={on} onChange={() => toggle(stage.id)}
                                className="w-4 h-4 rounded border-surface-300 text-brand-600 focus:ring-brand-400" />
                            <span className={clsx("w-6 h-6 rounded-full flex items-center justify-center text-2xs font-bold shrink-0",
                                on ? "bg-brand-600 text-white" : "bg-surface-100 text-surface-400")}>
                                {on ? seq : "·"}
                            </span>
                            <span className={clsx("text-sm", on ? "font-semibold text-surface-900" : "text-surface-500")}>
                                {stage.name}
                            </span>
                        </label>
                    );
                })}
            </div>
            <p className="text-2xs text-surface-400">
                The running order follows the stage catalogue (Setup → Production Stages). Changing the
                catalogue later doesn't re-order production already on the floor.
            </p>
        </div>
    );
}

function MeasurementsTab({
    measurements,
    onChange,
}: {
    measurements: MeasurementField[];
    onChange: (m: MeasurementField[]) => void;
}) {
    const toast = useToastStore();
    const [newName, setNewName] = useState("");
    const [newUnit, setNewUnit] = useState("");
    const [newRequired, setNewRequired] = useState(true);

    const addField = () => {
        const name = newName.trim();
        if (!name) return;
        if (measurements.some((m) => m.name.toLowerCase() === name.toLowerCase())) return;
        onChange([...measurements, { name, unit: newUnit.trim() || undefined, required: newRequired }]);
        setNewName("");
        setNewUnit("");
        setNewRequired(true);
    };

    const removeField = (i: number) => {
        onChange(measurements.filter((_, idx) => idx !== i));
    };

    const updateField = (i: number, patch: Partial<MeasurementField>) => {
        onChange(measurements.map((m, idx) => (idx === i ? { ...m, ...patch } : m)));
    };

    const COMMON_MEASUREMENTS = [
        { name: "Chest", unit: "Inches" },
        { name: "Waist", unit: "Inches" },
        { name: "Hips", unit: "Inches" },
        { name: "Shoulder Width", unit: "Inches" },
        { name: "Sleeve Length", unit: "Inches" },
        { name: "Back Length", unit: "Inches" },
        { name: "Inseam", unit: "Inches" },
        { name: "Neck", unit: "Inches" },
        { name: "Thigh", unit: "Inches" },
        { name: "Height", unit: "Inches" },
    ];

    const unaddedCommon = COMMON_MEASUREMENTS.filter(
        (c) => !measurements.some((m) => m.name.toLowerCase() === c.name.toLowerCase()),
    );

    // Standard clergy tailoring sheets. These behave as a GENDER SELECTOR, not
    // additive quick-adds: Men and Ladies share seven fields, so appending only
    // skipped the shared ones and dropped the 3-4 new fields at the bottom of a
    // long list — off-screen on a phone, so tapping "Ladies" felt dead. Tapping
    // now LOADS that gender's sheet: shared fields keep their config, the other
    // gender's exclusive fields are cleared, and any custom fields are kept.
    const GENDER_SHEETS: Record<"Men" | "Ladies", string[]> = {
        Men: ["Neck", "Shoulders", "Sleeves", "Wrist", "Arm Hole", "Upper Arm",
            "Chest", "Stomach", "Shirt Length", "Full Length"],
        Ladies: ["Neck", "Shoulders", "Sleeves", "Wrist", "Arm Hole", "Upper Arm",
            "Bodice", "Waist", "Hips", "Blouse Length", "Full Length"],
    };
    // Every name governed by a gender sheet — anything else the user typed is a
    // custom field and must survive a gender switch.
    const sheetNames = new Set(
        [...GENDER_SHEETS.Men, ...GENDER_SHEETS.Ladies].map((n) => n.toLowerCase()),
    );
    // Which gender is currently loaded — a sheet is "active" when every one of
    // its fields is present. Drives the tab highlight.
    const hasWholeSheet = (g: "Men" | "Ladies") => {
        const names = new Set(measurements.map((m) => m.name.toLowerCase()));
        return GENDER_SHEETS[g].every((n) => names.has(n.toLowerCase()));
    };
    const activeGender: "Men" | "Ladies" | null =
        hasWholeSheet("Men") && !hasWholeSheet("Ladies") ? "Men"
        : hasWholeSheet("Ladies") && !hasWholeSheet("Men") ? "Ladies"
        : null;

    const applyGenderSheet = (g: "Men" | "Ladies") => {
        if (activeGender === g) return; // already loaded — no-op, but harmless
        const byName = new Map(measurements.map((m) => [m.name.toLowerCase(), m]));
        // Reuse the existing field object for shared names so the user's unit /
        // required tweaks carry across the switch.
        const sheet: MeasurementField[] = GENDER_SHEETS[g].map(
            (n) => byName.get(n.toLowerCase()) ?? { name: n, unit: "Inches", required: true },
        );
        // Preserve custom fields (not part of either standard sheet), in order.
        const custom = measurements.filter((m) => !sheetNames.has(m.name.toLowerCase()));
        onChange([...sheet, ...custom]);
        toast.success(`${g}'s measurement sheet loaded — ${GENDER_SHEETS[g].length} fields`);
    };

    return (
        <div className="space-y-3">
            {/* Header */}
            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold text-surface-900">
                        Measurement Fields
                    </h3>
                    <p className="text-xs text-surface-400 mt-0.5">
                        Pre-configure the measurements needed for this product. At the POS, when
                        a sales user marks an item as Made-to-Order, they will be prompted to fill
                        in these fields before placing the order for production.
                    </p>
                </div>

                {/* Gender sheet — a selector: tapping LOADS that gender's sheet */}
                <div className="px-4 pt-3 pb-3 border-b border-surface-100">
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                        Gender sheet
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        {(["Men", "Ladies"] as const).map((g) => {
                            const active = activeGender === g;
                            return (
                                <button
                                    key={g}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => applyGenderSheet(g)}
                                    className={clsx(
                                        "text-sm px-4 py-2 rounded-xl border font-semibold transition-colors",
                                        active
                                            ? "border-brand-500 bg-brand-600 text-white shadow-sm"
                                            : "border-brand-300 text-brand-700 bg-brand-50 hover:bg-brand-100 active:bg-brand-200",
                                    )}
                                >
                                    {active && "✓ "}{g}
                                </button>
                            );
                        })}
                        <span className="text-2xs text-surface-400 self-center ml-1 max-w-[220px] leading-snug">
                            Loads the standard {GENDER_SHEETS.Men.length}/{GENDER_SHEETS.Ladies.length}-field sheet. Switching keeps any custom fields you added.
                        </span>
                    </div>
                </div>

                {/* Quick-add common */}
                {unaddedCommon.length > 0 && (
                    <div className="px-4 pb-3 border-b border-surface-100">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Quick add — individual field
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {unaddedCommon.map((c) => (
                                <button
                                    key={c.name}
                                    type="button"
                                    onClick={() =>
                                        onChange([
                                            ...measurements,
                                            { name: c.name, unit: c.unit, required: true },
                                        ])
                                    }
                                    className="text-xs px-2.5 py-1 rounded-full border border-surface-200 text-surface-600 hover:border-brand-300 hover:text-brand-700 hover:bg-brand-50 transition-colors"
                                >
                                    + {c.name}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {/* Existing fields */}
                <div className="divide-y divide-surface-50">
                    {measurements.length === 0 ? (
                        <div className="px-4 py-8 text-center text-sm text-surface-400">
                            No measurement fields yet. Add from the quick-add list above or define a custom one below.
                        </div>
                    ) : (
                        measurements.map((m, i) => (
                            <div
                                key={i}
                                className="flex items-center gap-3 px-4 py-2.5 group hover:bg-surface-50/50"
                            >
                                <div className="flex-1 min-w-0">
                                    <input
                                        className="input text-sm py-1"
                                        value={m.name}
                                        onChange={(e) =>
                                            updateField(i, { name: e.target.value })
                                        }
                                        placeholder="Measurement name"
                                    />
                                </div>
                                <div className="w-24">
                                    <input
                                        className="input text-sm py-1"
                                        value={m.unit ?? ""}
                                        onChange={(e) =>
                                            updateField(i, {
                                                unit: e.target.value || undefined,
                                            })
                                        }
                                        placeholder="Unit (cm)"
                                    />
                                </div>
                                <label className="flex items-center gap-1.5 text-xs text-surface-600 shrink-0 cursor-pointer select-none">
                                    <input
                                        type="checkbox"
                                        className="accent-brand-500"
                                        checked={m.required}
                                        onChange={(e) =>
                                            updateField(i, { required: e.target.checked })
                                        }
                                    />
                                    Required
                                </label>
                                <button
                                    type="button"
                                    onClick={() => removeField(i)}
                                    className="w-6 h-6 rounded-full flex items-center justify-center text-surface-300 hover:text-danger hover:bg-danger-light transition-colors opacity-0 group-hover:opacity-100"
                                    aria-label="Close"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        ))
                    )}
                </div>

                {/* Add custom field */}
                <div className="px-4 py-3 border-t border-surface-100 bg-surface-50/50">
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                        Custom field
                    </p>
                    <div className="flex items-center gap-2">
                        <input
                            className="input text-sm flex-1"
                            placeholder="Field name (e.g. Ankle Width)"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === "Enter") {
                                    e.preventDefault();
                                    addField();
                                }
                            }}
                        />
                        <input
                            className="input text-sm w-24"
                            placeholder="Unit"
                            value={newUnit}
                            onChange={(e) => setNewUnit(e.target.value)}
                        />
                        <label className="flex items-center gap-1.5 text-xs text-surface-600 shrink-0 cursor-pointer">
                            <input
                                type="checkbox"
                                className="accent-brand-500"
                                checked={newRequired}
                                onChange={(e) => setNewRequired(e.target.checked)}
                            />
                            Required
                        </label>
                        <button
                            type="button"
                            onClick={addField}
                            disabled={!newName.trim()}
                            className="btn-secondary btn-sm disabled:opacity-40"
                        >
                            Add
                        </button>
                    </div>
                </div>
            </div>

            {/* Preview */}
            {measurements.length > 0 && (
                <div className="card">
                    <div className="card-header">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                            POS Preview - what the sales team will see
                        </p>
                    </div>
                    <div className="card-body">
                        <div className="grid grid-cols-2 gap-3">
                            {measurements.map((m) => (
                                <div key={m.name}>
                                    <label className="block text-xs font-medium text-surface-600 mb-1">
                                        {m.name}
                                        {m.unit && (
                                            <span className="ml-1 text-surface-400">
                                                ({m.unit})
                                            </span>
                                        )}
                                        {m.required && (
                                            <span className="ml-1 text-danger">*</span>
                                        )}
                                    </label>
                                    <div className="input text-sm py-1.5 text-surface-300 italic">
                                        Enter value…
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ── PriceRows ─────────────────────────────────────────────────────────────────
// All currency price rows rendered together so the base row can hold refs to
// the other rows' inputs and update them directly via DOM — zero re-renders,
// zero focus loss.
const PriceRows = React.memo(function PriceRows({
    fields,
    currencies,
    baseCurrency,
    control,
    onRecalculate,
}: {
    fields: any[];
    currencies: any[];
    baseCurrency: any;
    control: any;
    onRecalculate: (i: number) => void;
}) {
    // Refs to every non-base regular_price and sale_price input DOM node
    // keyed by field index so the base onChange can update them directly.
    const regularRefs = useRef<Record<number, HTMLInputElement | null>>({});
    const saleRefs    = useRef<Record<number, HTMLInputElement | null>>({});

    const baseRate = (baseCurrency?.exchange_rate ?? 1) as number;

    const handleBaseRegularChange = useCallback((e: React.ChangeEvent<HTMLInputElement>, baseFieldOnChange: (v: any) => void) => {
        const baseAmount = Number(e.target.value) || 0;
        baseFieldOnChange(baseAmount);
        if (baseRate <= 0) return;
        currencies.forEach((cur: any, j: number) => {
            if (cur.code === baseCurrency?.code) return;
            const oRate = (cur.exchange_rate ?? 1) as number;
            if (oRate <= 0) return;
            const converted = Math.round((baseAmount / baseRate) * oRate * 100) / 100;
            const el = regularRefs.current[j];
            if (el) {
                el.value = converted ? String(converted) : "";
                // Notify react-hook-form without triggering a re-render
                el.dispatchEvent(new Event("input", { bubbles: true }));
            }
        });
    }, [currencies, baseCurrency, baseRate]);

    const handleBaseSaleChange = useCallback((e: React.ChangeEvent<HTMLInputElement>, baseFieldOnChange: (v: any) => void) => {
        const baseAmount = Number(e.target.value) || 0;
        baseFieldOnChange(baseAmount || null);
        if (baseRate <= 0) return;
        currencies.forEach((cur: any, j: number) => {
            if (cur.code === baseCurrency?.code) return;
            const oRate = (cur.exchange_rate ?? 1) as number;
            if (oRate <= 0) return;
            const el = saleRefs.current[j];
            if (el) {
                el.value = baseAmount ? String(Math.round((baseAmount / baseRate) * oRate * 100) / 100) : "";
                el.dispatchEvent(new Event("input", { bubbles: true }));
            }
        });
    }, [currencies, baseCurrency, baseRate]);

    return (
        <>
        {fields.map((field, i) => {
            const currency = currencies.find((c: any) => c.code === field.currency_code);
            const isBase   = field.currency_code === baseCurrency?.code;
            const thisRate = ((currency as any)?.exchange_rate ?? 1) as number;
            const rateLabel = !isBase && baseCurrency
                ? `Rate: 1 ${baseCurrency.code} = ${(thisRate / baseRate).toFixed(4)} ${field.currency_code}`
                : null;

            return (
                <div
                    key={field.id}
                    className={`border rounded-xl overflow-hidden ${isBase ? "border-brand-300 ring-1 ring-brand-200" : "border-surface-100"}`}
                >
                    <div className={`flex items-center justify-between px-4 py-2.5 border-b ${isBase ? "bg-brand-50 border-brand-200" : "bg-surface-50 border-surface-100"}`}>
                        <div className="flex items-center gap-2">
                            <span className={`text-sm font-bold font-mono ${isBase ? "text-brand-700" : "text-surface-900"}`}>
                                {field.currency_code}
                            </span>
                            <span className="text-xs text-surface-500">{(currency as any)?.name}</span>
                            {isBase && (
                                <span className="text-2xs font-semibold bg-brand-500 text-white px-2 py-0.5 rounded-full">
                                    Default currency
                                </span>
                            )}
                            {rateLabel && (
                                <span className="text-2xs text-surface-400 bg-surface-100 px-2 py-0.5 rounded-full">
                                    {rateLabel}
                                </span>
                            )}
                        </div>
                        {!isBase && (
                            <button type="button" className="text-2xs text-brand-500 hover:text-brand-700 hover:underline" onClick={() => onRecalculate(i)}>
                                ↺ Recalculate from {baseCurrency?.code}
                            </button>
                        )}
                    </div>
                    <div className="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        <Field label="Regular Price" required>
                            <Controller control={control} name={`prices.${i}.regular_price`}
                                render={({ field: f }) => (
                                    <FieldInput className="input" type="number" step="0.01" min="0"
                                        name={f.name}
                                        ref={(el: HTMLInputElement | null) => {
                                            f.ref(el);
                                            if (!isBase) regularRefs.current[i] = el;
                                        }}
                                        defaultValue={f.value ?? ""}
                                        onBlur={f.onBlur}
                                        onChange={isBase
                                            ? (e: React.ChangeEvent<HTMLInputElement>) => handleBaseRegularChange(e, f.onChange)
                                            : (e: React.ChangeEvent<HTMLInputElement>) => f.onChange(Number(e.target.value) || 0)
                                        }
                                    />
                                )}
                            />
                        </Field>
                        <Field label="Sale Price">
                            <Controller control={control} name={`prices.${i}.sale_price`}
                                render={({ field: f }) => (
                                    <FieldInput className="input" type="number" step="0.01" min="0" placeholder="-"
                                        name={f.name}
                                        ref={(el: HTMLInputElement | null) => {
                                            f.ref(el);
                                            if (!isBase) saleRefs.current[i] = el;
                                        }}
                                        defaultValue={f.value ?? ""}
                                        onBlur={f.onBlur}
                                        onChange={isBase
                                            ? (e: React.ChangeEvent<HTMLInputElement>) => handleBaseSaleChange(e, f.onChange)
                                            : (e: React.ChangeEvent<HTMLInputElement>) => f.onChange(e.target.value ? Number(e.target.value) : null)
                                        }
                                    />
                                )}
                            />
                        </Field>
                        <Field label="Cost Price">
                            <Controller control={control} name={`prices.${i}.cost_price`}
                                render={({ field: f }) => (
                                    <FieldInput className="input" type="number" step="0.01" min="0" placeholder="-"
                                        name={f.name} ref={f.ref} defaultValue={f.value ?? ""}
                                        onBlur={f.onBlur}
                                        onChange={(e: React.ChangeEvent<HTMLInputElement>) => f.onChange(e.target.value ? Number(e.target.value) : null)}
                                    />
                                )}
                            />
                        </Field>
                        <Field label="Sale From">
                            <Controller control={control} name={`prices.${i}.sale_start_date`}
                                render={({ field: f }) => (
                                    <FieldInput className="input text-xs" type="date"
                                        name={f.name} ref={f.ref} defaultValue={f.value ?? ""}
                                        onBlur={f.onBlur} onChange={f.onChange}
                                    />
                                )}
                            />
                        </Field>
                        <Field label="Sale Until">
                            <Controller control={control} name={`prices.${i}.sale_end_date`}
                                render={({ field: f }) => (
                                    <FieldInput className="input text-xs" type="date"
                                        name={f.name} ref={f.ref} defaultValue={f.value ?? ""}
                                        onBlur={f.onBlur} onChange={f.onChange}
                                    />
                                )}
                            />
                        </Field>
                    </div>
                </div>
            );
        })}
        </>
    );
});

// ── Main component ────────────────────────────────────────────────────────────

export default function ProductFormPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const isEditing = !!id && id !== "new";
    const { can } = usePermissions();
    // Save maps to products.create when adding a new product, products.edit
    // when saving changes to an existing one - matches the route gates in
    // routes/api.php exactly. Image/variant management all sit behind
    // products.edit regardless of mode.
    const canSave = isEditing ? can("products.edit") : can("products.create");
    const canEditImagesAndVariants = can("products.edit");

    const [activeTab, setActiveTab] = useState<Tab>("content");
    const [images, setImages] = useState<ProductImage[]>([]);
    const [zoomedIndex, setZoomedIndex] = useState<number | null>(null);
    const [variantGenerator, setVariantGenerator] = useState(false);
    const [editingVariant, setEditingVariant] = useState<ProductVariant | null>(
        null,
    );
    const [deletingVariant, setDeletingVariant] =
        useState<ProductVariant | null>(null);
    const [uploading, setUploading] = useState(false);
    const [skuManual, setSkuManual] = useState(false);
    const imageRef = useRef<HTMLInputElement>(null);

    // Phase 2 - per-product tax rate selection
    const [taxRateIds, setTaxRateIds] = useState<number[]>([]);

    // Fetch business settings to know if tax-inclusive pricing is on
    const { data: settingsData } = useQuery({
        queryKey: ["business-settings"],
        queryFn: () => settingsApi.get(),
        staleTime: 300_000,
    });
    const taxInclusive = settingsData?.settings?.tax_inclusive ?? false;

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    );

    // ── Data ──────────────────────────────────────────────────────────────────

    const { data: productData, isLoading } = useQuery({
        queryKey: ["product", id],
        queryFn: () => productsApi.get(Number(id)),
        enabled: isEditing,
    });
    const { data: catsData } = useQuery({
        queryKey: ["categories-flat"],
        queryFn: () => categoriesApi.list(),
    });
    const { data: currData } = useQuery({
        queryKey: ["currencies"],
        queryFn: () => currenciesApi.list(),
    });

    const product = productData?.product;
    const categories = catsData?.data ?? [];
    const currencies: any[] = Object.values(
        ((currData?.data ?? []) as any[])
            .filter((c) => c.is_active)
            .reduce(
                (acc, c) => ({ ...acc, [c.code]: c }),
                {} as Record<string, any>,
            ),
    );

    // ── Form ──────────────────────────────────────────────────────────────────

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        mode: "onSubmit",
        reValidateMode: "onChange",
        defaultValues: {
            sku: "",
            slug: "",
            category_id: null,
            product_type: "simple",
            status: "draft",
            is_featured: false,
            is_producible: false,
            brand: "",
            tax_class: "standard",
            low_stock_threshold: 5,
            weight: null,
            length: null,
            width: null,
            height: null,
            translations: [
                {
                    language_code: "en",
                    name: "",
                    description: "",
                    short_description: "",
                },
            ],
            prices: [],
            seo: [
                {
                    language_code: "en",
                    meta_title: "",
                    meta_description: "",
                    meta_keywords: "",
                    canonical_url: "",
                    og_title: "",
                    og_description: "",
                },
            ],
            measurements: [],
            production_stage_ids: [],
        },
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        getValues,
        reset,
        control,
        formState: { errors },
    } = form;
    const transArr = useFieldArray({ control, name: "translations" });
    const priceArr = useFieldArray({ control, name: "prices" });
    const seoArr = useFieldArray({ control, name: "seo" });

    // ── Stable price auto-calculation handlers ────────────────────────────────
    // Defined here outside JSX so identity stays stable across renders,
    // preventing inputs from losing focus when other fields update.
    const baseCurrency = useMemo(
        () => currencies.find((c: any) => c.is_base || c.is_default),
        [currencies],
    );

    const handleRecalculate = useCallback(
        (targetIndex: number) => {
            const baseIndex = currencies.findIndex((c: any) => c.is_base || c.is_default);
            if (baseIndex < 0) return;
            const baseRate   = (baseCurrency?.exchange_rate ?? 1) as number;
            const targetRate = (currencies[targetIndex]?.exchange_rate ?? 1) as number;
            if (baseRate <= 0 || targetRate <= 0) return;
            const baseRegular = Number(getValues(`prices.${baseIndex}.regular_price`) ?? 0);
            const baseSale    = Number(getValues(`prices.${baseIndex}.sale_price`) ?? 0);
            setValue(`prices.${targetIndex}.regular_price`, Math.round((baseRegular / baseRate) * targetRate * 100) / 100 as any);
            if (baseSale) {
                setValue(`prices.${targetIndex}.sale_price`, Math.round((baseSale / baseRate) * targetRate * 100) / 100 as any);
            }
        },
        [currencies, baseCurrency, getValues, setValue],
    );

    useEffect(() => {
        if (isEditing && product && currencies.length > 0) {
            const basePrices = product.prices
                .filter((p: any) => !p.product_variant_id)
                .reduce(
                    (acc: any, p: any) => ({ ...acc, [p.currency_code]: p }),
                    {},
                );
            reset({
                sku: product.sku,
                slug: product.slug,
                category_id: product.category_id,
                product_type: product.product_type,
                status: product.status,
                is_featured: product.is_featured,
                is_producible: product.is_producible,
                brand: product.brand ?? "",
                tax_class: product.tax_class ?? "standard",
                low_stock_threshold: product.low_stock_threshold,
                weight: product.weight ?? null,
                length: product.length ?? null,
                width: product.width ?? null,
                height: product.height ?? null,
                translations: product.translations.length
                    ? product.translations.map((t: any) => ({
                          language_code: t.language_code,
                          name: t.name,
                          description: t.description,
                          short_description: t.short_description ?? "",
                      }))
                    : [
                          {
                              language_code: "en",
                              name: "",
                              description: "",
                              short_description: "",
                          },
                      ],
                prices: currencies.map((c: any) => ({
                    currency_code: c.code,
                    regular_price: Number(
                        basePrices[c.code]?.regular_price ?? 0,
                    ),
                    sale_price: basePrices[c.code]?.sale_price
                        ? Number(basePrices[c.code].sale_price)
                        : null,
                    cost_price: basePrices[c.code]?.cost_price
                        ? Number(basePrices[c.code].cost_price)
                        : null,
                    sale_start_date:
                        basePrices[c.code]?.sale_start_date ?? null,
                    sale_end_date: basePrices[c.code]?.sale_end_date ?? null,
                })),
                seo: product.seo.length
                    ? product.seo.map((s: any) => ({
                          language_code: s.language_code,
                          meta_title: s.meta_title ?? "",
                          meta_description: s.meta_description ?? "",
                          meta_keywords: s.meta_keywords ?? "",
                          canonical_url: s.canonical_url ?? "",
                          og_title: s.og_title ?? "",
                          og_description: s.og_description ?? "",
                      }))
                    : [
                          {
                              language_code: "en",
                              meta_title: "",
                              meta_description: "",
                              meta_keywords: "",
                              canonical_url: "",
                              og_title: "",
                              og_description: "",
                          },
                      ],
                measurements: Array.isArray(product.measurements)
                    ? product.measurements
                    : [],
                production_stage_ids: Array.isArray((product as any).production_stage_ids) ? (product as any).production_stage_ids : [],
            });
            setImages(
                product.images
                    .slice()
                    .sort((a: any, b: any) => a.sort_order - b.sort_order),
            );
            // Phase 2 - restore tax rate selections from loaded product
            setTaxRateIds(product.tax_rate_ids ?? []);
            setSkuManual(true); // existing product SKU - treat as manual
        } else if (!isEditing && currencies.length > 0) {
            reset({
                sku: "",
                slug: "",
                category_id: null,
                product_type: "simple",
                status: "draft",
                is_featured: false,
                is_producible: false,
                brand: "",
                tax_class: "standard",
                low_stock_threshold: 5,
                weight: null,
                length: null,
                width: null,
                height: null,
                translations: [
                    {
                        language_code: "en",
                        name: "",
                        description: "",
                        short_description: "",
                    },
                ],
                prices: currencies.map((c: any) => ({
                    currency_code: c.code,
                    regular_price: 0,
                    sale_price: null,
                    cost_price: null,
                    sale_start_date: null,
                    sale_end_date: null,
                })),
                seo: [
                    {
                        language_code: "en",
                        meta_title: "",
                        meta_description: "",
                        meta_keywords: "",
                        canonical_url: "",
                        og_title: "",
                        og_description: "",
                    },
                ],
                measurements: [],
            production_stage_ids: [],
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [product, currencies.length, isEditing]);

    // ── Auto SKU ──────────────────────────────────────────────────────────────

    const watchedName = watch("translations.0.name") ?? "";
    const watchedCategoryId = watch("category_id");

    useEffect(() => {
        if (skuManual || isEditing) return;
        if (!watchedName) return;
        const category = categories.find((c) => c.id === watchedCategoryId);
        const catName = category?.name_en ?? "";
        const sku = generateSku(catName, watchedName);
        setValue("sku", sku, { shouldValidate: false });
    }, [
        watchedName,
        watchedCategoryId,
        categories,
        skuManual,
        isEditing,
        setValue,
    ]);

    // ── Save ──────────────────────────────────────────────────────────────────

    const saveMutation = useMutation({
        mutationFn: async (values: FormValues) => {
            const payload = {
                ...values,
                slug: values.slug || undefined,
                category_id: values.category_id ?? null,
                // Ensure NOT NULL columns always receive a value
                tax_class: values.tax_class?.trim() || "standard",
                brand: values.brand?.trim() || undefined,
                // getValues ensures we get the latest measurements even if setValue
                // didn't trigger a re-render cycle before handleSubmit fired
                measurements: getValues("measurements") ?? values.measurements ?? [],
                production_stage_ids: getValues("production_stage_ids") ?? values.production_stage_ids ?? [],
            };
            const normalizedPayload = {
                ...payload,
                translations: payload.translations?.map((t) => ({
                    ...t,
                    short_description: t.short_description ?? "",
                })),
            };

            const res = isEditing
                ? await productsApi.update(Number(id), normalizedPayload)
                : await productsApi.create(normalizedPayload as any);

            // Phase 2 - sync tax rate assignments (separate pivot call)
            const productId = res.product.id;
            try {
                await productsApi.syncTaxRates(productId, taxRateIds);
            } catch (e) {
                // Non-fatal: log but don't fail the save
                console.warn("Tax rate sync failed:", e);
            }

            return res;
        },
        onSuccess: (res) => {
            toast.success(isEditing ? "Product saved." : "Product created.");
            qc.invalidateQueries({ queryKey: ["products"] });
            if (!isEditing)
                navigate(`/catalogue/products/${res.product.id}`, {
                    replace: true,
                });
            else qc.invalidateQueries({ queryKey: ["product", id] });
        },
        onError: (err: ApiError) => {
            if (err.errors?.sku)
                form.setError("sku", { message: err.errors.sku[0] });
            else toast.error(err.message);
        },
    });

    // ── Image handlers ────────────────────────────────────────────────────────

    const handleUpload = useCallback(
        async (files: FileList) => {
            if (!isEditing) {
                toast.error("Save the product first before uploading images.");
                return;
            }
            setUploading(true);
            try {
                // Optimise in the browser first: shrinks large photos to a fast,
                // high-quality WebP so the upload succeeds (and is quick) instead
                // of being rejected for size. Per-file fallback to the original.
                const { smartCompressImage } = await import("@/utils/compressImage");
                const optimized = await Promise.all(
                    Array.from(files).map((f) => smartCompressImage(f).catch(() => f)),
                );
                const res = await productsApi.uploadImages(Number(id), optimized);
                setImages((prev) => [...prev, ...res.images]);
                toast.success(`${res.images.length} image(s) uploaded.`);
            } catch (e: any) {
                toast.error(e.message ?? "Upload failed.");
            } finally {
                setUploading(false);
            }
        },
        [id, isEditing, toast],
    );

    const handleSetPrimary = useCallback(
        async (imageId: number) => {
            await productsApi.setPrimaryImage(Number(id), imageId);
            setImages((prev) =>
                prev.map((img) => ({ ...img, is_primary: img.id === imageId })),
            );
        },
        [id],
    );

    const handleDeleteImage = useCallback(
        async (imageId: number) => {
            await productsApi.deleteImage(Number(id), imageId);
            setImages((prev) => {
                const f = prev.filter((img) => img.id !== imageId);
                if (f.length > 0 && !f.some((i) => i.is_primary))
                    f[0].is_primary = true;
                return f;
            });
            toast.success("Image deleted.");
        },
        [id, toast],
    );

    const handleImageDragEnd = useCallback(
        async (event: DragEndEvent) => {
            const { active, over } = event;
            if (!over || active.id === over.id) return;
            const reordered = arrayMove(
                images,
                images.findIndex((i) => i.id === active.id),
                images.findIndex((i) => i.id === over.id),
            ).map((img, idx) => ({ ...img, sort_order: idx + 1 }));
            setImages(reordered);
            await productsApi.reorderImages(
                Number(id),
                reordered.map((img) => ({
                    id: img.id,
                    sort_order: img.sort_order,
                })),
            );
        },
        [images, id],
    );

    const deleteVariantMutation = useMutation({
        mutationFn: (variantId: number) =>
            productsApi.deleteVariant(Number(id), variantId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["product", id] });
            toast.success("Variant deleted.");
            setDeletingVariant(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    // ── Derived ───────────────────────────────────────────────────────────────

    const enIdx = transArr.fields.findIndex((f) => f.language_code === "en");
    const enName = watch(`translations.${enIdx >= 0 ? enIdx : 0}.name`) ?? "";

    if (isEditing && isLoading)
        return (
            <div className="flex items-center justify-center h-64">
                <Spinner size="lg" />
            </div>
        );

    return (
        <div className="animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 mb-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3 min-w-0">
                    <button
                        onClick={() => navigate("/catalogue/products")}
                        className="text-surface-400 hover:text-surface-600 transition-colors shrink-0"
                    >
                        <svg
                            className="w-5 h-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M10 19l-7-7m0 0l7-7m-7 7h18"
                            />
                        </svg>
                    </button>
                    <div className="min-w-0">
                        <h1 className="text-lg font-bold text-surface-900 leading-tight truncate">
                            {isEditing
                                ? enName || product?.sku || "Edit Product"
                                : "New Product"}
                        </h1>
                        {isEditing && (
                            <p className="text-xs text-surface-400 font-mono truncate">
                                {product?.sku}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    <select
                        className="input text-sm py-1.5 w-28 sm:w-32"
                        value={watch("status")}
                        onChange={(e) =>
                            setValue("status", e.target.value as any)
                        }
                    >
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>
                    </select>
                    {canSave && (
                    <button
                        onClick={handleSubmit(
                            (v) => saveMutation.mutate(v),
                            (errs) => {
                                // Determine which tabs contain errors and navigate to the first one
                                const hasContent =
                                    !!errs.translations || !!errs.sku;
                                const hasPricing = !!errs.prices;
                                const hasMeasurements = !!errs.measurements;

                                if (hasContent) {
                                    setActiveTab("content");
                                } else if (hasPricing) {
                                    setActiveTab("pricing");
                                } else if (hasMeasurements) {
                                    setActiveTab("measurements");
                                }

                                // Build a human-readable summary of what's missing
                                const missing: string[] = [];
                                if (errs.translations?.[0]?.name)
                                    missing.push("Product Name");
                                if (errs.translations?.[0]?.description)
                                    missing.push("Full Description");
                                if (errs.sku) missing.push("SKU");
                                if (errs.prices)
                                    missing.push("Pricing (check Regular Price)");
                                if (errs.measurements)
                                    missing.push("Measurements");

                                const msg =
                                    missing.length > 0
                                        ? `Please fill in: ${missing.join(", ")}.`
                                        : "Please fix the highlighted errors before saving.";
                                toast.error(msg);
                            },
                        )}
                        disabled={saveMutation.isPending}
                        className="btn-primary btn-sm"
                    >
                        {saveMutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        {isEditing ? "Save" : "Create"}
                    </button>
                    )}
                </div>
            </div>

            {/* Two-column layout: stacked on mobile, side-by-side on xl */}
            <div className="flex flex-col gap-4 xl:flex-row xl:items-start">
                {/* ── LEFT: Main content ─────────────────────────────────────────── */}
                <div className="flex-1 min-w-0 space-y-3">
                    <div className="flex gap-0 border-b border-surface-100 overflow-x-auto no-scrollbar">
                        {[...BASE_TABS, ...(watch("is_producible") ? PRODUCTION_TABS : [])].map((tab) => {
                            // Determine if this tab has any validation errors
                            const tabHasError =
                                (tab.id === "content" && (!!errors.translations || !!errors.sku)) ||
                                (tab.id === "pricing" && !!errors.prices) ||
                                (tab.id === "measurements" && !!errors.measurements);
                            return (
                            <button
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                className={clsx(
                                    "relative px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap shrink-0",
                                    activeTab === tab.id
                                        ? "border-brand-500 text-brand-600"
                                        : "border-transparent text-surface-500 hover:text-surface-700",
                                )}
                            >
                                {tab.label}
                                {tabHasError && (
                                    <span className="absolute top-1.5 right-0.5 w-1.5 h-1.5 rounded-full bg-danger" />
                                )}
                            </button>
                        );})}
                    </div>

                    {/* ── CONTENT TAB ─────────────────────────────────────────────── */}
                    {activeTab === "content" && (
                        <div className="space-y-3">
                            {/* Organisation fields - moved from sidebar */}
                            <div className="card">
                                <div className="card-body">
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <Field label="Category">
                                            <FieldSelect
                                                className="input text-sm"
                                                value={
                                                    watch("category_id") ?? ""
                                                }
                                                onChange={(e) =>
                                                    setValue(
                                                        "category_id",
                                                        e.target.value
                                                            ? Number(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    - None -
                                                </option>
                                                {categories.map((c) => (
                                                    <option
                                                        key={c.id}
                                                        value={c.id}
                                                    >
                                                        {c.breadcrumb ||
                                                            c.name_en}
                                                    </option>
                                                ))}
                                            </FieldSelect>
                                        </Field>
                                        <Field label="Type">
                                            <FieldSelect
                                                className="input text-sm"
                                                {...register("product_type")}
                                            >
                                                <option value="simple">
                                                    Simple
                                                </option>
                                                <option value="variable">
                                                    Variable
                                                </option>
                                                <option value="made_to_order">
                                                    Made to Order
                                                </option>
                                            </FieldSelect>
                                        </Field>
                                        <Field label="Brand">
                                            <FieldInput
                                                className="input text-sm"
                                                {...register("brand")}
                                                placeholder="Bethany House"
                                            />
                                        </Field>
                                        <Field label="Tax Class">
                                            <FieldInput
                                                className="input text-sm"
                                                {...register("tax_class")}
                                                placeholder="standard"
                                            />
                                        </Field>
                                    </div>
                                </div>
                            </div>

                            {/* English content */}
                            {transArr.fields.map((field, i) => {
                                if (field.language_code !== "en") return null;
                                return (
                                    <div key={field.id} className="card">
                                        <div className="card-body space-y-3">
                                            <Field
                                                label="Product Name"
                                                error={
                                                    errors.translations?.[i]
                                                        ?.name?.message
                                                }
                                                required
                                            >
                                                <FieldInput
                                                    className={`input ${errors.translations?.[i]?.name ? "input-error" : ""}`}
                                                    {...register(
                                                        `translations.${i}.name`,
                                                    )}
                                                    placeholder="e.g. Classic Ankara Wrap Dress"
                                                    autoFocus
                                                />
                                            </Field>
                                            <Field
                                                label="Short Description"
                                                hint="One-liner shown in product cards"
                                            >
                                                <FieldInput
                                                    className="input"
                                                    {...register(
                                                        `translations.${i}.short_description`,
                                                    )}
                                                    placeholder="Brief summary"
                                                />
                                            </Field>
                                            <Field
                                                label="Full Description"
                                                error={
                                                    errors.translations?.[i]
                                                        ?.description?.message
                                                }
                                                required
                                            >
                                                <Controller
                                                    control={control}
                                                    name={`translations.${i}.description`}
                                                    render={({ field }) => (
                                                        <RichEditor
                                                            value={
                                                                field.value ??
                                                                ""
                                                            }
                                                            onChange={
                                                                field.onChange
                                                            }
                                                            placeholder="Full product description, materials, care instructions, sizing guide…"
                                                            error={
                                                                !!errors
                                                                    .translations?.[
                                                                    i
                                                                ]?.description
                                                            }
                                                            minHeight={160}
                                                        />
                                                    )}
                                                />
                                                {errors.translations?.[i]
                                                    ?.description && (
                                                    <p className="text-xs text-danger mt-1">
                                                        {
                                                            errors.translations[
                                                                i
                                                            ].description
                                                                ?.message
                                                        }
                                                    </p>
                                                )}
                                            </Field>
                                        </div>
                                    </div>
                                );
                            })}

                            {/* Other translations */}
                            {transArr.fields
                                .filter((f) => f.language_code !== "en")
                                .map((field) => {
                                    const i = transArr.fields.findIndex(
                                        (f) => f.id === field.id,
                                    );
                                    const labels: Record<string, string> = {
                                        sw: "🇰🇪 Swahili",
                                        fr: "🇫🇷 French",
                                        pt: "🇵🇹 Portuguese",
                                    };
                                    return (
                                        <details
                                            key={field.id}
                                            className="card group"
                                        >
                                            <summary className="card-body py-3 cursor-pointer list-none flex items-center justify-between text-sm font-medium text-surface-600">
                                                {labels[field.language_code] ??
                                                    field.language_code}
                                                <svg
                                                    className="w-4 h-4 text-surface-400 group-open:rotate-180 transition-transform"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    strokeWidth={2}
                                                >
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        d="M19 9l-7 7-7-7"
                                                    />
                                                </svg>
                                            </summary>
                                            <div className="px-4 pb-4 space-y-3 border-t border-surface-100 pt-3">
                                                <Field label="Name">
                                                    <FieldInput
                                                        className="input"
                                                        {...register(
                                                            `translations.${i}.name`,
                                                        )}
                                                    />
                                                </Field>
                                                <Field label="Short Desc">
                                                    <FieldInput
                                                        className="input"
                                                        {...register(
                                                            `translations.${i}.short_description`,
                                                        )}
                                                    />
                                                </Field>
                                                <Field label="Description">
                                                    <Controller
                                                        control={control}
                                                        name={`translations.${i}.description`}
                                                        render={({ field }) => (
                                                            <RichEditor
                                                                value={
                                                                    field.value ??
                                                                    ""
                                                                }
                                                                onChange={
                                                                    field.onChange
                                                                }
                                                                minHeight={100}
                                                            />
                                                        )}
                                                    />
                                                </Field>
                                            </div>
                                        </details>
                                    );
                                })}

                            <div className="flex gap-2">
                                {[
                                    { code: "sw", label: "Swahili" },
                                    { code: "fr", label: "French" },
                                    { code: "pt", label: "Portuguese" },
                                ]
                                    .filter(
                                        (l) =>
                                            !transArr.fields.some(
                                                (f) =>
                                                    f.language_code === l.code,
                                            ),
                                    )
                                    .map((l) => (
                                        <button
                                            key={l.code}
                                            type="button"
                                            onClick={() =>
                                                transArr.append({
                                                    language_code: l.code,
                                                    name: "",
                                                    description: "",
                                                    short_description: "",
                                                })
                                            }
                                            className="btn-ghost btn-sm text-xs"
                                        >
                                            + {l.label}
                                        </button>
                                    ))}
                            </div>
                        </div>
                    )}

                    {/* ── MEDIA TAB ───────────────────────────────────────────────── */}
                    {activeTab === "media" && (
                        <div className="card">
                            <div className="card-header flex items-center justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-surface-900">
                                        Product Images
                                    </h3>
                                    <p className="text-xs text-surface-400 mt-0.5">
                                        Drag to reorder · Hover to set primary
                                        or delete
                                    </p>
                                </div>
                                {canEditImagesAndVariants && (
                                <button
                                    onClick={() => imageRef.current?.click()}
                                    disabled={!isEditing || uploading}
                                    className="btn-primary btn-sm"
                                >
                                    {uploading ? (
                                        <Spinner
                                            size="xs"
                                            className="border-white/30 border-t-white"
                                        />
                                    ) : null}
                                    Upload
                                </button>
                                )}
                                <input
                                    ref={imageRef}
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    className="hidden"
                                    onChange={(e) =>
                                        e.target.files &&
                                        handleUpload(e.target.files)
                                    }
                                />
                            </div>
                            <div className="card-body">
                                {!isEditing && (
                                    <p className="text-xs text-amber-600 bg-amber-50 px-3 py-2 rounded-lg mb-3">
                                        Save the product first, then upload
                                        images.
                                    </p>
                                )}
                                {images.length === 0 ? (
                                    <div
                                        onClick={() =>
                                            isEditing &&
                                            canEditImagesAndVariants &&
                                            imageRef.current?.click()
                                        }
                                        className="border-2 border-dashed border-surface-200 rounded-xl p-10 text-center cursor-pointer hover:border-brand-300 hover:bg-brand-50 transition-colors"
                                    >
                                        <svg
                                            className="w-8 h-8 text-surface-300 mx-auto mb-2"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={1.5}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                                            />
                                        </svg>
                                        <p className="text-sm text-surface-400">
                                            Click to upload images
                                        </p>
                                        <p className="text-xs text-surface-300 mt-1">
                                            PNG, JPG, WebP · optimised automatically for fast, high-quality upload
                                        </p>
                                    </div>
                                ) : (
                                    <DndContext
                                        sensors={sensors}
                                        collisionDetection={closestCenter}
                                        onDragEnd={handleImageDragEnd}
                                    >
                                        <SortableContext
                                            items={images.map((i) => i.id)}
                                            strategy={
                                                horizontalListSortingStrategy
                                            }
                                        >
                                            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                                                {images.map((img, idx) => (
                                                    <SortableImage
                                                        key={img.id}
                                                        image={img}
                                                        onSetPrimary={
                                                            handleSetPrimary
                                                        }
                                                        onDelete={
                                                            handleDeleteImage
                                                        }
                                                        onZoom={() => setZoomedIndex(idx)}
                                                    />
                                                ))}
                                                {canEditImagesAndVariants && (
                                                <div
                                                    onClick={() =>
                                                        imageRef.current?.click()
                                                    }
                                                    className="aspect-square border-2 border-dashed border-surface-200 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-brand-300 hover:bg-brand-50 transition-colors"
                                                >
                                                    <svg
                                                        className="w-5 h-5 text-surface-300"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke="currentColor"
                                                        strokeWidth={2}
                                                    >
                                                        <path
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                            d="M12 4.5v15m7.5-7.5h-15"
                                                        />
                                                    </svg>
                                                    <p className="text-2xs text-surface-400 mt-1">
                                                        Add
                                                    </p>
                                                </div>
                                                )}
                                            </div>
                                        </SortableContext>
                                    </DndContext>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ── PRICING TAB ─────────────────────────────────────────────── */}
                    {activeTab === "pricing" && (
                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold text-surface-900">
                                    Pricing
                                </h3>
                                <p className="text-xs text-surface-400 mt-0.5">
                                    Set tax rates first, then configure prices per currency.
                                </p>
                            </div>
                            <div className="card-body space-y-4">

                                {/* ── Tax Rates (moved to top) ─────────────────── */}
                                <div className="rounded-xl border border-surface-100 bg-surface-50/50 p-4 space-y-3">
                                    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h4 className="text-sm font-semibold text-surface-800">
                                                Applicable Tax Rates
                                            </h4>
                                            <p className="text-xs text-surface-400 mt-0.5">
                                                Select which taxes apply to this product.
                                                Leave empty to use the global default rate.
                                            </p>
                                        </div>
                                        <a
                                            href="/settings/taxes"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-xs text-brand-500 hover:underline shrink-0"
                                        >
                                            Manage rates ↗
                                        </a>
                                    </div>
                                    <TaxRateSelector
                                        selectedIds={taxRateIds}
                                        onChange={setTaxRateIds}
                                        taxInclusive={taxInclusive}
                                    />
                                </div>

                                {/* Auto-calculates non-base currency prices — renders nothing visible */}
                                {/* ── Currency prices ──────────────────────────── */}
                                <div className="space-y-3">
                                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Base Prices
                                    </p>
                                    <p className="text-xs text-surface-400 -mt-2">
                                        Set the default currency price — other currencies are auto-calculated from exchange rates. You can override any price manually.
                                    </p>
                                    {/* Warning when base-currency regular price is 0 */}
                                    {(() => {
                                        const baseIdx = priceArr.fields.findIndex(
                                            (f) => f.currency_code === baseCurrency?.code,
                                        );
                                        const basePrice = baseIdx >= 0
                                            ? Number(watch(`prices.${baseIdx}.regular_price`) ?? 0)
                                            : 0;
                                        return basePrice === 0 && baseCurrency ? (
                                            <div className="flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5 text-xs text-amber-800">
                                                <svg className="w-4 h-4 shrink-0 mt-0.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                </svg>
                                                <span>
                                                    The <strong>{baseCurrency.code}</strong> regular price is <strong>0</strong>. Set a price before publishing.
                                                </span>
                                            </div>
                                        ) : null;
                                    })()}
                                <PriceRows
                                    fields={priceArr.fields}
                                    currencies={currencies}
                                    baseCurrency={baseCurrency}
                                    control={control}
                                    onRecalculate={handleRecalculate}
                                />
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ── VARIANTS TAB ────────────────────────────────────────────── */}
                    {activeTab === "variants" && (
                        <div className="card">
                            <div className="card-header flex items-center justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-surface-900">
                                        Product Variants
                                    </h3>
                                    <p className="text-xs text-surface-400 mt-0.5">
                                        Define attributes (Size, Colour…) and
                                        generate all combinations automatically.
                                    </p>
                                </div>
                                {watch("product_type") === "variable" &&
                                    isEditing && (
                                        <button
                                            onClick={() =>
                                                setVariantGenerator(true)
                                            }
                                            className="btn-primary btn-sm"
                                        >
                                            + Generate from Attributes
                                        </button>
                                    )}
                            </div>
                            <div className="card-body">
                                {watch("product_type") !== "variable" ? (
                                    <div className="text-center py-6">
                                        <p className="text-sm text-surface-500 mb-2">
                                            Product type is{" "}
                                            <strong>
                                                {watch("product_type")?.replace(
                                                    "_",
                                                    " ",
                                                )}
                                            </strong>
                                            .
                                        </p>
                                        <button
                                            onClick={() =>
                                                setValue(
                                                    "product_type",
                                                    "variable",
                                                )
                                            }
                                            className="btn-secondary btn-sm text-xs"
                                        >
                                            Switch to Variable
                                        </button>
                                    </div>
                                ) : !isEditing ? (
                                    <p className="text-sm text-surface-400 text-center py-6">
                                        Save the product first, then generate
                                        variants.
                                    </p>
                                ) : (product?.variants?.length ?? 0) === 0 ? (
                                    <div className="text-center py-8">
                                        <p className="text-sm text-surface-400 mb-1">
                                            No variants yet.
                                        </p>
                                        <p className="text-xs text-surface-300 mb-3">
                                            Click "Generate from Attributes" to
                                            define sizes, colours, and other
                                            options.
                                        </p>
                                        <button
                                            onClick={() =>
                                                setVariantGenerator(true)
                                            }
                                            className="btn-primary btn-sm"
                                        >
                                            Generate from Attributes
                                        </button>
                                    </div>
                                ) : (
                                    <>
                                        <div className="overflow-x-auto -mx-4 sm:mx-0">
                                        <table className="w-full text-sm min-w-[500px]">
                                            <thead>
                                                <tr className="border-b border-surface-100 text-xs text-surface-500 uppercase">
                                                    <th className="pb-2 text-left font-semibold">
                                                        Variant
                                                    </th>
                                                    <th className="pb-2 text-left font-semibold">
                                                        SKU
                                                    </th>
                                                    <th className="pb-2 text-left font-semibold">
                                                        Attributes
                                                    </th>
                                                    {currencies
                                                        .slice(0, 2)
                                                        .map((c: any) => (
                                                            <th
                                                                key={c.code}
                                                                className="pb-2 text-right font-semibold"
                                                            >
                                                                {c.code}
                                                            </th>
                                                        ))}
                                                    <th className="pb-2 text-center font-semibold">
                                                        Status
                                                    </th>
                                                    <th className="pb-2" />
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-surface-50">
                                                {product?.variants?.map((v) => (
                                                    <tr
                                                        key={v.id}
                                                        className="hover:bg-surface-50 transition-colors"
                                                    >
                                                        <td className="py-2.5">
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="font-medium text-surface-800">
                                                                    {
                                                                        v.variant_name
                                                                    }
                                                                </span>
                                                                {v.is_default && (
                                                                    <span className="badge badge-info text-2xs">
                                                                        Default
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="py-2.5">
                                                            <span className="text-xs font-mono bg-surface-100 text-surface-500 px-1.5 py-0.5 rounded">
                                                                {v.sku}
                                                            </span>
                                                        </td>
                                                        <td className="py-2.5">
                                                            <div className="flex gap-1 flex-wrap">
                                                                {Object.entries(
                                                                    v.attributes ??
                                                                        {},
                                                                ).map(
                                                                    ([
                                                                        k,
                                                                        val,
                                                                    ]) => (
                                                                        <span
                                                                            key={
                                                                                k
                                                                            }
                                                                            className="text-xs bg-surface-100 text-surface-600 px-1.5 py-0.5 rounded"
                                                                        >
                                                                            {String(
                                                                                val,
                                                                            )}
                                                                        </span>
                                                                    ),
                                                                )}
                                                            </div>
                                                        </td>
                                                        {currencies
                                                            .slice(0, 2)
                                                            .map((c: any) => {
                                                                const p =
                                                                    v.prices?.find(
                                                                        (x) =>
                                                                            x.currency_code ===
                                                                            c.code,
                                                                    );
                                                                return (
                                                                    <td
                                                                        key={
                                                                            c.code
                                                                        }
                                                                        className="py-2.5 text-right"
                                                                    >
                                                                        {p ? (
                                                                            <span className="text-sm font-medium">
                                                                                {Number(
                                                                                    p.regular_price,
                                                                                ).toLocaleString()}
                                                                            </span>
                                                                        ) : (
                                                                            <span className="text-surface-300">
                                                                                -
                                                                            </span>
                                                                        )}
                                                                    </td>
                                                                );
                                                            })}
                                                        <td className="py-2.5 text-center">
                                                            <span
                                                                className={clsx(
                                                                    "text-xs font-medium px-2 py-0.5 rounded-full",
                                                                    v.is_active
                                                                        ? "bg-success-light text-success"
                                                                        : "bg-surface-100 text-surface-400",
                                                                )}
                                                            >
                                                                {v.is_active
                                                                    ? "Active"
                                                                    : "Off"}
                                                            </span>
                                                        </td>
                                                        <td className="py-2.5">
                                                            <div className="flex items-center gap-1 justify-end">
                                                                {canEditImagesAndVariants && (
                                                                <button
                                                                    onClick={() =>
                                                                        setEditingVariant(
                                                                            v,
                                                                        )
                                                                    }
                                                                    className="btn-ghost btn-sm p-1"
                                                                    aria-label="Edit"
                                                                >
                                                                    <svg
                                                                        className="w-3.5 h-3.5"
                                                                        fill="none"
                                                                        viewBox="0 0 24 24"
                                                                        stroke="currentColor"
                                                                        strokeWidth={
                                                                            2
                                                                        }
                                                                    >
                                                                        <path
                                                                            strokeLinecap="round"
                                                                            strokeLinejoin="round"
                                                                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                                                        />
                                                                    </svg>
                                                                </button>
                                                                )}
                                                                {!v.is_default && canEditImagesAndVariants && (
                                                                    <button
                                                                        onClick={() =>
                                                                            setDeletingVariant(
                                                                                v,
                                                                            )
                                                                        }
                                                                        className="btn-ghost btn-sm p-1 text-danger hover:bg-danger-light"
                                                                        aria-label="Delete"
                                                                    >
                                                                        <svg
                                                                            className="w-3.5 h-3.5"
                                                                            fill="none"
                                                                            viewBox="0 0 24 24"
                                                                            stroke="currentColor"
                                                                            strokeWidth={
                                                                                2
                                                                            }
                                                                        >
                                                                            <path
                                                                                strokeLinecap="round"
                                                                                strokeLinejoin="round"
                                                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                                            />
                                                                        </svg>
                                                                    </button>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                        </div>
                                        <div className="mt-3 pt-3 border-t border-surface-50">
                                            {canEditImagesAndVariants && (
                                            <button
                                                onClick={() =>
                                                    setVariantGenerator(true)
                                                }
                                                className="btn-ghost btn-sm text-xs text-brand-600"
                                            >
                                                + Generate more variants from
                                                attributes
                                            </button>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ── SEO TAB ─────────────────────────────────────────────────── */}
                    {activeTab === "seo" && (
                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold text-surface-900">
                                    Search Engine Optimisation
                                </h3>
                            </div>
                            <div className="card-body space-y-4">
                                {seoArr.fields.map((field, i) => {
                                    const metaTitle =
                                        watch(`seo.${i}.meta_title`) || "";
                                    const metaDesc =
                                        watch(`seo.${i}.meta_description`) ||
                                        "";
                                    return (
                                        <div
                                            key={field.id}
                                            className="space-y-3"
                                        >
                                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                <Field
                                                    label={`Meta Title (${metaTitle.length}/60)`}
                                                >
                                                    <FieldInput
                                                        className="input"
                                                        {...register(
                                                            `seo.${i}.meta_title`,
                                                        )}
                                                        placeholder={
                                                            enName ||
                                                            "Product name"
                                                        }
                                                    />
                                                </Field>
                                                <Field label="Keywords">
                                                    <FieldInput
                                                        className="input"
                                                        {...register(
                                                            `seo.${i}.meta_keywords`,
                                                        )}
                                                        placeholder="ankara dress, fashion kenya"
                                                    />
                                                </Field>
                                            </div>
                                            <Field
                                                label={`Meta Description (${metaDesc.length}/160)`}
                                            >
                                                <FieldTextarea
                                                    className="input resize-none"
                                                    rows={2}
                                                    {...register(
                                                        `seo.${i}.meta_description`,
                                                    )}
                                                    placeholder="Compelling description for search results…"
                                                />
                                            </Field>
                                            {(metaTitle || enName) && (
                                                <div className="border border-surface-100 rounded-xl p-3 bg-surface-50 text-xs">
                                                    <p className="text-blue-600 font-medium">
                                                        {metaTitle || enName} |
                                                        Bethany House
                                                    </p>
                                                    <p className="text-green-700">
                                                        bethanyhouse.co.ke/products/
                                                        {watch("slug") ||
                                                            "product-slug"}
                                                    </p>
                                                    <p className="text-surface-500 line-clamp-1 mt-0.5">
                                                        {metaDesc ||
                                                            "No description."}
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* ── MEASUREMENTS TAB ───────────────────────────────────────── */}
                    {activeTab === "measurements" && (
                        <MeasurementsTab
                            measurements={watch("measurements") ?? []}
                            onChange={(m) => setValue("measurements", m, { shouldDirty: true })}
                        />
                    )}

                    {activeTab === "stages" && (
                        <ProductionStagesTab
                            selected={watch("production_stage_ids") ?? []}
                            onChange={(ids) => setValue("production_stage_ids", ids, { shouldDirty: true })}
                        />
                    )}

                    {/* ── BOM TAB ─────────────────────────────────────────────────── */}
                    {activeTab === "bom" &&
                        (isEditing ? (
                            <BomTab
                                productId={Number(id)}
                                variants={(product?.variants ?? []).map(
                                    (v) => ({
                                        id: v.id,
                                        variant_name: v.variant_name,
                                        sku: v.sku,
                                    }),
                                )}
                            />
                        ) : (
                            <div className="card">
                                <div className="card-body text-center py-10">
                                    <p className="text-sm text-surface-500 mb-1">
                                        Save the product first
                                    </p>
                                    <p className="text-xs text-surface-400">
                                        Create the product, then come back here
                                        to define its Bill of Materials.
                                    </p>
                                </div>
                            </div>
                        ))}
                </div>
                <div className="w-full xl:w-64 xl:shrink-0 space-y-3">
                    {/* SKU - with auto-generate */}
                    <div className="card p-3 space-y-2">
                        <div className="flex items-center justify-between">
                            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                SKU
                            </p>
                            {!isEditing && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSkuManual(false);
                                        const category = categories.find(
                                            (c) =>
                                                c.id === watch("category_id"),
                                        );
                                        setValue(
                                            "sku",
                                            generateSku(
                                                category?.name_en ?? "",
                                                watchedName,
                                            ),
                                        );
                                    }}
                                    className="text-2xs text-brand-500 hover:text-brand-700 hover:underline"
                                    title="Re-generate SKU from category and name"
                                >
                                    ↺ Auto
                                </button>
                            )}
                        </div>
                        <input
                            className={`input text-sm font-mono uppercase ${errors.sku ? "input-error" : ""}`}
                            {...register("sku", {
                                onChange: (e) => {
                                    setValue("sku", e.target.value.toUpperCase(), { shouldValidate: true });
                                    setSkuManual(true);
                                },
                            })}
                            placeholder="BH-001"
                        />
                        {errors.sku && (
                            <p className="text-2xs text-danger">
                                {errors.sku.message}
                            </p>
                        )}
                        {!isEditing && !skuManual && watchedName && (
                            <p className="text-2xs text-surface-400">
                                Auto-generated · type to override
                            </p>
                        )}
                    </div>

                    {/* Flags */}
                    <div className="card p-3 space-y-2">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-1">
                            Flags
                        </p>
                        <label className="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                className="accent-brand-500"
                                checked={watch("is_featured")}
                                onChange={(e) =>
                                    setValue("is_featured", e.target.checked)
                                }
                            />
                            <span className="text-sm text-surface-700">
                                Featured
                            </span>
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                className="accent-brand-500"
                                checked={watch("is_producible")}
                                onChange={(e) => {
                                    setValue("is_producible", e.target.checked);
                                    // If turning off production, jump back to content tab
                                    if (!e.target.checked) setActiveTab("content");
                                }}
                            />
                            <span className="text-sm text-surface-700">
                                Needs Production
                            </span>
                        </label>
                        <Field label="Low Stock Alert">
                            <FieldInput
                                className="input text-sm py-1"
                                type="number"
                                min="0"
                                {...register("low_stock_threshold")}
                            />
                        </Field>
                    </div>

                    {/* Shipping */}
                    <div className="card">
                        <div className="px-3 py-2 border-b border-surface-100">
                            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Shipping
                            </p>
                        </div>
                        <div className="p-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-2">
                            <Field label="Weight kg">
                                <FieldInput
                                    className="input text-sm py-1"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    {...register("weight")}
                                    placeholder="0.00"
                                />
                            </Field>
                            <Field label="Length cm">
                                <FieldInput
                                    className="input text-sm py-1"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    {...register("length")}
                                    placeholder="0.0"
                                />
                            </Field>
                            <Field label="Width cm">
                                {" "}
                                <FieldInput
                                    className="input text-sm py-1"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    {...register("width")}
                                    placeholder="0.0"
                                />
                            </Field>
                            <Field label="Height cm">
                                <FieldInput
                                    className="input text-sm py-1"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    {...register("height")}
                                    placeholder="0.0"
                                />
                            </Field>
                        </div>
                    </div>

                    {/* Info panel */}
                    {isEditing && product && (
                        <div className="card p-3 space-y-1.5 text-xs">
                            <p className="font-semibold text-surface-500 uppercase tracking-wider text-2xs mb-1">
                                Info
                            </p>
                            <div className="flex justify-between">
                                <span className="text-surface-400">Images</span>
                                <span className="text-surface-700">
                                    {images.length}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-surface-400">
                                    Variants
                                </span>
                                <span className="text-surface-700">
                                    {product.variants?.length ?? 0}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-surface-400">
                                    Created
                                </span>
                                <span className="text-surface-700">
                                    {new Date(
                                        product.created_at,
                                    ).toLocaleDateString("en-GB", {
                                        day: "numeric",
                                        month: "short",
                                    })}
                                </span>
                            </div>
                            {product.published_at && (
                                <div className="flex justify-between">
                                    <span className="text-surface-400">
                                        Published
                                    </span>
                                    <span className="text-surface-700">
                                        {new Date(
                                            product.published_at,
                                        ).toLocaleDateString("en-GB", {
                                            day: "numeric",
                                            month: "short",
                                        })}
                                    </span>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Variant generator */}
            {isEditing && (
                <VariantGenerator
                    open={variantGenerator}
                    onClose={() => setVariantGenerator(false)}
                    productId={Number(id)}
                    currencies={currencies}
                    productSku={watch("sku") || product?.sku || ""}
                    productName={watch("translations.0.name") || product?.translations?.[0]?.name || ""}
                    productPrices={watch("prices") ?? []}
                    onSaved={() =>
                        qc.invalidateQueries({ queryKey: ["product", id] })
                    }
                />
            )}

            {/* Edit single variant */}
            {editingVariant && isEditing && (
                <EditVariantModal
                    open={!!editingVariant}
                    onClose={() => setEditingVariant(null)}
                    productId={Number(id)}
                    editing={editingVariant}
                    currencies={currencies}
                    productPrices={watch("prices") ?? []}
                    onSaved={() =>
                        qc.invalidateQueries({ queryKey: ["product", id] })
                    }
                />
            )}

            {/* Delete variant confirm */}
            <ConfirmDialog
                open={!!deletingVariant}
                onClose={() => setDeletingVariant(null)}
                onConfirm={() =>
                    deletingVariant &&
                    deleteVariantMutation.mutate(deletingVariant.id)
                }
                isLoading={deleteVariantMutation.isPending}
                title="Delete Variant"
                message={`Delete "${deletingVariant?.variant_name}"? This cannot be undone.`}
                confirmLabel="Delete"
            />

            {/* Product image zoom */}
            <ImageZoomModal
                images={images}
                index={zoomedIndex}
                onNavigate={setZoomedIndex}
                onClose={() => setZoomedIndex(null)}
            />
        </div>
    );
}