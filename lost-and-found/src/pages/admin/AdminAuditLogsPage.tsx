import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { Pagination } from "@/components/ui/Pagination";
import { formatDateTime } from "@/lib/format";
import { adminService, type AuditLogEntry } from "@/features/admin/adminService";

export function AdminAuditLogsPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["admin", "audit-logs", page],
    queryFn: () => adminService.auditLogs({ page }),
  });

  const columns: DataTableColumn<AuditLogEntry>[] = [
    // actor is null for events with no signed-in user, such as a failed
    // login attempt or a status change made by a queued job.
    { key: "actor", header: "Actor", render: (row) => row.actor ? `${row.actor.name} (${row.actor.role})` : "System" },
    { key: "action", header: "Action", render: (row) => row.action },
    { key: "target", header: "Target", render: (row) => row.targetType ? `${row.targetType} #${row.targetId}` : "-" },
    { key: "date", header: "When", render: (row) => formatDateTime(row.createdAt) },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Audit logs" }]} />
      <h1 className="text-xl font-semibold text-text">System activity</h1>

      {isLoading ? (
        <LoadingState rows={6} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load the audit log right now." />
      ) : data && data.data.length > 0 ? (
        <>
          <DataTable columns={columns} rows={data.data} rowKey={(row) => row.id} caption="System audit log" />
          <Pagination currentPage={data.meta.currentPage} lastPage={data.meta.lastPage} onPageChange={setPage} />
        </>
      ) : (
        <EmptyState title="No activity recorded yet" />
      )}
    </div>
  );
}
