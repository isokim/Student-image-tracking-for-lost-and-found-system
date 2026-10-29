import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { authService, type LoginPayload, type RegisterPayload } from "./authService";
import { setUnauthenticatedHandler } from "@/lib/apiClient";
import type { User } from "@/types";

// NOTE: the role checks this context exposes are for UX only - hiding
// menu items, choosing which dashboard to land on. The Laravel backend
// is the authority on what a user may actually do, and every protected
// endpoint re-checks permissions server-side. Nothing here can grant
// access to anything.

interface AuthContextValue {
  user: User | null;
  status: "loading" | "authenticated" | "unauthenticated";
  login: (payload: LoginPayload) => Promise<User>;
  register: (payload: RegisterPayload) => Promise<User>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthContextValue["status"]>("loading");
  const queryClient = useQueryClient();

  /**
   * The id of whoever the cache currently belongs to.
   *
   * Read from a ref rather than compared against `user`, because these
   * transitions happen inside callbacks that already closed over a stale
   * `user`.
   */
  const cachedForUserId = useRef<string | null>(null);

  /**
   * The single place the signed-in identity changes.
   *
   * Every transition goes through here for one reason: React Query keys
   * do not include the user, so cached queries are shared across
   * accounts. Two students using the same browser in turn - a shared
   * lab machine, a phone handed over - would otherwise see the previous
   * account's reports, matches and notifications rendered as their own
   * until each query happened to refetch. Clearing on a change of
   * identity makes that impossible rather than merely unlikely.
   */
  const applyUser = useCallback(
    (next: User | null) => {
      const nextId = next?.id ?? null;

      if (cachedForUserId.current !== nextId) {
        queryClient.clear();
      }

      cachedForUserId.current = nextId;
      setUser(next);
      setStatus(next ? "authenticated" : "unauthenticated");
    },
    [queryClient],
  );

  /**
   * Asks the API who we are.
   *
   * Authentication is a Sanctum session cookie, so there is no token to
   * read from storage - the only way to know whether a session is still
   * valid is to ask. A 401 here simply means "not signed in", which is
   * why it is caught rather than surfaced as an error.
   */
  const refresh = useCallback(async () => {
    try {
      applyUser(await authService.me());
    } catch {
      applyUser(null);
    }
  }, [applyUser]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  /**
   * A 401 on any request means the session died mid-session - it
   * expired, or it was signed out in another tab. Dropping to the login
   * screen is the only useful response; the alternative is a page that
   * silently never loads its data.
   */
  useEffect(() => {
    setUnauthenticatedHandler(() => applyUser(null));

    return () => setUnauthenticatedHandler(null);
  }, [applyUser]);

  const login = useCallback(
    async (payload: LoginPayload) => {
      const loggedInUser = await authService.login(payload);
      applyUser(loggedInUser);
      return loggedInUser;
    },
    [applyUser],
  );

  const register = useCallback(
    async (payload: RegisterPayload) => {
      // Registration signs the new student in, so there is no second
      // round-trip to the login endpoint.
      const newUser = await authService.register(payload);
      applyUser(newUser);
      return newUser;
    },
    [applyUser],
  );

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } finally {
      // Clear local state even if the request failed - the session may
      // already have expired, and leaving a stale user on screen is
      // worse than logging out optimistically.
      applyUser(null);
    }
  }, [applyUser]);

  return (
    <AuthContext.Provider value={{ user, status, login, register, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
