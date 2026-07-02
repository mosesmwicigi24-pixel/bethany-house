import type { ReactNode } from 'react'
import { usePermissions } from '@/hooks/usePermissions'

interface PermissionGateProps {
  /** Single permission required */
  permission?: string
  /** Any of these permissions (OR) */
  anyOf?: string[]
  /** All of these permissions (AND) */
  allOf?: string[]
  /** Require a specific role */
  role?: string
  /** Rendered when permission check fails (default: null) */
  fallback?: ReactNode
  children: ReactNode
}

/**
 * Conditionally renders children based on the current user's permissions.
 *
 * <PermissionGate permission="procurement.create">
 *   <CreateButton />
 * </PermissionGate>
 */
export function PermissionGate({
  permission,
  anyOf,
  allOf,
  role,
  fallback = null,
  children,
}: PermissionGateProps) {
  const { can, canAny, canAll, hasRole } = usePermissions()

  let allowed = true

  if (permission) allowed = allowed && can(permission)
  if (anyOf?.length) allowed = allowed && canAny(...anyOf)
  if (allOf?.length) allowed = allowed && canAll(...allOf)
  if (role) allowed = allowed && hasRole(role)

  return <>{allowed ? children : fallback}</>
}
