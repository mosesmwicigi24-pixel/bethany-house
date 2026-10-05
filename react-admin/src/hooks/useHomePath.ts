import { usePermissions } from "@/hooks/usePermissions";
import { NAV } from "@/components/layout/Sidebar";
import { homePath, isFloorWorker } from "@/lib/homePath";

/** The signed-in user's home page (see lib/homePath). */
export function useHomePath(): string {
    const { can, isSuperAdmin } = usePermissions();
    return homePath({ can, isSuperAdmin }, NAV);
}

/** True for someone who works the floor rather than running it. */
export function useIsFloorWorker(): boolean {
    const { can, isSuperAdmin } = usePermissions();
    return isFloorWorker({ can, isSuperAdmin });
}
