import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { adminService } from "@/features/admin/adminService";
import { claimService } from "@/features/claims/claimService";
import { foundItemService } from "@/features/found-items/foundItemService";
import { matchService } from "@/features/matches/matchService";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { formatDate, formatDateTime } from "@/lib/format";

function StatCard({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <p className="text-2xl font-semibold text-text">{value}</p>
      <p className="mt-1 text-sm text-text-muted">{label}</p>
    </div>
  );
}

export function AdminDashboardPage() {
  const stats = useQuery({ queryKey: ["admin", "stats"], queryFn: adminService.stats });
  const claims = useQuery({ queryKey: ["claims", "admin"], queryFn: claimService.list });
  const matches = useQuery({ queryKey: ["matches", "admin"], queryFn: matchService.list });
  const foundItems = useQuery({
    queryKey: ["found-items", "admin-recent"],
    queryFn: () => foundItemService.list(),
  });
  const auditLogs = useQuery({ queryKey: ["admin", "audit-logs", "recent"], queryFn: () => adminService.auditLogs() });

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="text-xl font-semibold text-text">Overview</h1>
        <p className="mt-1 text-sm text-text-muted">System-wide activity across lost and found reports.</p>
      </div>

      {stats.isLoading ? (
        <LoadingState rows={1} />
      ) : stats.data ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <StatCard label="Active lost reports" value={stats.data.activeLostReports} />
          <StatCard label="Pending verifications" value={stats.data.pendingFoundVerifications} />
          <StatCard label="Pending claims" value={stats.data.pendingClaims} />
          <StatCard label="Returned this month" value={stats.data.itemsReturnedThisMonth} />
        </div>
      ) : null}

      <section className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-text">Recent found item reports</h2>
          <Link to="/admin/found-items" className="text-sm font-medium text-navy-700 hover:underline">
            View all
          </Link>
        </div>
        {foundItems.isLoading ? (
          <LoadingState rows={2} />
        ) : foundItems.data && foundItems.data.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {foundItems.data.data.slice(0, 5).map((item) => (
              <li key={item.id} className="rounded-md border border-border bg-surface text-sm hover:border-border-strong">
                <Link to={`/admin/found-items/${item.id}`} className="flex items-center justify-between p-3">
                  <span className="text-text">{item.title}</span>
                  <span className="text-text-muted">{formatDate(item.createdAt)}</span>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No recent reports" />
        )}
      </section>

      <section className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-text">Pending claims</h2>
          <Link to="/admin/claims" className="text-sm font-medium text-navy-700 hover:underline">
            View all
          </Link>
        </div>
        {claims.isLoading ? (
          <LoadingState rows={2} />
        ) : claims.data && claims.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {claims.data.slice(0, 5).map((claim) => (
              <li key={claim.id} className="rounded-md border border-border bg-surface hover:border-border-strong">
                <Link to={`/admin/claims/${claim.id}`} className="block p-3">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-text">{claim.foundItem.title}</span>
                    <span className="text-text-muted">{claim.claimant.name}</span>
                  </div>
                  <div className="mt-2">
                    <ClaimStatus status={claim.status} />
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No pending claims" />
        )}
      </section>

      <section className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-text">AI matches</h2>
          <Link to="/admin/matches" className="text-sm font-medium text-navy-700 hover:underline">
            View all
          </Link>
        </div>
        {matches.isLoading ? (
          <LoadingState rows={2} />
        ) : matches.data && matches.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {matches.data.slice(0, 5).map((match) => (
              <li key={match.id} className="rounded-md border border-border bg-surface text-sm text-text hover:border-border-strong">
                <Link to={`/admin/matches/${match.id}`} className="block p-3">
                  {match.lostItem.title} - {match.foundItem.title}
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No matches yet" />
        )}
      </section>

      <section className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-text">Recent activity</h2>
          <Link to="/admin/audit-logs" className="text-sm font-medium text-navy-700 hover:underline">
            View audit log
          </Link>
        </div>
        {auditLogs.isLoading ? (
          <LoadingState rows={2} />
        ) : auditLogs.data && auditLogs.data.data.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {auditLogs.data.data.slice(0, 5).map((entry) => (
              <li key={entry.id} className="flex items-center justify-between rounded-md border border-border bg-surface p-3 text-sm">
                <span className="text-text">
                  {entry.actor?.name ?? "System"} {entry.action} {entry.targetType}
                </span>
                <span className="text-text-muted">{formatDateTime(entry.createdAt)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No activity recorded yet" />
        )}
      </section>
    </div>
  );
}
