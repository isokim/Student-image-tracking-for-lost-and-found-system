import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { SearchBar } from "@/components/common/SearchBar";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { Pagination } from "@/components/ui/Pagination";
import { LostItemStatusBadge } from "@/components/common/ItemStatusBadge";
import { formatDate, formatLocation } from "@/lib/format";
import { lostItemService } from "@/features/lost-items/lostItemService";
import type { LostItemReport } from "@/types";

export function AdminLostItemsPage() {
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState("");
  const navigate = useNavigate();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["admin", "lost-items", page],
    queryFn: () => lostItemService.list({ page }),
  });

  const rows = data?.data.filter((item) => item.title.toLowerCase().includes(query.toLowerCase())) ?? [];

  const columns: DataTableColumn<LostItemReport>[] = [
    { key: "title", header: "Item", render: (row) => row.title },
    { key: "reportedBy", header: "Reported by", render: (row) => row.reportedBy.name },
    { key: "location", header: "Location", render: (row) => formatLocation(row) },
    { key: "date", header: "Date", render: (row) => formatDate(row.createdAt) },
    { key: "status", header: "Status", render: (row) => <LostItemStatusBadge status={row.status} /> },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Lost items" }]} />
      <h1 className="text-xl font-semibold text-text">Lost item reports</h1>
      <SearchBar value={query} onChange={setQuery} placeholder="Search by item title…" />

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load lost items right now." />
      ) : rows.length > 0 ? (
        <>
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            onRowClick={(row) => navigate(`/admin/lost-items/${row.id}`)}
            caption="Lost item reports"
          />
          {data && <Pagination currentPage={data.meta.currentPage} lastPage={data.meta.lastPage} onPageChange={setPage} />}
        </>
      ) : (
        <EmptyState title="No lost item reports" />
      )}
    </div>
  );
}
