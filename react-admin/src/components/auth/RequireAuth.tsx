import { useEffect } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuthStore } from '@/store/auth.store'
import { Spinner } from '@/components/ui/Spinner'

interface RequireAuthProps {
  children: React.ReactNode
}

/**
 * Wrap protected routes with this. Redirects to /login if not authenticated.
 * On mount it re-validates the stored token against the API.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const { isAuthenticated, isLoading, fetchMe, user } = useAuthStore()
  const location = useLocation()

  useEffect(() => {
    // If we have a token but no user object (page refresh), fetch user
    if (isAuthenticated && !user) {
      fetchMe()
    }
  }, [isAuthenticated, user, fetchMe])

  if (isLoading) {
    return (
      <div className="h-screen flex items-center justify-center bg-surface-50">
        <Spinner size="lg" />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  return <>{children}</>
}
