import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { FoundItemStatusBadge } from "@/components/common/ItemStatusBadge";
import { formatDate, formatLocation } from "@/lib/format";
import { foundItemService } from "@/features/found-items/foundItemService";
import { useNavigate } from "react-router-dom";
import { buttonStyles } from "@/components/ui/Button";
import { PackagePlus } from "lucide-react";
import type { FoundItemReport } from "@/types";

export function StaffFoundItemsPage() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["found-items", "staff"],
    queryFn: () => foundItemService.list(),
  });

  const columns: DataTableColumn<FoundItemReport>[] = [
    { key: "title", header: "Item", render: (row) => row.title },
    { key: "location", header: "Location", render: (row) => formatLocation(row) },
    { key: "reportedBy", header: "Reported by", render: (row) => row.reportedBy.name },
    { key: "date", header: "Date", render: (row) => formatDate(row.createdAt) },
    { key: "status", header: "Status", render: (row) => <FoundItemStatusBadge status={row.status} /> },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Found items" }]} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-text">Found item verification</h1>
        <Link to="/staff/found-items/report" className={buttonStyles("primary", "md")}>
          <PackagePlus className="h-4 w-4" aria-hidden="true" />
          Report found item
        </Link>
      </div>

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load found items right now." />
      ) : data && data.data.length > 0 ? (
        <DataTable
          columns={columns}
          rows={data.data}
          rowKey={(row) => row.id}
          onRowClick={(row) => navigate(`/staff/items/${row.id}`)}
          caption="Found items awaiting or completed verification"
        />
      ) : (
        <EmptyState title="No found items reported" />
      )}
    </div>
  );
}
