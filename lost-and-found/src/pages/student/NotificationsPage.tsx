import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { NotificationItem } from "@/components/common/NotificationItem";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { notificationService } from "@/features/notifications/notificationService";

export function NotificationsPage() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["notifications"],
    queryFn: notificationService.list,
  });

  async function markRead(id: string) {
    await notificationService.markRead(id);
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
  }

  async function markAllRead() {
    await notificationService.markAllRead();
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
  }

  const hasUnread = data?.some((n) => !n.readAt);

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Notifications" }]} />

      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-text">Notifications</h1>
        {hasUnread && (
          <button type="button" onClick={markAllRead} className="text-sm font-medium text-navy-700 hover:underline">
            Mark all as read
          </button>
        )}
      </div>

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load notifications right now." />
      ) : data && data.length > 0 ? (
        <div className="flex flex-col gap-2">
          {data.map((n) => (
            <NotificationItem key={n.id} notification={n} onMarkRead={markRead} />
          ))}
        </div>
      ) : (
        <EmptyState title="No notifications" description="You'll see updates about matches and claims here." />
      )}
    </div>
  );
}
