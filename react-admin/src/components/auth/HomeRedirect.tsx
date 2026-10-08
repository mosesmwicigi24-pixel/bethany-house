import { useEffect } from "react";
import { Navigate } from "react-router-dom";
import { useAuthStore } from "@/store/auth.store";
import { useHomePath } from "@/hooks/useHomePath";
import { Spinner } from "@/components/ui/Spinner";
import { StartupProblem } from "@/components/auth/StartupProblem";

/**
 * Sends "/" and unknown addresses to the user's own home page (lib/homePath)
 * instead of a fixed /dashboard that some roles cannot open.
 *
 * Home is decided by permission, so it waits for the user to load: deciding
 * on an empty permission list would send everyone to their profile.
 */
export function HomeRedirect() {
    const { isAuthenticated, user, fetchMe, startupError } = useAuthStore();
    const home = useHomePath();

    useEffect(() => {
        if (isAuthenticated && !user) fetchMe();
    }, [isAuthenticated, user, fetchMe]);

    if (!isAuthenticated) return <Navigate to="/login" replace />;

    if (!user && startupError) return <StartupProblem />;

    if (!user) {
        return (
            <div className="h-screen flex items-center justify-center bg-surface-50">
                <Spinner size="lg" />
            </div>
        );
    }

    return <Navigate to={home} replace />;
}
