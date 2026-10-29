import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { PackagePlus } from "lucide-react";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { ItemCard } from "@/components/common/ItemCard";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { Pagination } from "@/components/ui/Pagination";
import { buttonStyles } from "@/components/ui/Button";
import { foundItemService } from "@/features/found-items/foundItemService";

export function FoundItemsPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["found-items", page],
    queryFn: () => foundItemService.list({ page }),
  });

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Found items" }]} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-text">Found items</h1>
        <Link to="/found-items/report" className={buttonStyles("primary", "md")}>
          <PackagePlus className="h-4 w-4" aria-hidden="true" />
          Report found item
        </Link>
      </div>

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load found items right now." />
      ) : data && data.data.length > 0 ? (
        <>
          <div className="grid gap-2 sm:grid-cols-2">
            {data.data.map((item) => (
              <ItemCard key={item.id} item={item} kind="found" href={`/found-items/${item.id}`} />
            ))}
          </div>
          <Pagination currentPage={data.meta.currentPage} lastPage={data.meta.lastPage} onPageChange={setPage} />
        </>
      ) : (
        <EmptyState
          title="No found items listed"
          description="Found items handed in by students and staff will appear here."
        />
      )}
    </div>
  );
}
