import { Link } from "react-router-dom";
import { PackagePlus, PackageSearch } from "lucide-react";
import { useAuth } from "@/features/auth/AuthContext";
import { useDashboardData } from "./useDashboardData";
import { DashboardSection } from "./DashboardSection";
import { ItemCard } from "@/components/common/ItemCard";
import { NotificationItem } from "@/components/common/NotificationItem";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { buttonStyles } from "@/components/ui/Button";
import { notificationService } from "@/features/notifications/notificationService";
import { formatLocation } from "@/lib/format";
import { useQueryClient } from "@tanstack/react-query";

export function StudentDashboardPage() {
  const { user } = useAuth();
  const { lostItems, foundItems, matches, claims, notifications } = useDashboardData();
  const queryClient = useQueryClient();

  async function markRead(id: string) {
    await notificationService.markRead(id);
    queryClient.invalidateQueries({ queryKey: ["notifications"] });
  }

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-text">Welcome back{user ? `, ${user.name.split(" ")[0]}` : ""}</h1>
          <p className="mt-1 text-sm text-text-muted">Here's what's happening with your reports and claims.</p>
        </div>
        <div className="flex gap-2">
          <Link to="/lost-items/report" className={buttonStyles("primary", "md")}>
            <PackageSearch className="h-4 w-4" aria-hidden="true" />
            Report lost item
          </Link>
          <Link to="/found-items/report" className={buttonStyles("outline", "md")}>
            <PackagePlus className="h-4 w-4" aria-hidden="true" />
            Report found item
          </Link>
        </div>
      </div>

      <DashboardSection title="Your active lost reports" viewAllHref="/lost-items">
        {lostItems.isLoading ? (
          <LoadingState rows={2} />
        ) : lostItems.data && lostItems.data.length > 0 ? (
          <div className="grid gap-2 sm:grid-cols-2">
            {lostItems.data.slice(0, 4).map((item) => (
              <ItemCard key={item.id} item={item} kind="lost" href={`/lost-items/${item.id}`} />
            ))}
          </div>
        ) : (
          <EmptyState
            title="No lost reports yet"
            description="If you've misplaced something on campus, report it so staff and the search can start looking."
            action={
              <Link to="/lost-items/report" className={buttonStyles("outline", "sm")}>
                Report a lost item
              </Link>
            }
          />
        )}
      </DashboardSection>

      <DashboardSection title="Your found reports" viewAllHref="/found-items">
        {foundItems.isLoading ? (
          <LoadingState rows={2} />
        ) : foundItems.data && foundItems.data.length > 0 ? (
          <div className="grid gap-2 sm:grid-cols-2">
            {foundItems.data.slice(0, 4).map((item) => (
              <ItemCard key={item.id} item={item} kind="found" href={`/found-items/${item.id}`} />
            ))}
          </div>
        ) : (
          <EmptyState title="No found reports yet" description="Handed something in? Log it here so its owner can find it." />
        )}
      </DashboardSection>

      <DashboardSection title="Possible matches" viewAllHref="/matches">
        {matches.isLoading ? (
          <LoadingState rows={2} />
        ) : matches.data && matches.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {matches.data.slice(0, 3).map((match) => (
              <li key={match.id}>
                <Link
                  to={`/matches/${match.id}`}
                  className="flex items-center justify-between rounded-md border border-border bg-surface p-3 hover:border-border-strong"
                >
                  <div>
                    <p className="text-sm font-medium text-text">{match.lostItem.title}</p>
                    <p className="text-xs text-text-muted">
                      {match.overallConfidence === "likely" ? "Likely match" : "Possible match"} found in{" "}
                      {formatLocation(match.foundItem)}
                    </p>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No matches yet" description="We'll notify you as soon as a found item looks like yours." />
        )}
      </DashboardSection>

      <DashboardSection title="Pending claims" viewAllHref="/claims">
        {claims.isLoading ? (
          <LoadingState rows={2} />
        ) : claims.data && claims.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {claims.data.slice(0, 3).map((claim) => (
              <li key={claim.id} className="rounded-md border border-border bg-surface p-3">
                <div className="flex items-center justify-between">
                  <p className="text-sm font-medium text-text">{claim.foundItem.title}</p>
                  <Link to={`/claims/${claim.id}`} className="text-xs font-medium text-navy-700 hover:underline">
                    View
                  </Link>
                </div>
                <div className="mt-2">
                  <ClaimStatus status={claim.status} />
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No pending claims" description="Claims you start on a possible match will show up here." />
        )}
      </DashboardSection>

      <DashboardSection title="Recent notifications" viewAllHref="/notifications">
        {notifications.isLoading ? (
          <LoadingState rows={2} />
        ) : notifications.data && notifications.data.length > 0 ? (
          <div className="flex flex-col gap-2">
            {notifications.data.slice(0, 4).map((n) => (
              <NotificationItem key={n.id} notification={n} onMarkRead={markRead} />
            ))}
          </div>
        ) : (
          <EmptyState title="You're all caught up" />
        )}
      </DashboardSection>
    </div>
  );
}
