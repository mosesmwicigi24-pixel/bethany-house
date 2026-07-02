import { useAuthStore } from '@/store/auth.store'

/**
 * Returns helpers to check the current user's permissions and roles.
 *
 * Usage:
 *   const { can, hasRole, isAdmin } = usePermissions()
 *   if (can('procurement.create')) { ... }
 */
export function usePermissions() {
  const user = useAuthStore((s) => s.user)

  const permissions: string[] = user?.permissions ?? []
  const roles: string[] = user?.roles?.map((r) => r.name) ?? []

  return {
    /** True if the user holds the given permission string */
    can: (permission: string): boolean => {
      if (!user) return false
      // Super admins bypass all checks
      if (roles.includes('super_admin')) return true
      return permissions.includes(permission)
    },

    /** True if the user has ANY of the given permissions */
    canAny: (...perms: string[]): boolean => {
      if (!user) return false
      if (roles.includes('super_admin')) return true
      return perms.some((p) => permissions.includes(p))
    },

    /** True if the user has ALL of the given permissions */
    canAll: (...perms: string[]): boolean => {
      if (!user) return false
      if (roles.includes('super_admin')) return true
      return perms.every((p) => permissions.includes(p))
    },

    /** True if the user holds the given role name */
    hasRole: (role: string): boolean => roles.includes(role),

    /** Convenience: is the user a super admin or admin */
    isAdmin: roles.includes('super_admin') || roles.includes('admin'),

    /** Convenience: is the user a super admin */
    isSuperAdmin: roles.includes('super_admin'),
  }
}
