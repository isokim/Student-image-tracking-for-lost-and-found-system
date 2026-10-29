import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "@/features/auth/AuthContext";
import { LoadingState } from "@/components/ui/LoadingState";
import { UnauthorizedState } from "@/components/ui/ErrorState";
import type { UserRole } from "@/types";

export function ProtectedRoute({ allowedRoles }: { allowedRoles?: UserRole[] }) {
  const { user, status } = useAuth();
  const location = useLocation();

  if (status === "loading") {
    return (
      <div className="p-6">
        <LoadingState rows={3} label="Checking your session" />
      </div>
    );
  }

  if (status === "unauthenticated" || !user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (allowedRoles && user.role !== "admin" && !allowedRoles.includes(user.role)) {
    return (
      <div className="p-6">
        <UnauthorizedState />
      </div>
    );
  }

  return <Outlet />;
}
