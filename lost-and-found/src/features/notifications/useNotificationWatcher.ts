import { useEffect, useRef } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/features/auth/AuthContext";
import { useToast } from "@/components/ui/Toast";
import { notificationService } from "@/features/notifications/notificationService";
import type { AppNotification, UserRole } from "@/types";

/** How often the bell re-checks the server while the tab is visible. */
const POLL_INTERVAL_MS = 15_000;

/** More new notifications than this at once become one summary toast. */
const MAX_INDIVIDUAL_TOASTS = 3;

/**
 * Where a notification's link should go for this user.
 *
 * Staff-facing links are written as /staff/... on the server, but admins
 * use the same pages under /admin/... and cannot open the /staff routes.
 */
export function resolveNotificationUrl(url: string | undefined, role: UserRole | undefined): string | undefined {
  if (!url || role !== "admin") return url;

  return url
    .replace(/^\/staff\/claims\//, "/admin/claims/")
    .replace(/^\/staff\/items\//, "/admin/found-items/");
}

/**
 * Keeps notifications current without a page refresh, and pops up a
 * toast when a new one arrives.
 *
 * Mounted once, in TopNavigation, which every signed-in layout renders
 * exactly once. Returns the unread count for the bell.
 *
 * Notifications that already existed when the page loaded are not
 * toasted - only ones that appear on a later poll. The set of ids seen so
 * far is reset whenever the signed-in user changes, so switching account
 * does not replay the new account's history as "new".
 */
export function useNotificationWatcher(): { unreadCount: number } {
  const { user } = useAuth();
  const { show } = useToast();
  const navigate = useNavigate();

  const seenIds = useRef<Set<string> | null>(null);
  const seenForUserId = useRef<string | null>(null);

  const { data } = useQuery({
    queryKey: ["notifications"],
    queryFn: notificationService.list,
    enabled: !!user,
    refetchInterval: POLL_INTERVAL_MS,
    refetchIntervalInBackground: false,
  });

  useEffect(() => {
    const userId = user?.id ?? null;

    if (seenForUserId.current !== userId) {
      seenForUserId.current = userId;
      seenIds.current = null;
    }

    if (!userId || !data) return;

    // First load for this user: remember what is already there, say nothing.
    if (seenIds.current === null) {
      seenIds.current = new Set(data.map((n) => n.id));
      return;
    }

    const seen = seenIds.current;
    const fresh = data.filter((n) => !seen.has(n.id) && !n.readAt);
    data.forEach((n) => seen.add(n.id));

    if (fresh.length === 0) return;

    const open = (notification: AppNotification) => {
      const url = resolveNotificationUrl(notification.relatedResourceUrl, user?.role);
      // Marking it read also refreshes the bell (every successful
      // non-GET request refreshes the query cache).
      void notificationService.markRead(notification.id).catch(() => undefined);
      navigate(url ?? "/notifications");
    };

    if (fresh.length > MAX_INDIVIDUAL_TOASTS) {
      show({
        tone: "info",
        title: `${fresh.length} new notifications`,
        action: { label: "View all", onClick: () => navigate("/notifications") },
        duration: 10_000,
      });
      return;
    }

    // Oldest first, so the newest ends up at the bottom of the stack.
    [...fresh].reverse().forEach((notification) => {
      show({
        tone: "info",
        title: notification.title,
        description: notification.body,
        action: {
          label: actionLabel(notification),
          onClick: () => open(notification),
        },
        duration: 10_000,
      });
    });
  }, [data, user?.id, user?.role, show, navigate]);

  return { unreadCount: data?.filter((n) => !n.readAt).length ?? 0 };
}

function actionLabel(notification: AppNotification): string {
  if (!notification.relatedResourceUrl) return "View notifications";

  switch (notification.type) {
    case "possible_match":
      return "View match";
    case "claim_approved":
    case "claim_rejected":
    case "item_returned":
      return "View claim";
    case "found_item_verified":
      return "View item";
    default:
      return notification.relatedResourceUrl.includes("/claims/") ? "Review claim" : "Open";
  }
}
