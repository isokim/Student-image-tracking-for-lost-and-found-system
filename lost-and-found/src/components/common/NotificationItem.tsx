import { Link } from "react-router-dom";
import { Bell, CheckCircle2, Search, UserCheck, XCircle } from "lucide-react";
import { cn } from "@/lib/cn";
import { formatRelativeTime } from "@/lib/format";
import type { AppNotification, NotificationType } from "@/types";

const iconMap: Record<NotificationType, typeof Bell> = {
  possible_match: Search,
  claim_approved: CheckCircle2,
  claim_rejected: XCircle,
  found_item_verified: UserCheck,
  item_returned: CheckCircle2,
  staff_message: Bell,
};

export function NotificationItem({
  notification,
  onMarkRead,
}: {
  notification: AppNotification;
  onMarkRead: (id: string) => void;
}) {
  const Icon = iconMap[notification.type];
  const isUnread = !notification.readAt;

  const content = (
    <div
      className={cn(
        "flex gap-3 rounded-md border border-border p-3",
        isUnread ? "bg-navy-50" : "bg-surface",
      )}
    >
      <span
        className={cn(
          "flex h-8 w-8 shrink-0 items-center justify-center rounded-full",
          isUnread ? "bg-navy-100 text-navy-900" : "bg-surface-sunken text-text-muted",
        )}
      >
        <Icon className="h-4 w-4" aria-hidden="true" />
      </span>
      <div className="flex-1 min-w-0">
        <p className={cn("text-sm", isUnread ? "font-medium text-text" : "text-text")}>{notification.title}</p>
        <p className="mt-0.5 text-sm text-text-muted">{notification.body}</p>
        <p className="mt-1 text-xs text-text-faint">{formatRelativeTime(notification.createdAt)}</p>
      </div>
      {isUnread && (
        <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-navy-700" aria-label="Unread" />
      )}
    </div>
  );

  if (notification.relatedResourceUrl) {
    return (
      <Link
        to={notification.relatedResourceUrl}
        onClick={() => isUnread && onMarkRead(notification.id)}
        className="block focus-visible:outline-2 focus-visible:outline-navy-600"
      >
        {content}
      </Link>
    );
  }

  return (
    <button
      type="button"
      onClick={() => isUnread && onMarkRead(notification.id)}
      className="block w-full text-left focus-visible:outline-2 focus-visible:outline-navy-600"
    >
      {content}
    </button>
  );
}
