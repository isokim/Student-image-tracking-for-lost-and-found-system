import { apiGet, apiPost } from "@/lib/apiClient";
import type { AppNotification } from "@/types";

/**
 * Notifications are stored in the database and pushed over WebSockets.
 * The database is the source of truth, so this endpoint is what the UI
 * falls back to on page load - it works whether or not the WebSocket
 * connection ever succeeded.
 */
export const notificationService = {
  async list(): Promise<AppNotification[]> {
    return apiGet<AppNotification[]>("/notifications");
  },

  async markRead(id: string): Promise<AppNotification> {
    return apiPost<AppNotification>(`/notifications/${id}/read`);
  },

  async markAllRead(): Promise<void> {
    await apiPost<{ message: string }>("/notifications/read-all");
  },
};
