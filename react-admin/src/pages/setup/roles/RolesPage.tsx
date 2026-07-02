import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { rolesApi, permissionsApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { DataTable } from "@/components/ui/DataTable";
import {
    Section,
    Field,
    useFieldAriaProps,
    Toggle,
    StatusBadge,
    ConfirmDialog,
    EmptyState,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import type {
    RoleSetup,
    PermissionGroup,
    PermissionSetup,
} from "@/types/setup";
import type { ApiError } from "@/types";

const roleSchema = z.object({
    name: z
        .string()
        .min(1)
        .regex(/^[a-z_]+$/, "Use lowercase letters and underscores only"),
    display_name: z.string().min(1, "Display name is required"),
    description: z.string(),
    user_type: z.enum(["system", "staff", "customer"]),
    is_active: z.boolean(),
    permissions: z.array(z.number()),
});

type RoleFormValues = z.infer<typeof roleSchema>;

const ROLE_DEFAULTS: RoleFormValues = {
    name: "",
    display_name: "",
    description: "",
    user_type: "staff",
    is_active: true,
    permissions: [],
};

const SYSTEM_ROLES = [
    {
        name: "super_admin",
        display: "Super Admin",
        desc: "Full system access. Cannot be restricted.",
    },
    {
        name: "admin",
        display: "Admin",
        desc: "Manage products, orders, inventory, reports.",
    },
    {
        name: "outlet_manager",
        display: "Outlet Manager",
        desc: "Manage own outlet, POS, inventory.",
    },
    {
        name: "pos_clerk",
        display: "POS Clerk",
        desc: "POS sales only at assigned outlet.",
    },
    {
        name: "tailor",
        display: "Tailor",
        desc: "Production tasks assigned to them.",
    },
    {
        name: "procurement_officer",
        display: "Procurement Officer",
        desc: "Purchase orders, suppliers, GRN.",
    },
];

export default function RolesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [permModalOpen, setPermModalOpen] = useState(false);
    const [editing, setEditing] = useState<RoleSetup | null>(null);
    const [managingPerms, setManagingPerms] = useState<RoleSetup | null>(null);
    const [deleting, setDeleting] = useState<RoleSetup | null>(null);
    const [selectedPerms, setSelectedPerms] = useState<number[]>([]);
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(
        new Set(),
    );

    const { data: rolesData, isLoading } = useQuery({
        queryKey: ["roles"],
        queryFn: () => rolesApi.list(),
    });

    const { data: permsData } = useQuery({
        queryKey: ["permissions"],
        queryFn: () => permissionsApi.listGrouped(),
    });

    const roles = rolesData?.data ?? [];
    const permGroups = permsData?.data ?? [];

    const form = useForm<RoleFormValues>({
        resolver: zodResolver(roleSchema),
        defaultValues: ROLE_DEFAULTS,
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    const openCreate = () => {
        reset(ROLE_DEFAULTS);
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = (r: RoleSetup) => {
        reset({
            name: r.name,
            display_name: r.display_name,
            description: r.description ?? "",
            user_type: r.user_type,
            is_active: r.is_active,
            permissions: r.permissions.map((p) => p.id),
        });
        setEditing(r);
        setModalOpen(true);
    };

    const openPerms = (r: RoleSetup) => {
        setManagingPerms(r);
        setSelectedPerms(r.permissions.map((p) => p.id));
        setExpandedGroups(new Set(permGroups.map((g) => g.group)));
        setPermModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (v: RoleFormValues) =>
            editing ? rolesApi.update(editing.id, v) : rolesApi.create(v),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["roles"] });
            toast.success(editing ? "Role updated." : "Role created.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const syncPermsMutation = useMutation({
        mutationFn: () =>
            rolesApi.syncPermissions(managingPerms!.id, selectedPerms),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["roles"] });
            toast.success("Permissions saved.");
            setPermModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => rolesApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["roles"] });
            toast.success("Role removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const togglePerm = (id: number) => {
        setSelectedPerms((prev) =>
            prev.includes(id) ? prev.filter((p) => p !== id) : [...prev, id],
        );
    };

    const toggleGroup = (group: PermissionGroup) => {
        const groupIds = group.permissions.map((p) => p.id);
        const allSelected = groupIds.every((id) => selectedPerms.includes(id));
        if (allSelected) {
            setSelectedPerms((prev) =>
                prev.filter((id) => !groupIds.includes(id)),
            );
        } else {
            setSelectedPerms((prev) => [...new Set([...prev, ...groupIds])]);
        }
    };

    const toggleGroupExpand = (group: string) => {
        setExpandedGroups((prev) => {
            const next = new Set(prev);
            next.has(group) ? next.delete(group) : next.add(group);
            return next;
        });
    };

    const TYPE_COLORS: Record<string, string> = {
        system: "badge-danger",
        staff: "badge-info",
        customer: "badge-neutral",
    };

    return (
        <div className="space-y-6 animate-fade-in max-w-5xl">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Roles & Permissions</h1>
                    <p className="page-subtitle">
                        Create system roles per the SRS. Super Admin has full
                        access. Tailor, POS Clerk and Outlet Manager have
                        restricted access.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                    + Create Role
                </button>
            </div>

            {/* SRS-mandated roles reference */}
            <div className="card p-4">
                <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                    Required roles per SRS
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                    {SYSTEM_ROLES.map((r) => {
                        const exists = roles.some(
                            (role) => role.name === r.name,
                        );
                        return (
                            <div
                                key={r.name}
                                className={`rounded-lg px-3 py-2.5 border ${exists ? "border-success/30 bg-success-light/30" : "border-surface-200 bg-surface-50"}`}
                            >
                                <div className="flex items-center gap-2">
                                    <span
                                        className={`w-1.5 h-1.5 rounded-full shrink-0 ${exists ? "bg-success" : "bg-surface-300"}`}
                                    />
                                    <span className="text-xs font-semibold text-surface-800">
                                        {r.display}
                                    </span>
                                </div>
                                <p className="text-2xs text-surface-500 mt-0.5 pl-3.5">
                                    {r.desc}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </div>

            <Section title="Configured Roles">
                {isLoading ? (
                    <div className="flex justify-center py-10">
                        <Spinner size="lg" />
                    </div>
                ) : roles.length === 0 ? (
                    <EmptyState
                        title="No roles configured"
                        description="Create the system roles listed above to get started."
                        action={
                            <button
                                onClick={openCreate}
                                className="btn-primary btn-sm"
                            >
                                Create Role
                            </button>
                        }
                    />
                ) : (
                    <div className="table-wrapper">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Type</th>
                                    <th>Permissions</th>
                                    <th>Users</th>
                                    <th>Status</th>
                                    <th style={{ width: 140 }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roles.map((role) => (
                                    <tr key={role.id}>
                                        <td>
                                            <div>
                                                <p className="font-medium text-surface-900 text-sm">
                                                    {role.display_name}
                                                </p>
                                                <p className="text-xs text-surface-400 font-mono">
                                                    {role.name}
                                                </p>
                                            </div>
                                        </td>
                                        <td>
                                            <span
                                                className={`badge ${TYPE_COLORS[role.user_type] ?? "badge-neutral"} text-2xs`}
                                            >
                                                {role.user_type}
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                onClick={() => openPerms(role)}
                                                className="text-xs text-brand-600 hover:text-brand-700 font-medium"
                                            >
                                                {role.permissions.length}{" "}
                                                permission
                                                {role.permissions.length !== 1
                                                    ? "s"
                                                    : ""}
                                            </button>
                                        </td>
                                        <td>
                                            <span className="text-sm text-surface-600">
                                                {role.users_count}
                                            </span>
                                        </td>
                                        <td>
                                            <StatusBadge
                                                active={role.is_active}
                                            />
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-1">
                                                <button
                                                    onClick={() =>
                                                        openPerms(role)
                                                    }
                                                    className="btn-ghost btn-sm text-xs"
                                                >
                                                    Permissions
                                                </button>
                                                <button
                                                    onClick={() =>
                                                        openEdit(role)
                                                    }
                                                    className="btn-ghost btn-sm"
                                                    aria-label="Edit"
                                                >
                                                    <EditIcon />
                                                </button>
                                                {!role.is_system && (
                                                    <button
                                                        onClick={() =>
                                                            setDeleting(role)
                                                        }
                                                        className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                                        aria-label="Delete"
                                                    >
                                                        <TrashIcon />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>

            {/* ── Role modal ─────────────────────────────────────────────────────── */}
            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.display_name}` : "Create Role"}
                size="md"
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
                            {saveMutation.isPending ? (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            ) : null}
                            {editing ? "Save" : "Create Role"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    {!editing && (
                        <div>
                            <p className="label mb-2">Preset from SRS</p>
                            <div className="flex gap-2 flex-wrap">
                                {SYSTEM_ROLES.map((r) => (
                                    <button
                                        key={r.name}
                                        type="button"
                                        onClick={() =>
                                            reset({
                                                ...ROLE_DEFAULTS,
                                                name: r.name,
                                                display_name: r.display,
                                                description: r.desc,
                                            })
                                        }
                                        className="btn-secondary btn-sm text-xs"
                                    >
                                        {r.display}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="Name (machine)"
                            error={errors.name?.message}
                            required
                            hint="Lowercase, underscores only"
                        >
                            <FieldInput
                                className={`input font-mono text-sm ${errors.name ? "input-error" : ""}`}
                                {...register("name")}
                                placeholder="pos_clerk"
                                disabled={!!editing}
                            />
                        </Field>
                        <Field
                            label="Display name"
                            error={errors.display_name?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${errors.display_name ? "input-error" : ""}`}
                                {...register("display_name")}
                                placeholder="POS Clerk"
                            />
                        </Field>
                        <Field label="Description" className="col-span-2">
                            <FieldInput
                                className="input"
                                {...register("description")}
                                placeholder="What can this role do?"
                            />
                        </Field>
                        <Field label="User type">
                            <FieldSelect
                                className="input"
                                {...register("user_type")}
                            >
                                <option value="system">
                                    System (admin panel)
                                </option>
                                <option value="staff">
                                    Staff (admin panel)
                                </option>
                                <option value="customer">
                                    Customer (storefront)
                                </option>
                            </FieldSelect>
                        </Field>
                        <Field label="Status" className="flex items-end">
                            <Toggle
                                checked={watch("is_active")}
                                onChange={(v) => setValue("is_active", v)}
                                label="Active"
                            />
                        </Field>
                    </div>
                </div>
            </Modal>

            {/* ── Permissions matrix modal ─────────────────────────────────────── */}
            <Modal
                open={permModalOpen}
                onClose={() => setPermModalOpen(false)}
                title={`Permissions - ${managingPerms?.display_name}`}
                size="xl"
                footer={
                    <>
                        <div className="mr-auto text-xs text-surface-500">
                            {selectedPerms.length} permissions selected
                        </div>
                        <button
                            onClick={() => setPermModalOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => syncPermsMutation.mutate()}
                            disabled={syncPermsMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {syncPermsMutation.isPending ? (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            ) : null}
                            Save Permissions
                        </button>
                    </>
                }
            >
                <div className="space-y-2 max-h-[55vh] overflow-y-auto pr-1">
                    {permGroups.length === 0 ? (
                        <p className="text-sm text-surface-400 py-6 text-center">
                            No permissions found. Run{" "}
                            <code className="text-xs bg-surface-100 px-1 rounded">
                                php artisan permission:sync
                            </code>{" "}
                            on the backend.
                        </p>
                    ) : (
                        permGroups.map((group) => {
                            const groupIds = group.permissions.map((p) => p.id);
                            const allSelected = groupIds.every((id) =>
                                selectedPerms.includes(id),
                            );
                            const someSelected = groupIds.some((id) =>
                                selectedPerms.includes(id),
                            );
                            const isExpanded = expandedGroups.has(group.group);

                            return (
                                <div
                                    key={group.group}
                                    className="border border-surface-100 rounded-xl overflow-hidden"
                                >
                                    <button
                                        type="button"
                                        onClick={() =>
                                            toggleGroupExpand(group.group)
                                        }
                                        className="w-full flex items-center justify-between px-4 py-3 bg-surface-50 hover:bg-surface-100 transition-colors"
                                    >
                                        <div className="flex items-center gap-3">
                                            <input
                                                type="checkbox"
                                                checked={allSelected}
                                                ref={(el) => {
                                                    if (el)
                                                        el.indeterminate =
                                                            someSelected &&
                                                            !allSelected;
                                                }}
                                                onChange={(e) => {
                                                    e.stopPropagation();
                                                    toggleGroup(group);
                                                }}
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                                className="accent-brand-500 w-4 h-4"
                                            />
                                            <span className="text-sm font-semibold text-surface-800">
                                                {group.group}
                                            </span>
                                            <span className="text-xs text-surface-400">
                                                {
                                                    selectedPerms.filter((id) =>
                                                        groupIds.includes(id),
                                                    ).length
                                                }
                                                /{group.permissions.length}
                                            </span>
                                        </div>
                                        <svg
                                            className={`w-4 h-4 text-surface-400 transition-transform ${isExpanded ? "" : "-rotate-90"}`}
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
                                    </button>
                                    {isExpanded && (
                                        <div className="px-4 py-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                            {group.permissions.map((perm) => (
                                                <label
                                                    key={perm.id}
                                                    className="flex items-start gap-2.5 cursor-pointer group"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedPerms.includes(
                                                            perm.id,
                                                        )}
                                                        onChange={() =>
                                                            togglePerm(perm.id)
                                                        }
                                                        className="accent-brand-500 w-4 h-4 mt-0.5 shrink-0"
                                                    />
                                                    <div>
                                                        <p className="text-sm text-surface-800 group-hover:text-surface-900">
                                                            {perm.display_name}
                                                        </p>
                                                        <p className="text-2xs text-surface-400 font-mono">
                                                            {perm.name}
                                                        </p>
                                                    </div>
                                                </label>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })
                    )}
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Delete Role"
                message={`Delete ${deleting?.display_name}? Users with this role will lose its permissions. This cannot be undone.`}
                confirmLabel="Delete Role"
            />
        </div>
    );
}

const EditIcon = () => (
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
            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
        />
    </svg>
);
const TrashIcon = () => (
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
);