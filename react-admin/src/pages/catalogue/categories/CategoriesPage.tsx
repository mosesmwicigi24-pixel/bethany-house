import { useState, useCallback, useRef, useMemo, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
    DndContext,
    DragOverlay,
    closestCenter,
    PointerSensor,
    KeyboardSensor,
    useSensor,
    useSensors,
    type DragStartEvent,
    type DragEndEvent,
} from "@dnd-kit/core";
import {
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
    useSortable,
    arrayMove,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { categoriesApi } from "@/api/categories";
import type { Category, CategoryFormData } from "@/api/categories";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Field, useFieldAriaProps,
    Toggle,
    StatusBadge,
    ConfirmDialog,
    FieldInput, FieldSelect, FieldTextarea
} from "@/components/setup/FormComponents";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ── Schema ────────────────────────────────────────────────────────────────────

const categorySchema = z.object({
    name_en: z.string().min(1, "English name is required"),
    name_sw: z.string().optional(),
    name_fr: z.string().optional(),
    name_pt: z.string().optional(),
    description_en: z.string().optional(),
    description_sw: z.string().optional(),
    description_fr: z.string().optional(),
    description_pt: z.string().optional(),
    slug: z.string().optional(),
    parent_id: z.number().nullable(),
    icon: z.string().optional(),
    color: z.string().optional(),
    is_active: z.boolean(),
    show_in_menu: z.boolean(),
    show_in_storefront: z.boolean(),
    featured: z.boolean(),
    meta_title: z.string().optional(),
    meta_description: z.string().optional(),
    meta_keywords: z.string().optional(),
});

type FormValues = z.infer<typeof categorySchema>;
type FormTab = "general" | "translations" | "display" | "seo";

const DEFAULTS: FormValues = {
    name_en: "",
    name_sw: "",
    name_fr: "",
    name_pt: "",
    description_en: "",
    description_sw: "",
    description_fr: "",
    description_pt: "",
    slug: "",
    parent_id: null,
    icon: "",
    color: "",
    is_active: true,
    show_in_menu: true,
    show_in_storefront: true,
    featured: false,
    meta_title: "",
    meta_description: "",
    meta_keywords: "",
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function countDescendants(cat: Category): number {
    if (!cat.children?.length) return 0;
    return cat.children.reduce((sum, c) => sum + 1 + countDescendants(c), 0);
}

/** Deep clone tree updating sort_order on a set of siblings */
function updateSortOrders(
    tree: Category[],
    parentId: number | null,
    reordered: Category[],
): Category[] {
    if (parentId === null) {
        return reordered.map((c, i) => ({ ...c, sort_order: i + 1 }));
    }
    return tree.map((cat) => {
        if (cat.id === parentId) {
            return {
                ...cat,
                children: reordered.map((c, i) => ({
                    ...c,
                    sort_order: i + 1,
                })),
            };
        }
        if (cat.children?.length) {
            return {
                ...cat,
                children: updateSortOrders(cat.children, parentId, reordered),
            };
        }
        return cat;
    });
}

/** Find siblings at a given parentId level */
function getSiblings(tree: Category[], parentId: number | null): Category[] {
    if (parentId === null) return tree;
    for (const cat of tree) {
        if (cat.id === parentId) return cat.children ?? [];
        if (cat.children?.length) {
            const found = getSiblings(cat.children, parentId);
            if (found.length > 0) return found;
        }
    }
    return [];
}

/** Find which parent a category belongs to */
function findParentId(tree: Category[], id: number): number | null {
    for (const cat of tree) {
        if (cat.children?.some((c) => c.id === id)) return cat.id;
        if (cat.children?.length) {
            const found = findParentId(cat.children, id);
            if (found !== undefined) return found;
        }
    }
    return null;
}

/** Find a category by id anywhere in the tree */
function findCategory(tree: Category[], id: number): Category | null {
    for (const cat of tree) {
        if (cat.id === id) return cat;
        if (cat.children?.length) {
            const found = findCategory(cat.children, id);
            if (found) return found;
        }
    }
    return null;
}

// ── Drag handle ───────────────────────────────────────────────────────────────

function DragHandle(props: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            {...props}
            title="Drag to reorder"
            className="w-5 h-5 flex items-center justify-center rounded text-surface-300 hover:text-surface-500 hover:bg-surface-100 cursor-grab active:cursor-grabbing transition-colors shrink-0 touch-none"
        >
            <svg
                className="w-3.5 h-3.5"
                fill="currentColor"
                viewBox="0 0 16 16"
            >
                <circle cx="5.5" cy="4" r="1.2" />
                <circle cx="5.5" cy="8" r="1.2" />
                <circle cx="5.5" cy="12" r="1.2" />
                <circle cx="10.5" cy="4" r="1.2" />
                <circle cx="10.5" cy="8" r="1.2" />
                <circle cx="10.5" cy="12" r="1.2" />
            </svg>
        </div>
    );
}

// ── Sortable tree node ────────────────────────────────────────────────────────

interface SortableNodeProps {
    category: Category;
    depth: number;
    selected: number | null;
    expanded: Set<number>;
    sensors: ReturnType<typeof useSensors>;
    isDragOverlay?: boolean;
    onSelect: (c: Category) => void;
    onToggleExpand: (id: number) => void;
    onAddChild: (parentId: number) => void;
    onEdit: (c: Category) => void;
    onDelete: (c: Category) => void;
    onToggle: (id: number) => void;
    onImageUpload: (id: number, file: File) => void;
    isToggling: boolean;
    /** Called when drag ends within this node's children */
    onChildrenReorder: (
        parentId: number | null,
        active: number,
        over: number,
    ) => void;
}

function SortableTreeNode({
    category,
    depth,
    selected,
    expanded,
    sensors,
    isDragOverlay = false,
    onSelect,
    onToggleExpand,
    onAddChild,
    onEdit,
    onDelete,
    onToggle,
    onImageUpload,
    isToggling,
    onChildrenReorder,
}: SortableNodeProps) {
    const fileRef = useRef<HTMLInputElement>(null);
    const { can } = usePermissions();
    const canEdit = can("products.edit");
    const canDelete = can("products.delete");

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: category.id });

    const style: React.CSSProperties = {
        transform: CSS.Transform.toString(transform),
        transition: isDragOverlay ? undefined : transition,
    };

    const isSelected = selected === category.id;
    const isExpanded = expanded.has(category.id);
    const hasChildren = (category.children?.length ?? 0) > 0;
    const descendants = countDescendants(category);

    const childIds = useMemo(
        () => (category.children ?? []).map((c) => c.id),
        [category.children],
    );

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={clsx(isDragging && !isDragOverlay && "opacity-30")}
        >
            {/* Node row */}
            <div
                className={clsx(
                    "group flex items-center gap-1 px-1.5 py-1 rounded-lg transition-all text-sm select-none",
                    isSelected
                        ? "bg-brand-50 text-brand-700"
                        : "hover:bg-surface-50 text-surface-700",
                    !category.is_active && "opacity-50",
                    isDragOverlay &&
                        "shadow-lg bg-white border border-brand-200 rounded-xl ring-2 ring-brand-300",
                )}
                style={{ paddingLeft: `${4 + depth * 18}px` }}
            >
                {/* Drag handle */}
                {canEdit && <DragHandle {...attributes} {...listeners} />}

                {/* Expand toggle */}
                <button
                    onClick={(e) => {
                        e.stopPropagation();
                        if (hasChildren) onToggleExpand(category.id);
                    }}
                    className={clsx(
                        "w-4 h-4 flex items-center justify-center rounded shrink-0 transition-colors",
                        hasChildren
                            ? "hover:bg-surface-200 cursor-pointer"
                            : "cursor-default",
                    )}
                >
                    {hasChildren ? (
                        <svg
                            className={clsx(
                                "w-3 h-3 text-surface-400 transition-transform duration-150",
                                isExpanded && "rotate-90",
                            )}
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2.5}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M9 5l7 7-7 7"
                            />
                        </svg>
                    ) : (
                        <span className="w-1.5 h-1.5 rounded-full bg-surface-200 block" />
                    )}
                </button>

                {/* Thumbnail */}
                <div
                    className={clsx(
                        "w-6 h-6 rounded-md overflow-hidden shrink-0 bg-surface-100 flex items-center justify-center text-xs",
                        canEdit && "cursor-pointer",
                    )}
                    onClick={() => canEdit && fileRef.current?.click()}
                    title={canEdit ? "Click to change image" : undefined}
                >
                    {category.image_url ? (
                        <img
                            src={category.image_url}
                            alt=""
                            className="w-full h-full object-cover"
                        />
                    ) : category.icon ? (
                        <span>{category.icon}</span>
                    ) : (
                        <svg
                            className="w-3 h-3 text-surface-300"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.5}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                            />
                        </svg>
                    )}
                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/*"
                        className="hidden"
                        onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f) onImageUpload(category.id, f);
                        }}
                    />
                </div>

                {/* Name - clickable to select */}
                <span
                    className="flex-1 truncate font-medium text-sm cursor-pointer"
                    onClick={() => onSelect(category)}
                >
                    {category.name_en}
                </span>

                {/* Right-side badges + actions */}
                <div className="flex items-center gap-1 shrink-0">
                    {descendants > 0 && (
                        <span className="text-2xs text-surface-300 font-mono tabular-nums">
                            {descendants}
                        </span>
                    )}
                    {category.featured && (
                        <span className="text-amber-400 text-xs leading-none">
                            ★
                        </span>
                    )}
                    {!category.is_active && (
                        <span className="text-2xs text-surface-300">off</span>
                    )}

                    {/* Hover actions */}
                    <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                        {canEdit && (
                        <button
                            title="Add subcategory"
                            onClick={(e) => {
                                e.stopPropagation();
                                onAddChild(category.id);
                            }}
                            className="w-5 h-5 rounded flex items-center justify-center text-surface-400 hover:text-brand-600 hover:bg-brand-50 transition-colors"
                            aria-label="Add"
                        >
                            <svg
                                className="w-3 h-3"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2.5}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M12 4.5v15m7.5-7.5h-15"
                                />
                            </svg>
                        </button>
                        )}
                        {canEdit && (
                        <button
                            title="Edit"
                            onClick={(e) => {
                                e.stopPropagation();
                                onEdit(category);
                            }}
                            className="w-5 h-5 rounded flex items-center justify-center text-surface-400 hover:text-surface-700 hover:bg-surface-200 transition-colors"
                            aria-label="Edit"
                        >
                            <svg
                                className="w-3 h-3"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                />
                            </svg>
                        </button>
                        )}
                        {canEdit && (
                        <button
                            title={category.is_active ? "Disable" : "Enable"}
                            onClick={(e) => {
                                e.stopPropagation();
                                onToggle(category.id);
                            }}
                            disabled={isToggling}
                            className={clsx(
                                "w-5 h-5 rounded flex items-center justify-center transition-colors",
                                category.is_active
                                    ? "text-surface-400 hover:text-danger hover:bg-danger-light"
                                    : "text-surface-400 hover:text-success hover:bg-success-light",
                            )}
                        >
                            {category.is_active ? (
                                <svg
                                    className="w-3 h-3"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={2}
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
                                    />
                                </svg>
                            ) : (
                                <svg
                                    className="w-3 h-3"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={2}
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                                    />
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                    />
                                </svg>
                            )}
                        </button>
                        )}
                        {canDelete && (
                        <button
                            title="Delete"
                            onClick={(e) => {
                                e.stopPropagation();
                                onDelete(category);
                            }}
                            className="w-5 h-5 rounded flex items-center justify-center text-surface-400 hover:text-danger hover:bg-danger-light transition-colors"
                            aria-label="Delete"
                        >
                            <svg
                                className="w-3 h-3"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
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
                </div>
            </div>

            {/* Children - each level gets its own DndContext + SortableContext */}
            {isExpanded && hasChildren && !isDragOverlay && (
                <div className="relative">
                    {/* Vertical connector line */}
                    <div
                        className="absolute top-0 bottom-0 w-px bg-surface-100"
                        style={{ left: `${4 + depth * 18 + 13}px` }}
                    />
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={(event) => {
                            const { active, over } = event;
                            if (over && active.id !== over.id) {
                                onChildrenReorder(
                                    category.id,
                                    Number(active.id),
                                    Number(over.id),
                                );
                            }
                        }}
                    >
                        <SortableContext
                            items={childIds}
                            strategy={verticalListSortingStrategy}
                        >
                            {category.children!.map((child) => (
                                <SortableTreeNode
                                    key={child.id}
                                    category={child}
                                    depth={depth + 1}
                                    selected={selected}
                                    expanded={expanded}
                                    sensors={sensors}
                                    onSelect={onSelect}
                                    onToggleExpand={onToggleExpand}
                                    onAddChild={onAddChild}
                                    onEdit={onEdit}
                                    onDelete={onDelete}
                                    onToggle={onToggle}
                                    onImageUpload={onImageUpload}
                                    isToggling={isToggling}
                                    onChildrenReorder={onChildrenReorder}
                                />
                            ))}
                        </SortableContext>
                    </DndContext>
                </div>
            )}
        </div>
    );
}

// ── Sensors hook - extracted so it can be reused without hooks-in-loops ───────

function useDndSensors() {
    return useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
}

// ── Detail panel ──────────────────────────────────────────────────────────────

interface DetailPanelProps {
    category: Category;
    onEdit: (c: Category) => void;
    onDelete: (c: Category) => void;
    onToggle: (id: number) => void;
    onAddChild: (parentId: number) => void;
    onImageUpload: (id: number, file: File) => void;
    isToggling: boolean;
}

function DetailPanel({
    category,
    onEdit,
    onDelete,
    onToggle,
    onAddChild,
    onImageUpload,
    isToggling,
}: DetailPanelProps) {
    const fileRef = useRef<HTMLInputElement>(null);
    const descendants = countDescendants(category);
    const { can } = usePermissions();
    const canEdit = can("products.edit");
    const canDelete = can("products.delete");

    return (
        <div className="flex flex-col h-full">
            <div className="p-5 border-b border-surface-100">
                <div className="flex items-start gap-4">
                    <div
                        className={clsx(
                            "w-16 h-16 rounded-xl overflow-hidden shrink-0 bg-surface-100 flex items-center justify-center group relative border border-surface-200",
                            canEdit && "cursor-pointer",
                        )}
                        onClick={() => canEdit && fileRef.current?.click()}
                    >
                        {category.image_url ? (
                            <img
                                src={category.image_url}
                                alt={category.name_en}
                                className="w-full h-full object-cover"
                            />
                        ) : category.icon ? (
                            <span className="text-3xl">{category.icon}</span>
                        ) : (
                            <svg
                                className="w-7 h-7 text-surface-300"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={1.5}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                                />
                            </svg>
                        )}
                        <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity rounded-xl">
                            <svg
                                className="w-5 h-5 text-white"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"
                                />
                            </svg>
                        </div>
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) onImageUpload(category.id, f);
                            }}
                        />
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2 className="text-lg font-bold text-surface-900 truncate">
                                {category.name_en}
                            </h2>
                            <StatusBadge active={category.is_active} />
                            {category.featured && (
                                <span className="badge text-2xs bg-amber-50 text-amber-600 border border-amber-200">
                                    ★ Featured
                                </span>
                            )}
                        </div>
                        <p className="text-xs text-surface-400 font-mono mt-0.5">
                            /{category.slug}
                        </p>
                        {category.parent && (
                            <p className="text-xs text-surface-500 mt-1">
                                <span className="text-surface-300">Under</span>{" "}
                                {category.breadcrumb
                                    .split(" > ")
                                    .slice(0, -1)
                                    .join(" > ")}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex gap-2 mt-4">
                    {canEdit && (
                    <button
                        onClick={() => onEdit(category)}
                        className="btn-primary btn-sm flex-1 gap-1"
                    >
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
                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                            />
                        </svg>
                        Edit
                    </button>
                    )}
                    {canEdit && (
                    <button
                        onClick={() => onAddChild(category.id)}
                        className="btn-secondary btn-sm flex-1 gap-1"
                    >
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
                                d="M12 4.5v15m7.5-7.5h-15"
                            />
                        </svg>
                        Add Sub
                    </button>
                    )}
                    {canEdit && (
                    <button
                        onClick={() => onToggle(category.id)}
                        disabled={isToggling}
                        className={clsx(
                            "btn-sm btn-secondary px-3",
                            category.is_active ? "text-danger" : "text-success",
                        )}
                    >
                        {category.is_active ? (
                            <svg
                                className="w-4 h-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={1.75}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"
                                />
                            </svg>
                        ) : (
                            <svg
                                className="w-4 h-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={1.75}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                                />
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                />
                            </svg>
                        )}
                    </button>
                    )}
                    {canDelete && (
                    <button
                        onClick={() => onDelete(category)}
                        className="btn-sm btn-secondary text-danger px-3"
                        aria-label="Delete"
                    >
                        <svg
                            className="w-4 h-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.75}
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
            </div>

            <div className="grid grid-cols-3 divide-x divide-surface-100 border-b border-surface-100 shrink-0">
                {[
                    { label: "Products", value: category.products_count },
                    {
                        label: "Subcategories",
                        value: category.children?.length ?? 0,
                    },
                    { label: "Descendants", value: descendants },
                ].map((s) => (
                    <div key={s.label} className="py-3 px-4 text-center">
                        <p className="text-xl font-bold text-surface-900">
                            {s.value}
                        </p>
                        <p className="text-2xs text-surface-400 mt-0.5">
                            {s.label}
                        </p>
                    </div>
                ))}
            </div>

            <div className="flex-1 overflow-y-auto p-5 space-y-4">
                {category.description_en && (
                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-1">
                            Description
                        </p>
                        <p className="text-sm text-surface-600 leading-relaxed">
                            {category.description_en}
                        </p>
                    </div>
                )}
                <div>
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                        Visibility
                    </p>
                    <div className="space-y-1.5">
                        {[
                            {
                                label: "Navigation menu",
                                value: category.show_in_menu,
                            },
                            {
                                label: "Storefront",
                                value: category.show_in_storefront,
                            },
                        ].map((v) => (
                            <div
                                key={v.label}
                                className="flex items-center justify-between text-sm"
                            >
                                <span className="text-surface-600">
                                    {v.label}
                                </span>
                                <span
                                    className={clsx(
                                        "text-xs font-medium",
                                        v.value
                                            ? "text-success"
                                            : "text-surface-400",
                                    )}
                                >
                                    {v.value ? "✓ Visible" : "✕ Hidden"}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
                {(category.name_sw || category.name_fr || category.name_pt) && (
                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Translations
                        </p>
                        <div className="space-y-1">
                            {[
                                { lang: "Swahili", value: category.name_sw },
                                { lang: "French", value: category.name_fr },
                                { lang: "Portuguese", value: category.name_pt },
                            ]
                                .filter((t) => t.value)
                                .map((t) => (
                                    <div
                                        key={t.lang}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <span className="text-surface-400 w-24 shrink-0">
                                            {t.lang}
                                        </span>
                                        <span className="text-surface-700">
                                            {t.value}
                                        </span>
                                    </div>
                                ))}
                        </div>
                    </div>
                )}
                {(category.meta_title || category.meta_description) && (
                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            SEO Preview
                        </p>
                        <div className="border border-surface-100 rounded-xl p-3 bg-surface-50 space-y-1">
                            <p className="text-sm text-blue-600 font-medium truncate">
                                {category.meta_title || category.name_en} |
                                Bethany House
                            </p>
                            <p className="text-xs text-green-700">
                                bethanyhouse.co.ke/categories/{category.slug}
                            </p>
                            {category.meta_description && (
                                <p className="text-xs text-surface-500 line-clamp-2">
                                    {category.meta_description}
                                </p>
                            )}
                        </div>
                    </div>
                )}
                {(category.children?.length ?? 0) > 0 && (
                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Subcategories
                        </p>
                        <div className="space-y-1">
                            {category.children!.map((child) => (
                                <div
                                    key={child.id}
                                    className="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-50 border border-surface-100"
                                >
                                    <span className="text-sm">
                                        {child.icon || "📁"}
                                    </span>
                                    <span className="text-sm text-surface-700 flex-1">
                                        {child.name_en}
                                    </span>
                                    <span
                                        className={clsx(
                                            "text-2xs",
                                            child.is_active
                                                ? "text-success"
                                                : "text-surface-300",
                                        )}
                                    >
                                        {child.is_active
                                            ? "Active"
                                            : "Inactive"}
                                    </span>
                                    {(child.children?.length ?? 0) > 0 && (
                                        <span className="text-2xs text-surface-400 font-mono">
                                            {child.children!.length} sub
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
                <div className="pt-2 border-t border-surface-50">
                    <dl className="space-y-1 text-xs text-surface-400">
                        <div className="flex justify-between">
                            <dt>Sort order</dt>
                            <dd className="font-mono">{category.sort_order}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt>Created</dt>
                            <dd>
                                {new Date(
                                    category.created_at,
                                ).toLocaleDateString("en-GB", {
                                    day: "numeric",
                                    month: "short",
                                    year: "numeric",
                                })}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────

export default function CategoriesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canCreate = can("products.edit");

    const [selected, setSelected] = useState<Category | null>(null);
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    const [search, setSearch] = useState("");
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Category | null>(null);
    const [deleting, setDeleting] = useState<Category | null>(null);
    const [activeTab, setActiveTab] = useState<FormTab>("general");

    // Local tree state for optimistic DnD updates
    const [localTree, setLocalTree] = useState<Category[] | null>(null);

    // Drag overlay state
    const [draggingCategory, setDraggingCategory] = useState<Category | null>(
        null,
    );

    const sensors = useDndSensors();

    // ── Data ──────────────────────────────────────────────────────────────────

    const { data, isLoading } = useQuery({
        queryKey: ["categories-tree"],
        queryFn: () => categoriesApi.tree(),
    });

    useEffect(() => {
        if (!data) return;
        if (expanded.size === 0 && data.data.length > 0) {
            setExpanded(new Set(data.data.map((c: Category) => c.id)));
        }
        setLocalTree(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data]);

    const { data: flatData } = useQuery({
        queryKey: ["categories-flat"],
        queryFn: () => categoriesApi.list(),
    });

    const serverTree = data?.data ?? [];
    const treeCategories = localTree ?? serverTree;
    const flatCategories = flatData?.data ?? [];
    const stats = data?.stats;

    const rootIds = useMemo(
        () => treeCategories.map((c) => c.id),
        [treeCategories],
    );

    // Filter tree for search
    function filterTree(cats: Category[]): Category[] {
        if (!search) return cats;
        return cats.reduce<Category[]>((acc, cat) => {
            const match =
                cat.name_en.toLowerCase().includes(search.toLowerCase()) ||
                cat.slug.toLowerCase().includes(search.toLowerCase());
            const filteredChildren = filterTree(cat.children ?? []);
            if (match || filteredChildren.length > 0)
                acc.push({ ...cat, children: filteredChildren });
            return acc;
        }, []);
    }

    const visibleTree = filterTree(treeCategories);

    // ── Reorder mutation ──────────────────────────────────────────────────────

    const reorderMutation = useMutation({
        mutationFn: (items: { id: number; sort_order: number }[]) =>
            categoriesApi.reorder(items),
        onSuccess: () =>
            qc.invalidateQueries({ queryKey: ["categories-tree"] }),
        onError: (err: ApiError) => {
            toast.error("Failed to save order - reverting.");
            setLocalTree(null); // revert optimistic update
        },
    });

    // Called when a drag ends at any level
    const handleChildrenReorder = useCallback(
        (parentId: number | null, activeId: number, overId: number) => {
            const siblings = getSiblings(treeCategories, parentId);
            const oldIndex = siblings.findIndex((c) => c.id === activeId);
            const newIndex = siblings.findIndex((c) => c.id === overId);
            if (oldIndex === -1 || newIndex === -1) return;

            const reordered = arrayMove(siblings, oldIndex, newIndex);
            const newTree =
                parentId === null
                    ? reordered.map((c, i) => ({ ...c, sort_order: i + 1 }))
                    : updateSortOrders(treeCategories, parentId, reordered);

            // Optimistic update
            setLocalTree(newTree);

            // Persist
            reorderMutation.mutate(
                reordered.map((c, i) => ({ id: c.id, sort_order: i + 1 })),
            );
        },
        [treeCategories, reorderMutation],
    );

    // Root-level drag end
    const handleRootDragEnd = useCallback(
        (event: DragEndEvent) => {
            setDraggingCategory(null);
            const { active, over } = event;
            if (over && active.id !== over.id) {
                handleChildrenReorder(null, Number(active.id), Number(over.id));
            }
        },
        [handleChildrenReorder],
    );

    const handleRootDragStart = useCallback(
        (event: DragStartEvent) => {
            const cat = findCategory(treeCategories, Number(event.active.id));
            setDraggingCategory(cat);
        },
        [treeCategories],
    );

    // ── Form ──────────────────────────────────────────────────────────────────

    const form = useForm<FormValues>({
        resolver: zodResolver(categorySchema),
        defaultValues: DEFAULTS,
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    const openCreate = useCallback(
        (parentId: number | null = null) => {
            reset({ ...DEFAULTS, parent_id: parentId });
            setEditing(null);
            setActiveTab("general");
            setModalOpen(true);
        },
        [reset],
    );

    const openEdit = useCallback(
        (c: Category) => {
            reset({
                name_en: c.name_en,
                name_sw: c.name_sw ?? "",
                name_fr: c.name_fr ?? "",
                name_pt: c.name_pt ?? "",
                description_en: c.description_en ?? "",
                description_sw: c.description_sw ?? "",
                description_fr: c.description_fr ?? "",
                description_pt: c.description_pt ?? "",
                slug: c.slug,
                parent_id: c.parent_id,
                icon: c.icon ?? "",
                color: c.color ?? "",
                is_active: c.is_active,
                show_in_menu: c.show_in_menu,
                show_in_storefront: c.show_in_storefront,
                featured: c.featured,
                meta_title: c.meta_title ?? "",
                meta_description: c.meta_description ?? "",
                meta_keywords: c.meta_keywords ?? "",
            });
            setEditing(c);
            setActiveTab("general");
            setModalOpen(true);
        },
        [reset],
    );

    const toggleExpand = useCallback((id: number) => {
        setExpanded((prev) => {
            const n = new Set(prev);
            n.has(id) ? n.delete(id) : n.add(id);
            return n;
        });
    }, []);
    const expandAll = () =>
        setExpanded(new Set(flatCategories.map((c) => c.id)));
    const collapseAll = () => setExpanded(new Set());

    // ── Mutations ─────────────────────────────────────────────────────────────

    const saveMutation = useMutation({
        mutationFn: (values: FormValues) => {
            const payload: CategoryFormData = {
                ...values,
                parent_id: values.parent_id ?? null,
                slug: values.slug || undefined,
            };
            return editing
                ? categoriesApi.update(editing.id, payload)
                : categoriesApi.create(payload);
        },
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["categories-tree"] });
            qc.invalidateQueries({ queryKey: ["categories-flat"] });
            toast.success(editing ? "Category updated." : "Category created.");
            setModalOpen(false);
            if (res.category) setSelected(res.category);
        },
        onError: (err: ApiError) => {
            if (err.errors?.name_en)
                form.setError("name_en", { message: err.errors.name_en[0] });
            else toast.error(err.message);
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => categoriesApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["categories-tree"] });
            qc.invalidateQueries({ queryKey: ["categories-flat"] });
            toast.success("Category deleted.");
            setDeleting(null);
            setSelected(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMutation = useMutation({
        mutationFn: (id: number) => categoriesApi.toggle(id),
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["categories-tree"] });
            if (selected?.id === res.category?.id) setSelected(res.category);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const imageMutation = useMutation({
        mutationFn: ({ id, file }: { id: number; file: File }) =>
            categoriesApi.uploadImage(id, file),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["categories-tree"] });
            toast.success("Image updated.");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const availableParents = flatCategories.filter(
        (c) => !editing || c.id !== editing.id,
    );

    const tabs: { id: FormTab; label: string }[] = [
        { id: "general", label: "General" },
        { id: "translations", label: "Translations" },
        { id: "display", label: "Display" },
        { id: "seo", label: "SEO" },
    ];

    // ── Render ─────────────────────────────────────────────────────────────────

    return (
        <div
            className="flex flex-col animate-fade-in"
            style={{ minHeight: "calc(100vh - 120px)" }}
        >
            {/* Header */}
            <div className="flex flex-col gap-3 mb-4 shrink-0 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Categories</h1>
                    <p className="page-subtitle">
                        {flatCategories.length}{" "}
                        {flatCategories.length === 1
                            ? "category"
                            : "categories"}
                        {stats
                            ? ` · ${stats.active} active · ${stats.featured} featured`
                            : ""}
                        {reorderMutation.isPending && (
                            <span className="ml-2 text-brand-500 text-xs">
                                Saving order…
                            </span>
                        )}
                    </p>
                </div>
                {canCreate && (
                <button onClick={() => openCreate()} className="btn-primary">
                    <svg
                        className="w-4 h-4"
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
                    New Category
                </button>
                )}
            </div>

            {/* Two-panel layout: stacks on mobile, side-by-side on lg+ */}
            <div className="flex flex-col gap-4 flex-1 min-h-0 lg:flex-row">
                {/* LEFT: Tree */}
                <div className="w-full lg:w-80 lg:shrink-0 flex flex-col card overflow-hidden" style={{ minHeight: "400px" }}>
                    <div className="p-3 border-b border-surface-100 space-y-2">
                        <input
                            className="input text-sm"
                            placeholder="Search categories…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <div className="flex items-center justify-between">
                            <div className="flex gap-1">
                                <button
                                    onClick={expandAll}
                                    className="text-2xs text-surface-400 hover:text-surface-600 px-1.5 py-0.5 rounded hover:bg-surface-100 transition-colors"
                                >
                                    Expand all
                                </button>
                                <button
                                    onClick={collapseAll}
                                    className="text-2xs text-surface-400 hover:text-surface-600 px-1.5 py-0.5 rounded hover:bg-surface-100 transition-colors"
                                >
                                    Collapse
                                </button>
                            </div>
                            <span className="text-2xs text-surface-400 font-mono">
                                {flatCategories.length} total
                            </span>
                        </div>
                        <p className="text-2xs text-surface-300 flex items-center gap-1">
                            <svg
                                className="w-3 h-3"
                                fill="currentColor"
                                viewBox="0 0 16 16"
                            >
                                <circle cx="5.5" cy="4" r="1.2" />
                                <circle cx="5.5" cy="8" r="1.2" />
                                <circle cx="5.5" cy="12" r="1.2" />
                                <circle cx="10.5" cy="4" r="1.2" />
                                <circle cx="10.5" cy="8" r="1.2" />
                                <circle cx="10.5" cy="12" r="1.2" />
                            </svg>
                            Drag to reorder within same level
                        </p>
                    </div>

                    <div className="flex-1 overflow-y-auto p-2">
                        {isLoading ? (
                            <div className="flex justify-center py-8">
                                <Spinner size="md" />
                            </div>
                        ) : visibleTree.length === 0 ? (
                            <div className="text-center py-8">
                                <p className="text-sm text-surface-400">
                                    {search
                                        ? "No matches."
                                        : "No categories yet."}
                                </p>
                                {!search && canCreate && (
                                    <button
                                        onClick={() => openCreate()}
                                        className="mt-3 btn-primary btn-sm text-xs"
                                    >
                                        Create first
                                    </button>
                                )}
                            </div>
                        ) : (
                            /* Root level DnD */
                            <DndContext
                                sensors={sensors}
                                collisionDetection={closestCenter}
                                onDragStart={handleRootDragStart}
                                onDragEnd={handleRootDragEnd}
                            >
                                <SortableContext
                                    items={rootIds}
                                    strategy={verticalListSortingStrategy}
                                >
                                    <div className="space-y-0.5">
                                        {visibleTree.map((cat) => (
                                            <SortableTreeNode
                                                key={cat.id}
                                                category={cat}
                                                depth={0}
                                                selected={selected?.id ?? null}
                                                expanded={expanded}
                                                sensors={sensors}
                                                onSelect={setSelected}
                                                onToggleExpand={toggleExpand}
                                                onAddChild={openCreate}
                                                onEdit={openEdit}
                                                onDelete={setDeleting}
                                                onToggle={(id) =>
                                                    toggleMutation.mutate(id)
                                                }
                                                onImageUpload={(id, file) =>
                                                    imageMutation.mutate({
                                                        id,
                                                        file,
                                                    })
                                                }
                                                isToggling={
                                                    toggleMutation.isPending
                                                }
                                                onChildrenReorder={
                                                    handleChildrenReorder
                                                }
                                            />
                                        ))}
                                    </div>
                                </SortableContext>

                                {/* Drag overlay - ghost image while dragging */}
                                <DragOverlay>
                                    {draggingCategory && (
                                        <div className="bg-white border border-brand-300 rounded-xl shadow-xl px-3 py-2 flex items-center gap-2 text-sm font-medium text-brand-700 ring-2 ring-brand-200">
                                            <span className="text-base">
                                                {draggingCategory.icon || "📁"}
                                            </span>
                                            {draggingCategory.name_en}
                                        </div>
                                    )}
                                </DragOverlay>
                            </DndContext>
                        )}
                    </div>
                </div>

                {/* RIGHT: Detail */}
                <div className="flex-1 card overflow-hidden">
                    {selected ? (
                        <DetailPanel
                            category={selected}
                            onEdit={openEdit}
                            onDelete={setDeleting}
                            onToggle={(id) => toggleMutation.mutate(id)}
                            onAddChild={openCreate}
                            onImageUpload={(id, file) =>
                                imageMutation.mutate({ id, file })
                            }
                            isToggling={toggleMutation.isPending}
                        />
                    ) : (
                        <div className="flex flex-col items-center justify-center h-full text-center px-8">
                            <div className="w-16 h-16 rounded-2xl bg-surface-100 flex items-center justify-center mb-4">
                                <svg
                                    className="w-8 h-8 text-surface-300"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={1.5}
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                                    />
                                </svg>
                            </div>
                            <h3 className="text-sm font-semibold text-surface-700 mb-1">
                                Select a category
                            </h3>
                            <p className="text-xs text-surface-400 max-w-xs">
                                Click any category in the tree to view details,
                                manage subcategories, and update SEO settings.
                            </p>
                            {treeCategories.length > 0 && (
                                <p className="text-2xs text-surface-300 mt-3">
                                    Drag the{" "}
                                    <span className="font-mono">⠿</span> handle
                                    to reorder within a level
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* CREATE / EDIT MODAL */}
            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit - ${editing.name_en}` : "New Category"}
                size="lg"
                footer={
                    <>
                        <button
                            onClick={() => setModalOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleSubmit((v) =>
                                saveMutation.mutate(v),
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
                            {editing ? "Save Changes" : "Create Category"}
                        </button>
                    </>
                }
            >
                <div className="flex gap-1 border-b border-surface-100 mb-5 -mx-6 px-6 overflow-x-auto no-scrollbar">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={clsx(
                                "px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap shrink-0",
                                activeTab === tab.id
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700",
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {activeTab === "general" && (
                    <div className="space-y-4">
                        <Field
                            label="Name (English)"
                            error={errors.name_en?.message}
                            required
                        >
                            <FieldInput
                                className="input"
                                {...register("name_en")}
                                placeholder="e.g. Women's Fashion"
                                autoFocus
                            />
                        </Field>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="Slug" hint="Auto-generated if empty">
                                <FieldInput
                                    className="input font-mono text-sm"
                                    {...register("slug")}
                                    placeholder="womens-fashion"
                                />
                            </Field>
                            <Field label="Parent Category">
                                <FieldSelect
                                    className="input"
                                    value={watch("parent_id") ?? ""}
                                    onChange={(e) =>
                                        setValue(
                                            "parent_id",
                                            e.target.value
                                                ? Number(e.target.value)
                                                : null,
                                        )
                                    }
                                >
                                    <option value="">- Root category -</option>
                                    {availableParents.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.breadcrumb || c.name_en}
                                        </option>
                                    ))}
                                </FieldSelect>
                            </Field>
                            <Field label="Icon" hint="Emoji e.g. 👗">
                                <FieldInput
                                    className="input text-xl"
                                    {...register("icon")}
                                    placeholder="👗"
                                />
                            </Field>
                            <Field label="Colour">
                                <div className="flex gap-2">
                                    <FieldInput
                                        type="color"
                                        className="w-10 h-10 rounded cursor-pointer border border-surface-200 p-0.5"
                                        value={watch("color") || "#6366f1"}
                                        onChange={(e) =>
                                            setValue("color", e.target.value)
                                        }
                                    />
                                    <FieldInput
                                        className="input flex-1 font-mono text-sm"
                                        {...register("color")}
                                        placeholder="#6366f1"
                                    />
                                </div>
                            </Field>
                        </div>
                        <Field label="Description (English)">
                            <FieldTextarea
                                className="input resize-none"
                                rows={3}
                                {...register("description_en")}
                                placeholder="Describe this category…"
                            />
                        </Field>
                    </div>
                )}

                {activeTab === "translations" && (
                    <div className="space-y-5">
                        <p className="text-xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                            Leave blank to fall back to English.
                        </p>
                        {[
                            { lang: "sw", label: "Swahili" },
                            { lang: "fr", label: "French" },
                            { lang: "pt", label: "Portuguese" },
                        ].map(({ lang, label }) => (
                            <div key={lang} className="space-y-2">
                                <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    {label}
                                </p>
                                <Field label={`Name - ${label}`}>
                                    <FieldInput
                                        className="input"
                                        {...register(`name_${lang}` as any)}
                                    />
                                </Field>
                                <Field label={`Description - ${label}`}>
                                    <FieldTextarea
                                        className="input resize-none"
                                        rows={2}
                                        {...register(
                                            `description_${lang}` as any,
                                        )}
                                    />
                                </Field>
                            </div>
                        ))}
                    </div>
                )}

                {activeTab === "display" && (
                    <div className="space-y-3">
                        <Toggle
                            checked={watch("is_active")}
                            onChange={(v) => setValue("is_active", v)}
                            label="Active"
                            description="Inactive categories are hidden everywhere."
                        />
                        <Toggle
                            checked={watch("show_in_menu")}
                            onChange={(v) => setValue("show_in_menu", v)}
                            label="Show in navigation menu"
                            description="Appears in the main nav on the storefront."
                        />
                        <Toggle
                            checked={watch("show_in_storefront")}
                            onChange={(v) => setValue("show_in_storefront", v)}
                            label="Show in storefront"
                            description="Visible in listings, search filters, browse."
                        />
                        <Toggle
                            checked={watch("featured")}
                            onChange={(v) => setValue("featured", v)}
                            label="Featured"
                            description="Highlighted on the homepage."
                        />
                    </div>
                )}

                {activeTab === "seo" && (
                    <div className="space-y-4">
                        <p className="text-xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                            Override auto-generated SEO for this category page.
                        </p>
                        <Field label="Meta Title" hint="50–60 characters">
                            <FieldInput
                                className="input"
                                {...register("meta_title")}
                            />
                            <p className="text-2xs text-surface-400 mt-1">
                                {(watch("meta_title") || "").length} / 60
                            </p>
                        </Field>
                        <Field
                            label="Meta Description"
                            hint="150–160 characters"
                        >
                            <FieldTextarea
                                className="input resize-none"
                                rows={3}
                                {...register("meta_description")}
                            />
                            <p className="text-2xs text-surface-400 mt-1">
                                {(watch("meta_description") || "").length} / 160
                            </p>
                        </Field>
                        <Field label="Meta Keywords">
                            <FieldInput
                                className="input"
                                {...register("meta_keywords")}
                            />
                        </Field>
                        {(watch("name_en") || watch("meta_title")) && (
                            <div className="border border-surface-100 rounded-xl p-4 bg-surface-50">
                                <p className="text-2xs text-surface-400 mb-2 font-semibold uppercase tracking-wider">
                                    Preview
                                </p>
                                <p className="text-sm text-blue-600 font-medium truncate">
                                    {watch("meta_title") || watch("name_en")} |
                                    Bethany House
                                </p>
                                <p className="text-xs text-green-700 mt-0.5">
                                    bethanyhouse.co.ke/categories/
                                    {watch("slug") || "slug"}
                                </p>
                                <p className="text-xs text-surface-500 mt-1 line-clamp-2">
                                    {watch("meta_description") ||
                                        watch("description_en") ||
                                        "No description."}
                                </p>
                            </div>
                        )}
                    </div>
                )}
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Delete Category"
                message={
                    deleting?.products_count
                        ? `Cannot delete "${deleting.name_en}" - it has ${deleting.products_count} product(s).`
                        : `Delete "${deleting?.name_en}"? This cannot be undone.`
                }
                confirmLabel={deleting?.products_count ? "OK" : "Delete"}
                confirmDisabled={!!deleting?.products_count}
            />
        </div>
    );
}