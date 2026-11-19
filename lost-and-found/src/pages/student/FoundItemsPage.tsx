import { useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { ItemCard } from "@/components/common/ItemCard";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { Pagination } from "@/components/ui/Pagination";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Button } from "@/components/ui/Button";
import { HandInNote } from "@/components/common/HandInNote";
import { foundItemService } from "@/features/found-items/foundItemService";
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { ITEM_CATEGORIES } from "@/types";

const categoryOptions = [
  { value: "", label: "Any category" },
  ...ITEM_CATEGORIES.map((category) => ({ value: category.value, label: category.label })),
];

export function FoundItemsPage() {
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState("");
  const [category, setCategory] = useState("");

  const q = useDebouncedValue(keyword.trim(), 300);
  const isFiltered = Boolean(q || category);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["found-items", { page, q, category }],
    queryFn: () =>
      foundItemService.list({
        page,
        q: q || undefined,
        category: category || undefined,
      }),
    // Keeps the current cards on screen while the next filter loads,
    // rather than flashing a skeleton on every keystroke.
    placeholderData: keepPreviousData,
  });

  // New filters start from page 1. Done in the handlers (and on the
  // debounced keyword) so a stale page number is never requested.
  const [lastQ, setLastQ] = useState(q);
  if (lastQ !== q) {
    setLastQ(q);
    setPage(1);
  }

  function clearFilters() {
    setKeyword("");
    setCategory("");
    setPage(1);
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Found items" }]} />

      <h1 className="text-xl font-semibold text-text">Found items</h1>

      <HandInNote />

      <div className="grid max-w-2xl gap-3 sm:grid-cols-[1fr_12rem]">
        <Input
          label="Search"
          hideLabel
          type="search"
          placeholder="Search found items, e.g. black wallet"
          value={keyword}
          onChange={(e) => setKeyword(e.target.value)}
        />
        <Select
          label="Category"
          hideLabel
          options={categoryOptions}
          value={category}
          onChange={(e) => {
            setCategory(e.target.value);
            setPage(1);
          }}
        />
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
      ) : isFiltered ? (
        <EmptyState
          title="No matching found items"
          description="Try a different word or category."
          action={
            <Button type="button" variant="outline" size="sm" onClick={clearFilters}>
              Clear filters
            </Button>
          }
        />
      ) : (
        <EmptyState
          title="No found items listed"
          description="Items handed in at the lost & found office will appear here once staff have logged them."
        />
      )}
    </div>
  );
}
