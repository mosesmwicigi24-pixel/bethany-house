/**
 * ProductionStagesPage - Settings > Production > Stages
 *
 * Configure the stages a production order passes through (e.g. Cutting,
 * Stitching, Finishing, QC). Supports create, inline-edit, delete, and
 * drag-to-reorder via @dnd-kit (same pattern as CategoriesPage).
 *
 * Route: /settings/production/stages
 * API:   GET/POST/PUT/DELETE /v1/admin/product-stages
 *        PUT /v1/admin/product-stages/reorder   ← must be before apiResource in api.php
 */

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, put, del } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
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

// ── Types ─────────────────────────────────────────────────────────────────────

interface ProductionStage {
    id: number;
    name: string;
    description: string | null;
    color: string | null;
    order: number;
}

// ── Colour palette ────────────────────────────────────────────────────────────

const COLOURS = [
    { label: "Slate",   value: "#64748b" },
    { label: "Blue",    value: "#3b82f6" },
    { label: "Indigo",  value: "#6366f1" },
    { label: "Purple",  value: "#a855f7" },
    { label: "Pink",    value: "#ec4899" },
    { label: "Orange",  value: "#f97316" },
    { label: "Amber",   value: "#f59e0b" },
    { label: "Green",   value: "#22c55e" },
    { label: "Teal",    value: "#14b8a6" },
    { label: "Red",     value: "#ef4444" },
];

// ── Drag handle ───────────────────────────────────────────────────────────────

function DragHandle(props: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            {...props}
            title="Drag to reorder"
            className="w-7 h-7 flex items-center justify-center rounded text-surface-300 hover:text-surface-500 hover:bg-surface-100 cursor-grab active:cursor-grabbing transition-colors shrink-0 touch-none"
        >
            <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 16 16">
                <circle cx="5.5" cy="4"  r="1.2" />
                <circle cx="5.5" cy="8"  r="1.2" />
                <circle cx="5.5" cy="12" r="1.2" />
                <circle cx="10.5" cy="4"  r="1.2" />
                <circle cx="10.5" cy="8"  r="1.2" />
                <circle cx="10.5" cy="12" r="1.2" />
            </svg>
        </div>
    );
}

// ── Colour picker ─────────────────────────────────────────────────────────────

function ColourPicker({ value, onChange }: { value: string; onChange: (v: string) => void }) {
    return (
        <div className="flex gap-1.5 flex-wrap mt-1">
            {COLOURS.map(c => (
                <button
                    key={c.value}
                    type="button"
                    title={c.label}
                    onClick={() => onChange(c.value)}
                    className={clsx(
                        "w-6 h-6 rounded-full border-2 transition-all",
                        value === c.value ? "border-surface-900 scale-110" : "border-transparent hover:scale-105",
                    )}
                    style={{ backgroundColor: c.value }}
                />
            ))}
        </div>
    );
}

// ── Sortable stage row ────────────────────────────────────────────────────────

function SortableStageRow({
    stage,
    index,
    onUpdated,
    onDeleted,
    isDragOverlay = false,
}: {
    stage: ProductionStage;
    index: number;
    onUpdated: () => void;
    onDeleted: () => void;
    isDragOverlay?: boolean;
}) {
    const toast = useToastStore();
    const [editing, setEditing]         = useState(false);
    const [name, setName]               = useState(stage.name);
    const [description, setDescription] = useState(stage.description ?? "");
    const [color, setColor]             = useState(stage.color ?? COLOURS[0].value);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: stage.id });

    const style: React.CSSProperties = {
        transform: CSS.Transform.toString(transform),
        transition: isDragOverlay ? undefined : transition,
    };

    const updateMut = useMutation({
        mutationFn: () => put(`/v1/admin/product-stages/${stage.id}`, { name, description: description || null, color }),
        onSuccess: () => { toast.success("Stage updated"); setEditing(false); onUpdated(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const deleteMut = useMutation({
        mutationFn: () => del(`/v1/admin/product-stages/${stage.id}`),
        onSuccess: () => { toast.success("Stage removed"); onDeleted(); },
        onError: (e: ApiError) => toast.error(e.message ?? "Cannot delete — stage may be in use."),
    });

    if (editing) {
        return (
            <div ref={setNodeRef} style={style} className="px-5 py-4 bg-brand-50/40 border-b border-surface-100">
                <div className="space-y-3">
                    <div className="flex gap-3">
                        <div className="flex-1">
                            <label className="label">Stage name <span className="text-danger">*</span></label>
                            <input
                                className="input"
                                value={name}
                                onChange={e => setName(e.target.value)}
                                autoFocus
                                onKeyDown={e => e.key === "Enter" && name.trim() && updateMut.mutate()}
                            />
                        </div>
                        <div>
                            <label className="label">Colour</label>
                            <ColourPicker value={color} onChange={setColor} />
                        </div>
                    </div>
                    <div>
                        <label className="label">Description <span className="text-surface-400 font-normal">(optional)</span></label>
                        <input
                            className="input"
                            value={description}
                            onChange={e => setDescription(e.target.value)}
                            placeholder="What happens in this stage?"
                        />
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => {
                                setName(stage.name);
                                setDescription(stage.description ?? "");
                                setColor(stage.color ?? COLOURS[0].value);
                                setEditing(false);
                            }}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => updateMut.mutate()}
                            disabled={!name.trim() || updateMut.isPending}
                            className="btn-primary btn-sm"
                        >
                            {updateMut.isPending ? "Saving…" : "Save"}
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <>
            <div
                ref={setNodeRef}
                style={style}
                className={clsx(
                    "flex items-center gap-3 px-5 py-3.5 border-b border-surface-50 last:border-0 transition-colors group",
                    isDragging && !isDragOverlay ? "opacity-30 bg-surface-50" : "hover:bg-surface-50/50",
                    isDragOverlay && "shadow-xl bg-white border border-brand-200 rounded-xl ring-2 ring-brand-300",
                )}
            >
                {/* Drag handle */}
                <DragHandle {...attributes} {...listeners} />

                {/* Colour dot + order number */}
                <div className="flex items-center gap-2 shrink-0">
                    <div className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: stage.color ?? "#64748b" }} />
                    <span className="text-2xs font-mono text-surface-300 w-4 text-center">{index + 1}</span>
                </div>

                {/* Name + description */}
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-surface-900">{stage.name}</p>
                    {stage.description && (
                        <p className="text-2xs text-surface-400 mt-0.5 truncate">{stage.description}</p>
                    )}
                </div>

                {/* Actions */}
                <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                    <button
                        onClick={() => setEditing(true)}
                        className="btn-ghost btn-icon btn-sm text-surface-400 hover:text-brand-600"
                        title="Edit"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                        </svg>
                    </button>
                    <button
                        onClick={() => setConfirmDelete(true)}
                        disabled={deleteMut.isPending}
                        className="btn-ghost btn-icon btn-sm text-surface-400 hover:text-danger hover:bg-danger-light"
                        title="Delete"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                        </svg>
                    </button>
                </div>
            </div>

            {/* Delete confirmation modal */}
            {confirmDelete && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onMouseDown={e => { if (e.target === e.currentTarget) setConfirmDelete(false); }}>
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col gap-4">
                        <div className="flex items-start gap-3">
                            <div className="w-10 h-10 rounded-full bg-danger/10 flex items-center justify-center shrink-0">
                                <svg className="w-5 h-5 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-surface-900">Delete stage?</h3>
                                <p className="text-xs text-surface-500 mt-1">
                                    <span className="font-semibold text-surface-700">"{stage.name}"</span> will be permanently removed.
                                    This cannot be undone. Stages in use by production orders cannot be deleted.
                                </p>
                            </div>
                        </div>
                        <div className="flex gap-2 pt-1">
                            <button onClick={() => setConfirmDelete(false)} className="btn-secondary flex-1 text-sm">
                                Cancel
                            </button>
                            <button
                                onClick={() => { setConfirmDelete(false); deleteMut.mutate(); }}
                                disabled={deleteMut.isPending}
                                className="flex-1 text-sm py-2 rounded-xl bg-danger text-white font-semibold hover:bg-danger/90 disabled:opacity-50 transition-colors"
                            >
                                {deleteMut.isPending ? "Deleting…" : "Delete stage"}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// ── Add stage form ────────────────────────────────────────────────────────────

function AddStageForm({ onSaved }: { onSaved: () => void }) {
    const toast = useToastStore();
    const [name, setName]               = useState("");
    const [description, setDescription] = useState("");
    const [color, setColor]             = useState(COLOURS[0].value);
    const [open, setOpen]               = useState(false);

    const mut = useMutation({
        mutationFn: () => post("/v1/admin/product-stages", { name, description: description || null, color }),
        onSuccess: () => {
            toast.success("Stage added");
            setName(""); setDescription(""); setColor(COLOURS[0].value); setOpen(false);
            onSaved();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (!open) {
        return (
            <div className="px-5 py-3 border-t border-surface-100">
                <button onClick={() => setOpen(true)} className="btn-secondary btn-sm gap-1.5">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Add Stage
                </button>
            </div>
        );
    }

    return (
        <div className="px-5 py-4 border-t border-surface-100 bg-surface-50/50">
            <p className="text-xs font-semibold text-surface-600 mb-3">New Stage</p>
            <div className="space-y-3">
                <div className="flex gap-3">
                    <div className="flex-1">
                        <label className="label">Stage name <span className="text-danger">*</span></label>
                        <input
                            className="input"
                            placeholder="e.g. Embroidery"
                            value={name}
                            onChange={e => setName(e.target.value)}
                            autoFocus
                            onKeyDown={e => e.key === "Enter" && name.trim() && mut.mutate()}
                        />
                    </div>
                    <div>
                        <label className="label">Colour</label>
                        <ColourPicker value={color} onChange={setColor} />
                    </div>
                </div>
                <div>
                    <label className="label">Description <span className="text-surface-400 font-normal">(optional)</span></label>
                    <input
                        className="input"
                        placeholder="What happens in this stage?"
                        value={description}
                        onChange={e => setDescription(e.target.value)}
                    />
                </div>
                <div className="flex gap-2">
                    <button onClick={() => { setOpen(false); setName(""); setDescription(""); }} className="btn-secondary btn-sm">Cancel</button>
                    <button
                        onClick={() => mut.mutate()}
                        disabled={!name.trim() || mut.isPending}
                        className="btn-primary btn-sm"
                    >
                        {mut.isPending ? "Adding…" : "Add Stage"}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function ProductionStagesPage() {
    const qc    = useQueryClient();
    const toast = useToastStore();

    // Local optimistic order — updated immediately on drag end, then confirmed
    // by the server response via query invalidation.
    const [localStages, setLocalStages] = useState<ProductionStage[] | null>(null);
    const [activeStage, setActiveStage] = useState<ProductionStage | null>(null);

    const { data, isLoading } = useQuery<ProductionStage[]>({
        queryKey: ["production-stages"],
        queryFn: () => get<ProductionStage[]>("/v1/admin/product-stages"),
        // Sync server data into local state only when we're not mid-drag
        select: (d) => [...d].sort((a, b) => a.order - b.order),
    });

    // Derive the display list: use local (optimistic) state while a reorder
    // mutation is pending, otherwise fall back to server data.
    const stages = localStages ?? data ?? [];

    const reorderMut = useMutation({
        mutationFn: (ids: number[]) => put("/v1/admin/product-stages/reorder", { stages: ids }),
        onSuccess: () => {
            setLocalStages(null); // clear optimistic state, let server data take over
            qc.invalidateQueries({ queryKey: ["production-stages"] });
        },
        onError: (e: ApiError) => {
            toast.error(e.message ?? "Reorder failed");
            setLocalStages(null); // roll back to server order
            qc.invalidateQueries({ queryKey: ["production-stages"] });
        },
    });

    const refresh = () => qc.invalidateQueries({ queryKey: ["production-stages"] });

    // ── dnd-kit sensors ──────────────────────────────────────────────────────

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const handleDragStart = (event: DragStartEvent) => {
        const dragged = stages.find(s => s.id === event.active.id);
        setActiveStage(dragged ?? null);
    };

    const handleDragEnd = (event: DragEndEvent) => {
        setActiveStage(null);
        const { active, over } = event;
        if (!over || active.id === over.id) return;

        const oldIdx = stages.findIndex(s => s.id === active.id);
        const newIdx = stages.findIndex(s => s.id === over.id);
        if (oldIdx === -1 || newIdx === -1) return;

        const reordered = arrayMove(stages, oldIdx, newIdx);
        setLocalStages(reordered); // optimistic update
        reorderMut.mutate(reordered.map(s => s.id));
    };

    const DEFAULT_STAGES = ["Cutting", "Stitching", "Finishing", "Quality Check"];

    const seedMut = useMutation({
        mutationFn: async () => {
            for (const [i, name] of DEFAULT_STAGES.entries()) {
                await post("/v1/admin/product-stages", {
                    name,
                    description: null,
                    color: COLOURS[i % COLOURS.length].value,
                });
            }
        },
        onSuccess: () => { toast.success("Default stages added"); refresh(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Production Stages</h1>
                    <p className="page-subtitle">
                        Define the stages a production order passes through. Stages are applied in order
                        when an order is confirmed.
                    </p>
                </div>
            </div>

            {/* Info callout */}
            <div className="bg-brand-50 border border-brand-100 rounded-2xl px-5 py-4 space-y-1">
                <p className="text-sm font-semibold text-brand-800">How stages work</p>
                <p className="text-xs text-brand-600">
                    When a production order is confirmed, a task is automatically created for each active
                    stage in the order shown here. Tailors and production staff progress through these tasks
                    as work is completed. Drag the <span className="font-semibold">⠿ handle</span> to reorder
                    stages — the order here is the order they appear on every production order.
                </p>
            </div>

            {/* Stages list */}
            <div className="card overflow-hidden p-0">
                {/* Table header */}
                <div className="overflow-x-auto">
                    <div className="grid grid-cols-[auto_auto_1fr_auto] gap-4 items-center px-5 py-2.5 bg-surface-50 border-b border-surface-100 min-w-[400px]">
                        <div className="w-7" /> {/* drag handle */}
                        <div className="w-9" /> {/* colour dot + number */}
                        <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">Stage</p>
                        <div className="w-16" /> {/* actions */}
                    </div>

                    {isLoading ? (
                        <div className="flex items-center justify-center py-16"><Spinner /></div>
                    ) : stages.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-3">
                            <div className="w-12 h-12 bg-surface-100 rounded-2xl flex items-center justify-center">
                                <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z"/>
                                </svg>
                            </div>
                            <p className="text-sm font-medium text-surface-500">No stages configured</p>
                            <p className="text-xs text-surface-400 text-center max-w-xs">
                                Add stages manually or use the defaults to get started quickly.
                            </p>
                            <button
                                onClick={() => seedMut.mutate()}
                                disabled={seedMut.isPending}
                                className="btn-primary btn-sm mt-1 gap-1.5"
                            >
                                {seedMut.isPending ? "Adding…" : "Add default stages"}
                            </button>
                        </div>
                    ) : (
                        <>
                            <DndContext
                                sensors={sensors}
                                collisionDetection={closestCenter}
                                onDragStart={handleDragStart}
                                onDragEnd={handleDragEnd}
                            >
                                <SortableContext
                                    items={stages.map(s => s.id)}
                                    strategy={verticalListSortingStrategy}
                                >
                                    {stages.map((stage, i) => (
                                        <SortableStageRow
                                            key={stage.id}
                                            stage={stage}
                                            index={i}
                                            onUpdated={refresh}
                                            onDeleted={refresh}
                                        />
                                    ))}
                                </SortableContext>

                                {/* Ghost overlay shown while dragging */}
                                <DragOverlay>
                                    {activeStage && (
                                        <SortableStageRow
                                            stage={activeStage}
                                            index={stages.findIndex(s => s.id === activeStage.id)}
                                            onUpdated={() => {}}
                                            onDeleted={() => {}}
                                            isDragOverlay
                                        />
                                    )}
                                </DragOverlay>
                            </DndContext>

                            <AddStageForm onSaved={refresh} />
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}