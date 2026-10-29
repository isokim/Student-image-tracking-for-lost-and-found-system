import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { foundItemService } from "@/features/found-items/foundItemService";
import { claimService } from "@/features/claims/claimService";
import { matchService } from "@/features/matches/matchService";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { FoundItemStatusBadge } from "@/components/common/ItemStatusBadge";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { formatDate } from "@/lib/format";

export function StaffDashboardPage() {
  const pendingFound = useQuery({
    queryKey: ["found-items", "pending"],
    queryFn: () => foundItemService.list({ status: "pending_verification" }),
  });
  const pendingClaims = useQuery({ queryKey: ["claims"], queryFn: claimService.list });
  const matches = useQuery({ queryKey: ["matches"], queryFn: matchService.list });

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="text-xl font-semibold text-text">Staff dashboard</h1>
        <p className="mt-1 text-sm text-text-muted">Verification queue, claims to review, and return processing.</p>
      </div>

      <section className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-text">Found items awaiting verification</h2>
          <Link to="/staff/found-items" className="text-sm font-medium text-navy-700 hover:underline">
            View all
          </Link>
        </div>
        {pendingFound.isLoading ? (
          <LoadingState rows={2} />
        ) : pendingFound.data && pendingFound.data.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {pendingFound.data.data.slice(0, 5).map((item) => (
              <li key={item.id}>
                <Link
                  to={`/staff/items/${item.id}`}
                  className="flex items-center justify-between rounded-md border border-border bg-surface p-3 hover:border-border-strong"
                >
                  <div>
                    <p className="text-sm font-medium text-text">{item.title}</p>
                    <p className="text-xs text-text-muted">
                      Reported by {item.reportedBy.name} · {formatDate(item.createdAt)}
                    </p>
                  </div>
                  <FoundItemStatusBadge status={item.status} />
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="Nothing waiting on verification" />
        )}
      </section>

      <section className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-text">Claims waiting for review</h2>
          <Link to="/staff/claims" className="text-sm font-medium text-navy-700 hover:underline">
            View all
          </Link>
        </div>
        {pendingClaims.isLoading ? (
          <LoadingState rows={2} />
        ) : pendingClaims.data && pendingClaims.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {pendingClaims.data.slice(0, 5).map((claim) => (
              <li key={claim.id}>
                <Link
                  to={`/staff/claims/${claim.id}`}
                  className="block rounded-md border border-border bg-surface p-3 hover:border-border-strong"
                >
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium text-text">{claim.foundItem.title}</p>
                    <span className="text-xs text-text-muted">by {claim.claimant.name}</span>
                  </div>
                  <div className="mt-2">
                    <ClaimStatus status={claim.status} />
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No claims pending review" />
        )}
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold text-text">Recent AI matches</h2>
        {matches.isLoading ? (
          <LoadingState rows={2} />
        ) : matches.data && matches.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {matches.data.slice(0, 5).map((match) => (
              <li key={match.id} className="rounded-md border border-border bg-surface p-3 text-sm text-text">
                {match.lostItem.title} - {match.foundItem.title}
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No matches to review" />
        )}
      </section>
    </div>
  );
}
