import { useState, type FormEvent } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Camera, RotateCcw, Search } from "lucide-react";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { MatchScoreCard } from "@/components/common/MatchScore";
import { formatDate, formatLocation } from "@/lib/format";
import { normalizeApiError } from "@/lib/apiClient";
import { ITEM_CATEGORIES, type FoundItemReport, type MatchScoreBreakdown } from "@/types";
import { searchService, type SearchFilters } from "@/features/search/searchService";
import { lostItemService } from "@/features/lost-items/lostItemService";

type SearchMode = "details" | "photo";

interface SearchResult {
  id: string;
  item: FoundItemReport;
  confidence?: "possible" | "likely";
  scores?: MatchScoreBreakdown;
}

const emptyFilters: SearchFilters = {
  query: "",
  category: "",
  colour: "",
  location: "",
};

export function SearchPage() {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<SearchMode>("details");
  const [filters, setFilters] = useState<SearchFilters>(emptyFilters);
  const [results, setResults] = useState<SearchResult[] | null>(null);
  const [isSearching, setIsSearching] = useState(false);
  const [searchingLostItemId, setSearchingLostItemId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const lostReports = useQuery({
    queryKey: ["lost-items", "mine"],
    queryFn: lostItemService.mine,
    enabled: mode === "photo",
  });

  function updateFilter<K extends keyof SearchFilters>(field: K) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
      const value = e.target.value || undefined;
      setFilters((current) => ({ ...current, [field]: value }));
    };
  }

  function cleanFilters(): SearchFilters {
    return Object.fromEntries(
      Object.entries(filters).filter(([, value]) => value && String(value).trim()),
    ) as SearchFilters;
  }

  function resetSearch() {
    setFilters(emptyFilters);
    setResults(null);
    setError(null);
    setSearchingLostItemId(null);
  }

  async function handleSearch(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setResults(null);

    const searchFilters = cleanFilters();

    if (mode === "details" && Object.keys(searchFilters).length === 0) {
      setError("Add an item name, category, colour, or location to search.");
      return;
    }

    setIsSearching(true);

    try {
      const itemResults = await searchService.search(searchFilters);
      setResults(itemResults.map((item) => ({ id: item.id, item })));
    } catch (err) {
      setError(normalizeApiError(err).message);
    } finally {
      setIsSearching(false);
    }
  }

  async function findMatchesFromLostReport(lostItemId: string) {
    setError(null);
    setResults(null);
    setSearchingLostItemId(lostItemId);

    try {
      const matches = await lostItemService.findMatches(lostItemId);
      queryClient.invalidateQueries({ queryKey: ["matches"] });
      queryClient.invalidateQueries({ queryKey: ["lost-items"] });
      setResults(
        matches.map((match) => ({
          id: match.id,
          item: match.foundItem,
          confidence: match.overallConfidence,
          scores: match.scores,
        })),
      );
    } catch (err) {
      setError(normalizeApiError(err).message);
    } finally {
      setSearchingLostItemId(null);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Search" }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">Search items</h1>
        <p className="mt-1 text-sm text-text-muted">
          Search found items by details, or use a lost report you already submitted.
        </p>
      </div>

      <div className="flex w-fit rounded-md border border-border bg-surface p-1">
        <button
          type="button"
          onClick={() => setMode("details")}
          className={mode === "details" ? "rounded-sm bg-navy-900 px-3 py-1.5 text-sm font-medium text-white" : "rounded-sm px-3 py-1.5 text-sm font-medium text-text-muted hover:text-text"}
        >
          <Search className="mr-1.5 inline h-4 w-4" aria-hidden="true" />
          Details
        </button>
        <button
          type="button"
          onClick={() => setMode("photo")}
          className={mode === "photo" ? "rounded-sm bg-navy-900 px-3 py-1.5 text-sm font-medium text-white" : "rounded-sm px-3 py-1.5 text-sm font-medium text-text-muted hover:text-text"}
        >
          <Camera className="mr-1.5 inline h-4 w-4" aria-hidden="true" />
          Photo
        </button>
      </div>

      {mode === "details" ? (
        <form onSubmit={handleSearch} className="flex max-w-2xl flex-col gap-5 rounded-lg border border-border bg-surface p-4">
          <Input
            label="Item name or description"
            placeholder="e.g. black wallet, blue backpack, Dell laptop"
            value={filters.query ?? ""}
            onChange={updateFilter("query")}
          />

          <div className="grid gap-4 sm:grid-cols-3">
            <Select
              label="Category"
              placeholder="Any category"
              options={ITEM_CATEGORIES.map((category) => ({ value: category.value, label: category.label }))}
              value={filters.category ?? ""}
              onChange={updateFilter("category")}
            />
            <Input label="Colour" placeholder="e.g. black" value={filters.colour ?? ""} onChange={updateFilter("colour")} />
            <Input
              label="Location"
              placeholder="e.g. library"
              value={filters.location ?? ""}
              onChange={updateFilter("location")}
            />
          </div>

          {error && <Alert tone="error" title="Couldn't search">{error}</Alert>}

          <div className="flex flex-wrap gap-3">
            <Button type="submit" isLoading={isSearching}>
              <Search className="h-4 w-4" aria-hidden="true" />
              Search
            </Button>
            <Button type="button" variant="outline" onClick={resetSearch}>
              <RotateCcw className="h-4 w-4" aria-hidden="true" />
              Reset
            </Button>
          </div>
        </form>
      ) : (
        <div className="flex max-w-3xl flex-col gap-4">
          {error && <Alert tone="error" title="Couldn't search">{error}</Alert>}

          {lostReports.isLoading ? (
            <LoadingState rows={3} label="Loading your lost reports" />
          ) : lostReports.isError ? (
            <Alert tone="error" title="Couldn't load your lost reports">
              Refresh the page and try again.
            </Alert>
          ) : lostReports.data && lostReports.data.length > 0 ? (
            <div className="grid gap-3 sm:grid-cols-2">
              {lostReports.data.map((item) => (
                <div key={item.id} className="rounded-lg border border-border bg-surface p-4">
                  <div className="flex gap-4">
                    <div className="h-20 w-20 shrink-0 overflow-hidden rounded-md bg-surface-sunken">
                      {item.photo ? (
                        <img
                          src={item.photo.thumbnailUrl ?? item.photo.url}
                          alt=""
                          className="h-full w-full object-cover"
                        />
                      ) : (
                        <div className="flex h-full items-center justify-center text-xs text-text-faint">No photo</div>
                      )}
                    </div>
                    <div className="min-w-0 flex-1">
                      <h2 className="text-sm font-medium text-text">{item.title}</h2>
                      <p className="mt-1 text-xs text-text-muted">
                        {formatLocation(item)} - {formatDate(item.lostAt)}
                      </p>
                      <p className="mt-2 line-clamp-2 text-sm text-text-muted">{item.description}</p>
                    </div>
                  </div>
                  <Button
                    type="button"
                    className="mt-4 w-full"
                    onClick={() => findMatchesFromLostReport(item.id)}
                    isLoading={searchingLostItemId === item.id}
                  >
                    <Camera className="h-4 w-4" aria-hidden="true" />
                    Search with this report
                  </Button>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState
              title="No lost reports yet"
              description="Report your lost item first, then use its saved photo and description for matching."
              action={
                <Link to="/lost-items/report" className="text-sm font-medium text-navy-700 hover:underline">
                  Report lost item
                </Link>
              }
            />
          )}
        </div>
      )}

      {isSearching ? (
        <LoadingState rows={4} label="Searching found items" />
      ) : searchingLostItemId ? (
        <LoadingState rows={4} label="Checking matches from your lost report" />
      ) : results && results.length > 0 ? (
        <div className="grid gap-3 sm:grid-cols-2">
          {results.map((result) => (
            <div key={result.id} className="rounded-lg border border-border bg-surface p-4">
              <Link to={`/found-items/${result.item.id}`} className="flex gap-4 hover:opacity-90">
                <div className="h-20 w-20 shrink-0 overflow-hidden rounded-md bg-surface-sunken">
                  {result.item.photo ? (
                    <img
                      src={result.item.photo.thumbnailUrl ?? result.item.photo.url}
                      alt=""
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    <div className="flex h-full items-center justify-center text-xs text-text-faint">No photo</div>
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-start justify-between gap-2">
                    <h2 className="text-sm font-medium text-text">{result.item.title}</h2>
                    {result.confidence && (
                      <span className="shrink-0 rounded-sm bg-info-bg px-2 py-0.5 text-xs font-medium text-info">
                        {result.confidence === "likely" ? "Likely" : "Possible"}
                      </span>
                    )}
                  </div>
                  <p className="mt-1 text-xs text-text-muted">
                    {formatLocation(result.item)} - {formatDate(result.item.foundAt)}
                  </p>
                  <p className="mt-2 line-clamp-2 text-sm text-text-muted">{result.item.description}</p>
                </div>
              </Link>

              {result.scores && (
                <div className="mt-4">
                  <MatchScoreCard scores={result.scores} confidence={result.confidence ?? "possible"} />
                </div>
              )}
            </div>
          ))}
        </div>
      ) : results ? (
        <EmptyState title="No matching items" description="Try fewer details or a clearer photo." />
      ) : (
        <EmptyState title="Start a search" description="Use the form above to check reported found items." />
      )}
    </div>
  );
}
