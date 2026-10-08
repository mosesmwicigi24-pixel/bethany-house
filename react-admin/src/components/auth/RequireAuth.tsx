import { useEffect } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuthStore } from '@/store/auth.store'
import { Spinner } from '@/components/ui/Spinner'
import { StartupProblem } from '@/components/auth/StartupProblem'

interface RequireAuthProps {
  children: React.ReactNode
}

/**
 * Wrap protected routes with this. Redirects to /login if not authenticated.
 * On mount it re-validates the stored token against the API.
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const { isAuthenticated, isLoading, fetchMe, user, startupError } = useAuthStore()
  const location = useLocation()

  useEffect(() => {
    // If we have a token but no user object (page refresh), fetch user
    if (isAuthenticated && !user) {
      fetchMe()
    }
  }, [isAuthenticated, user, fetchMe])

  // Signed in, but the server could not be reached to load who: keep the
  // session and say so, rather than sending them to the login page.
  if (isAuthenticated && !user && startupError) {
    return <StartupProblem />
  }

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
