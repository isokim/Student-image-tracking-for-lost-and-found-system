import type { UserRole } from "@/types";

/**
 * Which roles may open a given path.
 *
 * The router puts every signed-in route under exactly one role group -
 * `/staff/*` behind the staff layout, `/admin/*` behind the admin
 * layout, everything else behind the student layout - so this mirrors
 * that with a prefix rule rather than a table that could drift. The
 * three paths that are shared across roles are listed explicitly.
 *
 * Kept next to the router for that reason: if a route group ever gains a
 * second role, this has to change with it.
 */
const PUBLIC_PATHS = ["/", "/login", "/register", "/forgot-password"];

/**
 * Behind the auth guard, but not restricted to one role.
 *
 * These are linked from the shared header of every layout and are
 * declared once for all roles in the router, so every role may open
 * them.
 */
const SHARED_PATHS = ["/notifications", "/profile", "/settings"];

export function rolesForPath(pathname: string): UserRole[] | null {
  if (PUBLIC_PATHS.includes(pathname)) {
    return null;
  }

  if (SHARED_PATHS.includes(pathname)) {
    return ["student", "staff", "admin"];
  }

  if (pathname === "/admin" || pathname.startsWith("/admin/")) {
    return ["admin"];
  }

  if (pathname === "/staff" || pathname.startsWith("/staff/")) {
    return ["staff", "admin"];
  }

  return ["student", "admin"];
}

/**
 * Where to send someone straight after they sign in.
 *
 * `from` is the page they were bounced off when their session ended, and
 * honouring it blindly was a bug: signing out of `/admin/dashboard` and
 * then signing in as a student sent them back to the admin dashboard,
 * where ProtectedRoute correctly refused them - so a working account was
 * greeted with "You don't have access to this page".
 *
 * A destination is only used when the account that just signed in can
 * actually open it. Anything else falls back to that role's own
 * dashboard, which is always somewhere they can be.
 */
export function postLoginDestination(role: UserRole, from?: string | null): string {
  const fallback = role === "admin" ? "/admin/dashboard" : role === "staff" ? "/staff/dashboard" : "/dashboard";

  if (!from || PUBLIC_PATHS.includes(from)) {
    return fallback;
  }

  const allowed = rolesForPath(from);

  return allowed && allowed.includes(role) ? from : fallback;
}
