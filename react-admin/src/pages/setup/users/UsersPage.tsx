import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { usersApi, rolesApi, outletsApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { DataTable, Pagination } from "@/components/ui/DataTable";
import {
    Field,
    useFieldAriaProps,
    Toggle,
    StatusBadge,
    ConfirmDialog,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import type { UserSetup, UserFormData } from "@/types/setup";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ── Schemas ────────────────────────────────────────────────────────────────────

const baseUserSchema = z.object({
    first_name: z.string().min(1, "First name is required"),
    last_name: z.string().min(1, "Last name is required"),
    email: z.string().email("Enter a valid email"),
    phone: z.string(),
    status: z.enum(["active", "inactive"]),
    role_ids: z.array(z.number()).min(1, "Assign at least one role"),
    outlet_id: z.number().nullable(),
    must_setup_2fa: z.boolean(),
});

const createSchema = baseUserSchema
    .extend({
        password: z.string().min(8, "Minimum 8 characters"),
        password_confirmation: z.string().min(1, "Please confirm the password"),
    })
    .refine((d) => d.password === d.password_confirmation, {
        message: "Passwords do not match",
        path: ["password_confirmation"],
    });

const editSchema = baseUserSchema; // No password fields in edit

const changePasswordSchema = z
    .object({
        password: z.string().min(8, "Minimum 8 characters"),
        password_confirmation: z.string().min(1, "Please confirm the password"),
    })
    .refine((d) => d.password === d.password_confirmation, {
        message: "Passwords do not match",
        path: ["password_confirmation"],
    });

type CreateFormValues = z.infer<typeof createSchema>;
type EditFormValues = z.infer<typeof editSchema>;
type ChangePasswordFormValues = z.infer<typeof changePasswordSchema>;

const CREATE_DEFAULTS: CreateFormValues = {
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    password: "",
    password_confirmation: "",
    status: "active",
    role_ids: [],
    outlet_id: null,
    must_setup_2fa: false,
};

const EDIT_DEFAULTS: EditFormValues = {
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    status: "active",
    role_ids: [],
    outlet_id: null,
    must_setup_2fa: false,
};

// ── RolePicker - defined outside component to prevent remount on every render ──

interface RolePickerProps {
    roles: Array<{
        id: number;
        user_type: string;
        display_name: string;
        name: string;
    }>;
    roleIds: number[];
    onToggle: (id: number) => void;
    error?: string;
}

function RolePicker({ roles, roleIds, onToggle, error }: RolePickerProps) {
    return (
        <div className="border-t border-surface-100 pt-4">
            <p className="label mb-2">
                Roles <span className="text-danger">*</span>
                {error && <span className="field-error ml-2">{error}</span>}
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {roles
                    .filter((r) => r.user_type !== "customer")
                    .map((role) => (
                        <label
                            key={role.id}
                            className={clsx(
                                "flex items-start gap-2.5 p-2.5 rounded-lg border cursor-pointer transition-colors",
                                roleIds.includes(role.id)
                                    ? "border-brand-300 bg-brand-50"
                                    : "border-surface-200 hover:border-surface-300",
                            )}
                        >
                            <input
                                type="checkbox"
                                checked={roleIds.includes(role.id)}
                                onChange={() => onToggle(role.id)}
                                className="accent-brand-500 mt-0.5 shrink-0"
                            />
                            <div>
                                <p className="text-sm font-medium text-surface-800">
                                    {role.display_name}
                                </p>
                                <p className="text-2xs text-surface-400 font-mono">
                                    {role.name}
                                </p>
                            </div>
                        </label>
                    ))}
            </div>
        </div>
    );
}

export default function UsersPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const table = useTableState({
        defaultSortBy: "created_at",
        defaultPerPage: 15,
    });

    // Modal states - each modal has its own open flag, completely separate
    const [createOpen, setCreateOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [pwOpen, setPwOpen] = useState(false);
    const [promoteOpen, setPromoteOpen] = useState(false);
    const [deleting, setDeleting] = useState<UserSetup | null>(null);
    const [editingUser, setEditingUser] = useState<UserSetup | null>(null);
    const [pwUser, setPwUser] = useState<UserSetup | null>(null);
    const [promoteUser, setPromoteUser] = useState<UserSetup | null>(null);

    // Data
    const { data, isLoading } = useQuery({
        queryKey: ["admin-users", table.state],
        queryFn: () => usersApi.list(table.toParams()),
    });
    const { data: rolesData } = useQuery({
        queryKey: ["roles"],
        queryFn: () => rolesApi.list(),
    });
    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => outletsApi.list(),
    });

    const roles = rolesData?.data ?? [];
    const outlets = outletsData?.data ?? [];
    const users = data?.data ?? [];
    const meta = data?.meta;

    // Three separate form instances - no shared state
    const createForm = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
        defaultValues: CREATE_DEFAULTS,
    });
    const editForm = useForm<EditFormValues>({
        resolver: zodResolver(editSchema),
        defaultValues: EDIT_DEFAULTS,
    });
    const pwForm = useForm<ChangePasswordFormValues>({
        resolver: zodResolver(changePasswordSchema),
        defaultValues: { password: "", password_confirmation: "" },
    });

    // Open handlers - each resets only its own form
    const openCreate = useCallback(() => {
        createForm.reset(CREATE_DEFAULTS);
        setCreateOpen(true);
    }, [createForm]);

    const openEdit = useCallback(
        (u: UserSetup) => {
            editForm.reset({
                first_name: u.first_name,
                last_name: u.last_name,
                email: u.email,
                phone: u.phone ?? "",
                status: (u.status as "active" | "inactive") ?? "active",
                role_ids: u.roles.map((r) => r.id),
                outlet_id: u.outlet?.id ?? null,
                must_setup_2fa: u.must_setup_2fa ?? false,
            });
            setEditingUser(u);
            setEditOpen(true);
        },
        [editForm],
    );

    const openChangePassword = useCallback(
        (u: UserSetup) => {
            pwForm.reset({ password: "", password_confirmation: "" });
            setPwUser(u);
            setPwOpen(true);
        },
        [pwForm],
    );

    // Mutations
    const createMutation = useMutation({
        mutationFn: (v: CreateFormValues) =>
            usersApi.create(v as unknown as UserFormData),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["admin-users"] });
            toast.success("User created.");
            setCreateOpen(false);
        },
        onError: (err: ApiError) => {
            if (err.errors?.email)
                createForm.setError("email", { message: err.errors.email[0] });
            else toast.error(err.message);
        },
    });

    const editMutation = useMutation({
        mutationFn: (v: EditFormValues) =>
            usersApi.update(
                editingUser!.id,
                v as unknown as Partial<UserFormData>,
            ),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["admin-users"] });
            toast.success("User updated.");
            setEditOpen(false);
        },
        onError: (err: ApiError) => {
            if (err.errors?.email)
                editForm.setError("email", { message: err.errors.email[0] });
            else toast.error(err.message);
        },
    });

    const changePasswordMutation = useMutation({
        mutationFn: (v: ChangePasswordFormValues) =>
            usersApi.update(pwUser!.id, {
                password: v.password,
                password_confirmation: v.password_confirmation,
            } as Partial<UserFormData>),
        onSuccess: () => {
            toast.success(
                `Password changed for ${pwUser?.name ?? pwUser?.email}.`,
            );
            setPwOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => usersApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["admin-users"] });
            toast.success("User removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const promoteMutation = useMutation({
        mutationFn: (v: { role_ids: number[]; outlet_id: number | null }) =>
            usersApi.promoteToStaff(promoteUser!.id, v),
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["admin-users"] });
            toast.success(res.message);
            setPromoteOpen(false);
            setPromoteUser(null);
        },
        onError: (err: ApiError) =>
            toast.error(err.message ?? "Failed to promote user."),
    });

    // Password reset email - sends Laravel password broker email
    const resetEmailMutation = useMutation({
        mutationFn: (id: number) => usersApi.resetPassword(id),
        onSuccess: (_, id) => {
            const u = users.find((u) => u.id === id);
            toast.success(
                `Password reset email sent to ${u?.email ?? "user"}.`,
            );
        },
        onError: (err: ApiError) =>
            toast.error(
                err.message ??
                    "Failed to send reset email. Check mail configuration in Settings.",
            ),
    });

    // Role toggles - one per form
    const toggleCreateRole = (id: number) => {
        const cur = createForm.getValues("role_ids");
        createForm.setValue(
            "role_ids",
            cur.includes(id) ? cur.filter((r) => r !== id) : [...cur, id],
        );
    };
    const toggleEditRole = (id: number) => {
        const cur = editForm.getValues("role_ids");
        editForm.setValue(
            "role_ids",
            cur.includes(id) ? cur.filter((r) => r !== id) : [...cur, id],
        );
    };

    // Full name helper
    const fullName = (u: UserSetup) =>
        u.name ||
        `${u.first_name ?? ""} ${u.last_name ?? ""}`.trim() ||
        u.email;

    return (
        <div className="space-y-6 animate-fade-in">
            {/* Header */}
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Users</h1>
                    <p className="page-subtitle">
                        Staff and system users. Assign roles and outlets before
                        going live.
                    </p>
                </div>
                <button
                    onClick={openCreate}
                    className="btn-primary shrink-0 self-start sm:self-auto"
                >
                    + Create User
                </button>
            </div>

            {/* Search - state lives in table hook, never touches form state */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <input
                    className="input w-full sm:max-w-xs"
                    placeholder="Search by name or email…"
                    value={table.state.search}
                    onChange={(e) => table.setSearch(e.target.value)}
                />
                <select
                    className="input w-full sm:w-36"
                    value={table.state.perPage}
                    onChange={(e) => table.setPerPage(Number(e.target.value))}
                >
                    {[15, 30, 50].map((n) => (
                        <option key={n} value={n}>
                            {n} per page
                        </option>
                    ))}
                </select>
            </div>

            {/* Table */}
            <div className="card">
                <DataTable
                    columns={[
                        {
                            key: "name",
                            label: "User",
                            render: (u) => {
                                const user = u as unknown as UserSetup;
                                return (
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-full bg-brand-500/10 flex items-center justify-center shrink-0">
                                            <span className="text-brand-600 text-xs font-semibold uppercase">
                                                {user.first_name?.[0]}
                                                {user.last_name?.[0]}
                                            </span>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-surface-900">
                                                {fullName(user)}
                                            </p>
                                            <p className="text-xs text-surface-400">
                                                {user.email}
                                            </p>
                                            {user.phone && (
                                                <p className="text-xs text-surface-400">
                                                    {user.phone}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                );
                            },
                        },
                        {
                            key: "roles",
                            label: "Roles",
                            render: (u) => (
                                <div className="flex gap-1 flex-wrap">
                                    {(u as unknown as UserSetup).roles
                                        .slice(0, 2)
                                        .map((r) => (
                                            <span
                                                key={r.id}
                                                className="badge badge-neutral text-2xs"
                                            >
                                                {r.display_name ?? r.name}
                                            </span>
                                        ))}
                                    {(u as unknown as UserSetup).roles.length >
                                        2 && (
                                        <span className="badge badge-neutral text-2xs">
                                            +
                                            {(u as unknown as UserSetup).roles
                                                .length - 2}
                                        </span>
                                    )}
                                </div>
                            ),
                        },
                        {
                            key: "outlet",
                            label: "Outlet",
                            render: (u) =>
                                (u as unknown as UserSetup).outlet ? (
                                    <span className="text-sm text-surface-600">
                                        {
                                            (u as unknown as UserSetup).outlet
                                                ?.name
                                        }
                                    </span>
                                ) : (
                                    <span className="text-xs text-surface-300">
                                        -
                                    </span>
                                ),
                        },
                        {
                            key: "status",
                            label: "Status",
                            render: (u) => (
                                <StatusBadge
                                    active={
                                        (u as unknown as UserSetup).status ===
                                        "active"
                                    }
                                />
                            ),
                        },
                        {
                            key: "last_login_at",
                            label: "Last Login",
                            render: (u) =>
                                (u as unknown as UserSetup).last_login_at ? (
                                    <span className="text-xs text-surface-500">
                                        {new Date(
                                            (u as unknown as UserSetup)
                                                .last_login_at!,
                                        ).toLocaleDateString()}
                                    </span>
                                ) : (
                                    <span className="text-xs text-surface-300">
                                        Never
                                    </span>
                                ),
                        },
                        {
                            key: "id",
                            label: "",
                            width: "140px",
                            render: (u) => {
                                const user = u as unknown as UserSetup;
                                return (
                                    <div className="flex items-center gap-1">
                                        <button
                                            title="Change password"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                openChangePassword(user);
                                            }}
                                            className="btn-ghost btn-sm"
                                        >
                                            <KeyIcon />
                                        </button>
                                        <button
                                            title="Edit user"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                openEdit(user);
                                            }}
                                            className="btn-ghost btn-sm"
                                            aria-label="Edit"
                                        >
                                            <EditIcon />
                                        </button>
                                        {user.user_type === "customer" && (
                                            <button
                                                title="Promote to staff"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setPromoteUser(user);
                                                    setPromoteOpen(true);
                                                }}
                                                className="btn-ghost btn-sm text-brand-600 hover:bg-brand-50"
                                                aria-label="Promote to staff"
                                            >
                                                <PromoteIcon />
                                            </button>
                                        )}
                                        <button
                                            title="Delete user"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setDeleting(user);
                                            }}
                                            className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                            aria-label="Delete"
                                        >
                                            <TrashIcon />
                                        </button>
                                    </div>
                                );
                            },
                        },
                    ]}
                    data={users as unknown as Record<string, unknown>[]}
                    isLoading={isLoading}
                    sortBy={table.state.sortBy}
                    sortDir={table.state.sortDir}
                    onSort={table.setSort}
                    emptyMessage="No users found."
                />
                {meta && (
                    <Pagination
                        page={meta.current_page}
                        lastPage={meta.last_page}
                        total={meta.total}
                        from={meta.from}
                        to={meta.to}
                        isLoading={isLoading}
                        onPage={table.setPage}
                    />
                )}
            </div>

            {/* CREATE modal */}
            <Modal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                title="Create User"
                size="lg"
                footer={
                    <>
                        <button
                            onClick={() => setCreateOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={createForm.handleSubmit((v) =>
                                createMutation.mutate(v),
                            )}
                            disabled={createMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {createMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Create User
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="First Name"
                            error={
                                createForm.formState.errors.first_name?.message
                            }
                            required
                        >
                            <FieldInput
                                className={`input ${createForm.formState.errors.first_name ? "input-error" : ""}`}
                                {...createForm.register("first_name")}
                            />
                        </Field>
                        <Field
                            label="Last Name"
                            error={
                                createForm.formState.errors.last_name?.message
                            }
                            required
                        >
                            <FieldInput
                                className={`input ${createForm.formState.errors.last_name ? "input-error" : ""}`}
                                {...createForm.register("last_name")}
                            />
                        </Field>
                        <Field
                            label="Email"
                            error={createForm.formState.errors.email?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${createForm.formState.errors.email ? "input-error" : ""}`}
                                type="email"
                                {...createForm.register("email")}
                            />
                        </Field>
                        <Field label="Phone">
                            <FieldInput
                                className="input"
                                {...createForm.register("phone")}
                                placeholder="+254 700 000 000"
                            />
                        </Field>
                        <Field
                            label="Password"
                            error={
                                createForm.formState.errors.password?.message
                            }
                            required
                        >
                            <FieldInput
                                className={`input ${createForm.formState.errors.password ? "input-error" : ""}`}
                                type="password"
                                autoComplete="new-password"
                                {...createForm.register("password")}
                            />
                        </Field>
                        <Field
                            label="Confirm Password"
                            error={
                                createForm.formState.errors
                                    .password_confirmation?.message
                            }
                            required
                        >
                            <FieldInput
                                className={`input ${createForm.formState.errors.password_confirmation ? "input-error" : ""}`}
                                type="password"
                                autoComplete="new-password"
                                {...createForm.register(
                                    "password_confirmation",
                                )}
                            />
                        </Field>
                        <Field label="Status">
                            <FieldSelect
                                className="input"
                                {...createForm.register("status")}
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </FieldSelect>
                        </Field>
                        <Field
                            label="Assigned Outlet"
                            hint="Required for POS clerks and outlet managers"
                        >
                            <FieldSelect
                                className="input"
                                value={createForm.watch("outlet_id") ?? ""}
                                onChange={(e) =>
                                    createForm.setValue(
                                        "outlet_id",
                                        e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    )
                                }
                            >
                                <option value="">- No outlet -</option>
                                {outlets.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </FieldSelect>
                        </Field>
                    </div>
                    <RolePicker
                        roles={roles}
                        roleIds={createForm.watch("role_ids")}
                        onToggle={toggleCreateRole}
                        error={
                            createForm.formState.errors.role_ids
                                ?.message as string
                        }
                    />
                    <div className="border-t border-surface-100 pt-3">
                        <Toggle
                            checked={createForm.watch("must_setup_2fa")}
                            onChange={(v) =>
                                createForm.setValue("must_setup_2fa", v)
                            }
                            label="Require 2FA setup on next login"
                            description="User will be prompted to configure two-factor authentication."
                        />
                    </div>
                </div>
            </Modal>

            {/* EDIT modal - no password fields */}
            <Modal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                title={
                    editingUser
                        ? `Edit - ${fullName(editingUser)}`
                        : "Edit User"
                }
                size="lg"
                footer={
                    <>
                        <button
                            onClick={() => setEditOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={editForm.handleSubmit((v) =>
                                editMutation.mutate(v),
                            )}
                            disabled={editMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {editMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Save Changes
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="First Name"
                            error={
                                editForm.formState.errors.first_name?.message
                            }
                            required
                        >
                            <FieldInput
                                className={`input ${editForm.formState.errors.first_name ? "input-error" : ""}`}
                                {...editForm.register("first_name")}
                            />
                        </Field>
                        <Field
                            label="Last Name"
                            error={editForm.formState.errors.last_name?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${editForm.formState.errors.last_name ? "input-error" : ""}`}
                                {...editForm.register("last_name")}
                            />
                        </Field>
                        <Field
                            label="Email"
                            error={editForm.formState.errors.email?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${editForm.formState.errors.email ? "input-error" : ""}`}
                                type="email"
                                {...editForm.register("email")}
                            />
                        </Field>
                        <Field label="Phone">
                            <FieldInput
                                className="input"
                                {...editForm.register("phone")}
                                placeholder="+254 700 000 000"
                            />
                        </Field>
                        <Field label="Status">
                            <FieldSelect
                                className="input"
                                {...editForm.register("status")}
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </FieldSelect>
                        </Field>
                        <Field label="Assigned Outlet">
                            <FieldSelect
                                className="input"
                                value={editForm.watch("outlet_id") ?? ""}
                                onChange={(e) =>
                                    editForm.setValue(
                                        "outlet_id",
                                        e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    )
                                }
                            >
                                <option value="">- No outlet -</option>
                                {outlets.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </FieldSelect>
                        </Field>
                    </div>
                    <RolePicker
                        roles={roles}
                        roleIds={editForm.watch("role_ids")}
                        onToggle={toggleEditRole}
                        error={
                            editForm.formState.errors.role_ids
                                ?.message as string
                        }
                    />
                    <div className="border-t border-surface-100 pt-3">
                        <Toggle
                            checked={editForm.watch("must_setup_2fa")}
                            onChange={(v) =>
                                editForm.setValue("must_setup_2fa", v)
                            }
                            label="Require 2FA setup on next login"
                            description="User will be prompted to configure two-factor authentication."
                        />
                    </div>
                </div>
            </Modal>

            {/* CHANGE PASSWORD modal */}
            <Modal
                open={pwOpen}
                onClose={() => setPwOpen(false)}
                title={`Change Password${pwUser ? ` - ${fullName(pwUser)}` : ""}`}
                size="sm"
                footer={
                    <>
                        <button
                            onClick={() => setPwOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={pwForm.handleSubmit((v) =>
                                changePasswordMutation.mutate(v),
                            )}
                            disabled={changePasswordMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {changePasswordMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Change Password
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <p className="text-xs text-surface-500 bg-surface-50 rounded-lg px-3 py-2.5">
                        Setting a new password will invalidate all of this
                        user's active sessions.
                    </p>
                    <Field
                        label="New Password"
                        error={pwForm.formState.errors.password?.message}
                        required
                    >
                        <FieldInput
                            className={`input ${pwForm.formState.errors.password ? "input-error" : ""}`}
                            type="password"
                            autoComplete="new-password"
                            {...pwForm.register("password")}
                        />
                    </Field>
                    <Field
                        label="Confirm Password"
                        error={
                            pwForm.formState.errors.password_confirmation
                                ?.message
                        }
                        required
                    >
                        <FieldInput
                            className={`input ${pwForm.formState.errors.password_confirmation ? "input-error" : ""}`}
                            type="password"
                            autoComplete="new-password"
                            {...pwForm.register("password_confirmation")}
                        />
                    </Field>
                    <div className="border-t border-surface-100 pt-3">
                        <p className="text-xs font-medium text-surface-500 mb-2">
                            Or send a reset link via email:
                        </p>
                        <button
                            type="button"
                            onClick={() => {
                                resetEmailMutation.mutate(pwUser!.id);
                                setPwOpen(false);
                            }}
                            disabled={resetEmailMutation.isPending}
                            className="btn-secondary btn-sm w-full gap-1.5"
                        >
                            <MailIcon />
                            Send reset email to {pwUser?.email}
                        </button>
                    </div>
                </div>
            </Modal>

            {/* PROMOTE TO STAFF modal */}
            <PromoteToStaffModal
                open={promoteOpen}
                user={promoteUser}
                roles={roles}
                outlets={outlets}
                isPending={promoteMutation.isPending}
                onClose={() => {
                    setPromoteOpen(false);
                    setPromoteUser(null);
                }}
                onConfirm={(v) => promoteMutation.mutate(v)}
            />

            {/* DELETE confirm */}
            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Delete User"
                message={`Delete ${deleting ? fullName(deleting) : ""}? Their historical data is retained but they will lose access immediately.`}
                confirmLabel="Delete User"
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
const KeyIcon = () => (
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
            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
        />
    </svg>
);
const MailIcon = () => (
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
            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
        />
    </svg>
);
const PromoteIcon = () => (
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
            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
        />
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M19 3l-3 3m0 0l3 3m-3-3h6"
        />
    </svg>
);

// ── PromoteToStaffModal ────────────────────────────────────────────────────────

interface PromoteToStaffModalProps {
    open: boolean;
    user: UserSetup | null;
    roles: Array<{
        id: number;
        user_type: string;
        display_name: string;
        name: string;
    }>;
    outlets: Array<{ id: number; name: string }>;
    isPending: boolean;
    onClose: () => void;
    onConfirm: (v: { role_ids: number[]; outlet_id: number | null }) => void;
}

function PromoteToStaffModal({
    open,
    user,
    roles,
    outlets,
    isPending,
    onClose,
    onConfirm,
}: PromoteToStaffModalProps) {
    const [selectedRoles, setSelectedRoles] = useState<number[]>([]);
    const [selectedOutlet, setSelectedOutlet] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    // Reset selections whenever the modal opens for a new user
    useState(() => {
        if (open) {
            setSelectedRoles([]);
            setSelectedOutlet(null);
            setError(null);
        }
    });

    const toggleRole = (id: number) =>
        setSelectedRoles((cur) =>
            cur.includes(id) ? cur.filter((r) => r !== id) : [...cur, id],
        );

    const handleConfirm = () => {
        if (selectedRoles.length === 0) {
            setError("Assign at least one staff role.");
            return;
        }
        setError(null);
        onConfirm({ role_ids: selectedRoles, outlet_id: selectedOutlet });
    };

    const staffRoles = roles.filter((r) => r.user_type !== "customer");

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Promote to Staff"
            size="md"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={handleConfirm}
                        disabled={isPending}
                        className="btn-primary btn-sm gap-1.5"
                    >
                        {isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Promote to Staff
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                {/* Warning banner */}
                <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3">
                    <svg
                        className="w-5 h-5 text-amber-500 shrink-0 mt-0.5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                        />
                    </svg>
                    <div className="text-sm text-amber-800 space-y-1">
                        <p className="font-medium">
                            {user
                                ? `${user.first_name} ${user.last_name}`
                                : "This user"}{" "}
                            will be switched from Customer to Staff.
                        </p>
                        <ul className="text-xs text-amber-700 list-disc list-inside space-y-0.5">
                            <li>
                                Their customer profile is detached but order
                                history is preserved.
                            </li>
                            <li>
                                All active sessions will be revoked — they must
                                log in again.
                            </li>
                            <li>
                                They will gain admin panel access based on the
                                roles you assign.
                            </li>
                        </ul>
                    </div>
                </div>

                {/* Role picker */}
                <div>
                    <p className="label mb-2">
                        Assign Staff Role(s){" "}
                        <span className="text-danger">*</span>
                        {error && (
                            <span className="field-error ml-2">{error}</span>
                        )}
                    </p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {staffRoles.map((role) => (
                            <label
                                key={role.id}
                                className={clsx(
                                    "flex items-start gap-2.5 p-2.5 rounded-lg border cursor-pointer transition-colors",
                                    selectedRoles.includes(role.id)
                                        ? "border-brand-300 bg-brand-50"
                                        : "border-surface-200 hover:border-surface-300",
                                )}
                            >
                                <input
                                    type="checkbox"
                                    checked={selectedRoles.includes(role.id)}
                                    onChange={() => toggleRole(role.id)}
                                    className="accent-brand-500 mt-0.5 shrink-0"
                                />
                                <div>
                                    <p className="text-sm font-medium text-surface-800">
                                        {role.display_name}
                                    </p>
                                    <p className="text-2xs text-surface-400 font-mono">
                                        {role.name}
                                    </p>
                                </div>
                            </label>
                        ))}
                    </div>
                </div>

                {/* Outlet picker */}
                <Field
                    label="Assign to Outlet"
                    hint="Required for POS clerks and outlet managers"
                >
                    <FieldSelect
                        className="input"
                        value={selectedOutlet ?? ""}
                        onChange={(e) =>
                            setSelectedOutlet(
                                e.target.value ? Number(e.target.value) : null,
                            )
                        }
                    >
                        <option value="">— No outlet —</option>
                        {outlets.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </FieldSelect>
                </Field>
            </div>
        </Modal>
    );
}
