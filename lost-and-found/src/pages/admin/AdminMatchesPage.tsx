import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { Badge } from "@/components/ui/Badge";
import { formatDate } from "@/lib/format";
import { matchService } from "@/features/matches/matchService";
import type { ItemMatch } from "@/types";

export function AdminMatchesPage() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ["admin", "matches"], queryFn: matchService.list });

  const columns: DataTableColumn<ItemMatch>[] = [
    { key: "lost", header: "Lost item", render: (row) => row.lostItem.title },
    { key: "found", header: "Found item", render: (row) => row.foundItem.title },
    {
      key: "confidence",
      header: "Confidence",
      render: (row) => (
        <Badge tone={row.overallConfidence === "likely" ? "info" : "neutral"}>
          {row.overallConfidence === "likely" ? "Likely" : "Possible"}
        </Badge>
      ),
    },
    { key: "date", header: "Date", render: (row) => formatDate(row.createdAt) },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Matches" }]} />
      <h1 className="text-xl font-semibold text-text">AI matches</h1>

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load matches right now." />
      ) : data && data.length > 0 ? (
        <DataTable
          columns={columns}
          rows={data}
          rowKey={(row) => row.id}
          onRowClick={(row) => navigate(`/admin/matches/${row.id}`)}
          caption="All matches identified by the search system"
        />
      ) : (
        <EmptyState title="No matches identified yet" />
      )}
    </div>
  );
}
