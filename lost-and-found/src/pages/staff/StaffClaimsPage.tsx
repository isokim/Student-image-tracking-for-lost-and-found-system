import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { formatDate } from "@/lib/format";
import { claimService } from "@/features/claims/claimService";
import type { Claim } from "@/types";

export function StaffClaimsPage() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ["claims", "staff"], queryFn: claimService.list });

  const columns: DataTableColumn<Claim>[] = [
    { key: "item", header: "Item", render: (row) => row.foundItem.title },
    { key: "claimant", header: "Claimant", render: (row) => row.claimant.name },
    { key: "submitted", header: "Submitted", render: (row) => formatDate(row.createdAt) },
    { key: "status", header: "Status", render: (row) => <ClaimStatus status={row.status} /> },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Claims" }]} />
      <h1 className="text-xl font-semibold text-text">Claims review</h1>

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load claims right now." />
      ) : data && data.length > 0 ? (
        <DataTable
          columns={columns}
          rows={data}
          rowKey={(row) => row.id}
          onRowClick={(row) => navigate(`/staff/claims/${row.id}`)}
          caption="Claims submitted by students"
        />
      ) : (
        <EmptyState title="No claims to review" />
      )}
    </div>
  );
}
