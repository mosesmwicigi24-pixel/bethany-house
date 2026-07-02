import { clsx } from "clsx";
import { useId, createContext, useContext } from "react";
import type { ReactNode } from "react";

// ─── Field context ─────────────────────────────────────────────────────────────
// Allows child inputs to read the generated id, error id, and error state
// without prop drilling.  Any <input>, <select>, or <textarea> placed inside
// a <Field> can call useFieldAriaProps() to get the right ARIA attributes.

interface FieldContextValue {
    /** The id to set on the input so the <label htmlFor> links correctly. */
    inputId: string
    /** The id of the error <p> element - pass to aria-describedby on the input. */
    errorId: string | undefined
    /** The id of the hint <p> element - pass to aria-describedby on the input. */
    hintId: string | undefined
    /** Whether the field currently has a validation error. */
    hasError: boolean
}

const FieldContext = createContext<FieldContextValue>({
    inputId:  "",
    errorId:  undefined,
    hintId:   undefined,
    hasError: false,
})

/**
 * Call inside any input/select/textarea that lives inside a <Field> to get
 * the ARIA attributes it should spread.
 *
 * @example
 *   const fieldProps = useFieldAriaProps()
 *   <input {...register("name")} {...fieldProps} className="input" />
 */
export function useFieldAriaProps() {
    const { inputId, errorId, hintId, hasError } = useContext(FieldContext)
    const describedBy = [errorId, hintId].filter(Boolean).join(" ") || undefined
    return {
        id:                inputId   || undefined,
        "aria-describedby": describedBy,
        "aria-invalid":    hasError  ? (true as const) : undefined,
    }
}

// ─── Field wrapper ─────────────────────────────────────────────────────────────

interface FieldProps {
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    children: ReactNode;
    className?: string;
}

export function Field({
    label,
    error,
    hint,
    required,
    children,
    className,
}: FieldProps) {
    // Generate stable, unique ids for this field instance
    const baseId  = useId()
    const inputId = `${baseId}-input`
    const errorId = error ? `${baseId}-error` : undefined
    const hintId  = hint  ? `${baseId}-hint`  : undefined

    return (
        <FieldContext.Provider value={{ inputId, errorId, hintId, hasError: !!error }}>
            <div className={clsx("space-y-1", className)}>
                {/* htmlFor links the label to the input via inputId */}
                <label htmlFor={inputId} className="label">
                    {label}
                    {required && (
                        <>
                            {/* Visual asterisk - hidden from screen readers */}
                            <span className="text-danger ml-0.5" aria-hidden="true">*</span>
                            {/* Screen-reader-only indication */}
                            <span className="sr-only">(required)</span>
                        </>
                    )}
                </label>
                {children}
                {hint && !error && (
                    <p id={hintId} className="text-2xs text-surface-400">{hint}</p>
                )}
                {error && (
                    <p
                        id={errorId}
                        className="field-error"
                        role="alert"
                        aria-live="polite"
                    >
                        {error}
                    </p>
                )}
            </div>
        </FieldContext.Provider>
    );
}

// ─── Section card ──────────────────────────────────────────────────────────────

interface SectionProps {
    title: string;
    description?: string;
    children: ReactNode;
    actions?: ReactNode;
}

export function Section({
    title,
    description,
    children,
    actions,
}: SectionProps) {
    return (
        <div className="card">
            <div className="card-header">
                <div>
                    <h3 className="font-semibold text-surface-900 text-sm">
                        {title}
                    </h3>
                    {description && (
                        <p className="text-xs text-surface-500 mt-0.5">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex items-center gap-2">{actions}</div>
                )}
            </div>
            <div className="card-body space-y-4">{children}</div>
        </div>
    );
}

// ─── Toggle ────────────────────────────────────────────────────────────────────

interface ToggleProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label?: string;
    description?: string;
    disabled?: boolean;
}

export function Toggle({
    checked,
    onChange,
    label,
    description,
    disabled,
}: ToggleProps) {
    return (
        <label
            className={clsx(
                "flex items-start gap-3 cursor-pointer",
                disabled && "opacity-50 cursor-not-allowed",
            )}
        >
            <div className="relative mt-0.5 shrink-0">
                <input
                    type="checkbox"
                    className="sr-only"
                    checked={checked}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <div
                    className={clsx(
                        "w-10 h-5 rounded-full transition-colors duration-200",
                        checked ? "bg-brand-500" : "bg-surface-300",
                    )}
                />
                <div
                    className={clsx(
                        "absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200",
                        checked ? "translate-x-5" : "translate-x-0",
                    )}
                />
            </div>
            {(label || description) && (
                <div>
                    {label && (
                        <p className="text-sm font-medium text-surface-800">
                            {label}
                        </p>
                    )}
                    {description && (
                        <p className="text-xs text-surface-500 mt-0.5">
                            {description}
                        </p>
                    )}
                </div>
            )}
        </label>
    );
}

// ─── Select ────────────────────────────────────────────────────────────────────

interface SelectOption {
    value: string | number;
    label: string;
}

interface SelectProps {
    options: SelectOption[];
    value: string | number;
    onChange: (value: string) => void;
    placeholder?: string;
    error?: boolean;
    disabled?: boolean;
    className?: string;
}

export function Select({
    options,
    value,
    onChange,
    placeholder,
    error,
    disabled,
    className,
}: SelectProps) {
    return (
        <select
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
            className={clsx(
                "input appearance-none bg-white",
                error && "input-error",
                className,
            )}
        >
            {placeholder && <option value="">{placeholder}</option>}
            {options.map((opt) => (
                <option key={opt.value} value={opt.value}>
                    {opt.label}
                </option>
            ))}
        </select>
    );
}

// ─── Accessible field input wrappers ──────────────────────────────────────────
// Use these inside <Field> instead of bare <input>, <select>, <textarea>.
// They automatically read the FieldContext to apply id, aria-describedby,
// and aria-invalid - so screen readers announce labels and errors correctly.
//
// Usage:
//   <Field label="Name" error={errors.name?.message} required>
//     <FieldInput {...register("name")} placeholder="Jane" />
//   </Field>
//
// For react-hook-form: spread register() first, then the component handles ARIA.
// For controlled inputs: use value + onChange as usual.

import { forwardRef } from "react";

type FieldInputProps = React.InputHTMLAttributes<HTMLInputElement>

export const FieldInput = forwardRef<HTMLInputElement, FieldInputProps>(
    function FieldInput({ className, ...props }, ref) {
        const aria = useFieldAriaProps()
        return (
            <input
                ref={ref}
                {...props}
                {...aria}
                // Allow callers to override id (e.g. when they need a custom one)
                id={props.id ?? aria.id}
                className={clsx(
                    "input",
                    aria["aria-invalid"] && "input-error",
                    className,
                )}
            />
        )
    },
)

type FieldSelectProps = React.SelectHTMLAttributes<HTMLSelectElement>

export const FieldSelect = forwardRef<HTMLSelectElement, FieldSelectProps>(
    function FieldSelect({ className, children, ...props }, ref) {
        const aria = useFieldAriaProps()
        return (
            <select
                ref={ref}
                {...props}
                {...aria}
                id={props.id ?? aria.id}
                className={clsx(
                    "input",
                    aria["aria-invalid"] && "input-error",
                    className,
                )}
            >
                {children}
            </select>
        )
    },
)

type FieldTextareaProps = React.TextareaHTMLAttributes<HTMLTextAreaElement>

export const FieldTextarea = forwardRef<HTMLTextAreaElement, FieldTextareaProps>(
    function FieldTextarea({ className, ...props }, ref) {
        const aria = useFieldAriaProps()
        return (
            <textarea
                ref={ref}
                {...props}
                {...aria}
                id={props.id ?? aria.id}
                className={clsx(
                    "input",
                    aria["aria-invalid"] && "input-error",
                    className,
                )}
            />
        )
    },
)

// ─── Status badge ──────────────────────────────────────────────────────────────

export function StatusBadge({ active }: { active: boolean }) {
    return (
        <span
            className={active ? "badge badge-success" : "badge badge-neutral"}
        >
            {active ? "Active" : "Inactive"}
        </span>
    );
}

// ─── Default badge ─────────────────────────────────────────────────────────────

export function DefaultBadge() {
    return <span className="badge badge-info">Default</span>;
}

// ─── Empty state ───────────────────────────────────────────────────────────────

interface EmptyStateProps {
    title: string;
    description: string;
    action?: ReactNode;
    icon?: ReactNode;
}

export function EmptyState({
    title,
    description,
    action,
    icon,
}: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center py-16 px-4 text-center">
            {icon && <div className="text-surface-300 mb-4">{icon}</div>}
            <p className="text-sm font-medium text-surface-600">{title}</p>
            <p className="text-xs text-surface-400 mt-1 max-w-xs">
                {description}
            </p>
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

// ─── Confirm dialog ────────────────────────────────────────────────────────────

import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";

interface ConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    isLoading?: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    confirmDisabled?: boolean;
    variant?: "danger" | "warning";
}

export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    isLoading,
    title,
    message,
    confirmLabel = "Confirm",
    confirmDisabled,
    variant = "danger",
}: ConfirmDialogProps) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <button
                        onClick={onClose}
                        className="btn-secondary btn-sm"
                        disabled={isLoading}
                    >
                        Cancel
                    </button>
                    <button
                        onClick={onConfirm}
                        disabled={isLoading || confirmDisabled}
                        className={
                            variant === "danger"
                                ? "btn-danger btn-sm"
                                : "btn-primary btn-sm"
                        }
                    >
                        {isLoading ? (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        ) : (
                            confirmLabel
                        )}
                    </button>
                </>
            }
        >
            <p className="text-sm text-surface-600">{message}</p>
        </Modal>
    );
}
