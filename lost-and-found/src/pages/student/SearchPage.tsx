import { useState, type FormEvent } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Camera, ChevronDown, ClipboardList, RotateCcw, Search } from "lucide-react";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { Alert } from "@/components/ui/Alert";
import { ImageUploader } from "@/components/ui/ImageUploader";
import { MatchScoreCard } from "@/components/common/MatchScore";
import { cn } from "@/lib/cn";
import { formatDate, formatLocation } from "@/lib/format";
import { normalizeApiError } from "@/lib/apiClient";
import { ITEM_CATEGORIES, type FoundItemReport, type MatchScoreBreakdown } from "@/types";
import { searchService, type SearchFilters } from "@/features/search/searchService";
import { lostItemService } from "@/features/lost-items/lostItemService";

type SearchMode = "details" | "photo" | "report";

interface SearchResult {
  id: string;
  item: FoundItemReport;
  confidence?: "possible" | "likely";
  scores?: MatchScoreBreakdown;
}

const emptyFilters: Required<SearchFilters> = {
  query: "",
  category: "",
  colour: "",
  location: "",
  dateFrom: "",
  dateTo: "",
};

const categoryOptions = [
  { value: "", label: "Any category" },
  ...ITEM_CATEGORIES.map((category) => ({ value: category.value, label: category.label })),
];

/** Drops blank fields, so only what the student filled in is sent. */
function cleanFilters(filters: SearchFilters): SearchFilters {
  return Object.fromEntries(
    Object.entries(filters)
      .map(([key, value]) => [key, typeof value === "string" ? value.trim() : value])
      .filter(([, value]) => value),
  ) as SearchFilters;
}

export function SearchPage() {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<SearchMode>("details");

  // What is typed, and what was last searched for. Starting with an
  // empty search means the page opens on the latest found items.
  const [filters, setFilters] = useState(emptyFilters);
  const [submitted, setSubmitted] = useState<SearchFilters>({});
  const [showMoreFilters, setShowMoreFilters] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const [reportResults, setReportResults] = useState<SearchResult[] | null>(null);
  const [searchingLostItemId, setSearchingLostItemId] = useState<string | null>(null);
  const [reportError, setReportError] = useState<string | null>(null);

  // Search by photo: the image is compared with found-item photos by the
  // AI service and is not stored.
  const [photo, setPhoto] = useState<File | null>(null);
  const [photoCategory, setPhotoCategory] = useState("");
  const [photoResults, setPhotoResults] = useState<SearchResult[] | null>(null);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [isPhotoSearching, setIsPhotoSearching] = useState(false);

  const search = useQuery({
    queryKey: ["search", submitted],
    queryFn: () => searchService.search(submitted),
    enabled: mode === "details",
  });

  const lostReports = useQuery({
    queryKey: ["lost-items", "mine"],
    queryFn: lostItemService.mine,
    enabled: mode === "report",
  });

  const hasSearchTerms = Object.keys(submitted).length > 0;
  const moreFiltersActive = Boolean(filters.colour || filters.location || filters.dateFrom || filters.dateTo);

  function updateFilter(field: keyof SearchFilters) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
      const value = e.target.value;
      setFilters((current) => ({ ...current, [field]: value }));
    };
  }

  function handleSearch(e: FormEvent) {
    e.preventDefault();
    setFormError(null);

    if (filters.dateFrom && filters.dateTo && filters.dateFrom > filters.dateTo) {
      setFormError("The 'to' date must be on or after the 'from' date.");
      setShowMoreFilters(true);
      return;
    }

    // Nothing filled in is fine: it lists the latest found items.
    const next = cleanFilters(filters);
    if (JSON.stringify(next) === JSON.stringify(submitted)) {
      void search.refetch();
    } else {
      setSubmitted(next);
    }
  }

  function resetSearch() {
    setFilters(emptyFilters);
    setSubmitted({});
    setFormError(null);
  }

  async function handlePhotoSearch(e: FormEvent) {
    e.preventDefault();
    if (!photo) {
      setPhotoError("Choose a photo to search with.");
      return;
    }

    setPhotoError(null);
    setPhotoResults(null);
    setIsPhotoSearching(true);

    try {
      const results = await searchService.searchByImage(photo, cleanFilters({ category: photoCategory }));
      setPhotoResults(
        results.map((result) => ({
          id: result.id,
          item: result.matchedItem,
          confidence: result.overallConfidence,
          scores: result.scores,
        })),
      );
    } catch (err) {
      setPhotoError(normalizeApiError(err).message);
    } finally {
      setIsPhotoSearching(false);
    }
  }

  async function findMatchesFromLostReport(lostItemId: string) {
    setReportError(null);
    setReportResults(null);
    setSearchingLostItemId(lostItemId);

    try {
      const matches = await lostItemService.findMatches(lostItemId);
      queryClient.invalidateQueries({ queryKey: ["matches"] });
      queryClient.invalidateQueries({ queryKey: ["lost-items"] });
      setReportResults(
        matches.map((match) => ({
          id: match.id,
          item: match.foundItem,
          confidence: match.overallConfidence,
          scores: match.scores,
        })),
      );
    } catch (err) {
      setReportError(normalizeApiError(err).message);
    } finally {
      setSearchingLostItemId(null);
    }
  }

  const tabClass = (active: boolean) =>
    active
      ? "rounded-sm bg-navy-900 px-3 py-1.5 text-sm font-medium text-white"
      : "rounded-sm px-3 py-1.5 text-sm font-medium text-text-muted hover:text-text";

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Search" }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">Search found items</h1>
        <p className="mt-1 text-sm text-text-muted">
          Look through items handed in at the lost &amp; found office.
        </p>
      </div>

      <div className="flex w-fit rounded-md border border-border bg-surface p-1" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={mode === "details"}
          onClick={() => setMode("details")}
          className={tabClass(mode === "details")}
        >
          <Search className="mr-1.5 inline h-4 w-4" aria-hidden="true" />
          Search
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={mode === "photo"}
          onClick={() => setMode("photo")}
          className={tabClass(mode === "photo")}
        >
          <Camera className="mr-1.5 inline h-4 w-4" aria-hidden="true" />
          Search by photo
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={mode === "report"}
          onClick={() => setMode("report")}
          className={tabClass(mode === "report")}
        >
          <ClipboardList className="mr-1.5 inline h-4 w-4" aria-hidden="true" />
          Matches from my lost report
        </button>
      </div>

      {mode === "details" ? (
        <>
          <form onSubmit={handleSearch} className="flex max-w-2xl flex-col gap-4 rounded-lg border border-border bg-surface p-4">
            <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
              <Input
                label="What are you looking for?"
                placeholder="black wallet, blue backpack, Dell laptop"
                className="h-12 text-base"
                value={filters.query}
                onChange={updateFilter("query")}
                hint="Everything is optional. Search with nothing filled in to see the latest items handed in."
                autoFocus
              />
              <Select
                label="Category"
                className="h-12"
                options={categoryOptions}
                value={filters.category}
                onChange={updateFilter("category")}
              />
            </div>

            <div>
              <button
                type="button"
                onClick={() => setShowMoreFilters((open) => !open)}
                aria-expanded={showMoreFilters}
                aria-controls="search-more-filters"
                className="flex items-center gap-1 text-sm font-medium text-navy-700 hover:underline"
              >
                <ChevronDown
                  className={cn("h-4 w-4 transition-transform", showMoreFilters && "rotate-180")}
                  aria-hidden="true"
                />
                More filters
                {moreFiltersActive && !showMoreFilters && <span className="text-text-muted">(in use)</span>}
              </button>

              {showMoreFilters && (
                <div id="search-more-filters" className="mt-3 grid gap-4 sm:grid-cols-2">
                  <Input label="Colour" placeholder="e.g. black" value={filters.colour} onChange={updateFilter("colour")} />
                  <Input
                    label="Where it was found"
                    placeholder="e.g. library"
                    value={filters.location}
                    onChange={updateFilter("location")}
                  />
                  <Input label="Found from" type="date" value={filters.dateFrom} onChange={updateFilter("dateFrom")} />
                  <Input
                    label="Found to"
                    type="date"
                    value={filters.dateTo}
                    min={filters.dateFrom || undefined}
                    onChange={updateFilter("dateTo")}
                  />
                </div>
              )}
            </div>

            {formError && <Alert tone="error" title="Check your filters">{formError}</Alert>}

            <div className="flex flex-wrap gap-3">
              <Button type="submit" isLoading={search.isLoading}>
                <Search className="h-4 w-4" aria-hidden="true" />
                Search
              </Button>
              <Button type="button" variant="outline" onClick={resetSearch}>
                <RotateCcw className="h-4 w-4" aria-hidden="true" />
                Clear
              </Button>
            </div>
          </form>

          <section className="flex flex-col gap-3" aria-live="polite">
            {search.isLoading ? (
              <LoadingState rows={4} label="Searching found items" />
            ) : search.isError ? (
              <Alert tone="error" title="Couldn't search">
                {normalizeApiError(search.error).message}
              </Alert>
            ) : search.data && search.data.length > 0 ? (
              <>
                <h2 className="text-sm font-medium text-text-muted">
                  {hasSearchTerms
                    ? `${search.data.length} item${search.data.length === 1 ? "" : "s"} found`
                    : "Latest items handed in"}
                </h2>
                <ResultGrid results={search.data.map((item) => ({ id: item.id, item }))} />
              </>
            ) : hasSearchTerms ? (
              <EmptyState
                title="No matching items"
                description="Try fewer words, or clear the filters. New items are added as they're handed in."
              />
            ) : (
              <EmptyState title="No found items yet" description="Items handed in at the office will appear here." />
            )}
          </section>
        </>
      ) : mode === "photo" ? (
        <>
          <form
            onSubmit={handlePhotoSearch}
            className="flex max-w-2xl flex-col gap-4 rounded-lg border border-border bg-surface p-4"
          >
            <p className="text-sm text-text-muted">
              Upload a photo of the item you lost (or one like it). We'll show found items that look similar. Your photo
              is only used for this search and isn't saved.
            </p>

            <ImageUploader
              label="Photo of your item"
              value={photo}
              onChange={(file) => {
                setPhoto(file);
                setPhotoError(null);
              }}
            />

            <Select
              label="Category (optional)"
              className="sm:w-64"
              options={categoryOptions}
              value={photoCategory}
              onChange={(e) => setPhotoCategory(e.target.value)}
            />

            {photoError && <Alert tone="error" title="Couldn't search by photo">{photoError}</Alert>}

            <Button type="submit" isLoading={isPhotoSearching} disabled={!photo} className="w-fit">
              <Camera className="h-4 w-4" aria-hidden="true" />
              Search with this photo
            </Button>
          </form>

          <section className="flex flex-col gap-3" aria-live="polite">
            {isPhotoSearching ? (
              <LoadingState rows={4} label="Comparing your photo with found items" />
            ) : photoResults && photoResults.length > 0 ? (
              <>
                <h2 className="text-sm font-medium text-text-muted">
                  {photoResults.length} similar item{photoResults.length === 1 ? "" : "s"} — these are suggestions, so
                  check the details before claiming.
                </h2>
                <ResultGrid results={photoResults} />
              </>
            ) : photoResults ? (
              <EmptyState
                title="Nothing similar yet"
                description="No found item looks like your photo. Try a keyword search, or report it lost so we can notify you."
              />
            ) : null}
          </section>
        </>
      ) : (
        <>
          <div className="flex max-w-3xl flex-col gap-4">
            <p className="text-sm text-text-muted">
              Already reported something lost? Check it against everything handed in, using its description and photo.
            </p>

            {reportError && <Alert tone="error" title="Couldn't check for matches">{reportError}</Alert>}

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
                      <Thumbnail photo={item.photo} />
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
                      <Search className="h-4 w-4" aria-hidden="true" />
                      Check for matches
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

          {searchingLostItemId ? (
            <LoadingState rows={4} label="Checking matches from your lost report" />
          ) : reportResults && reportResults.length > 0 ? (
            <ResultGrid results={reportResults} />
          ) : reportResults ? (
            <EmptyState
              title="No matches yet"
              description="Nothing handed in looks like this yet. We'll notify you if a possible match comes in."
            />
          ) : null}
        </>
      )}
    </div>
  );
}

function Thumbnail({ photo }: { photo?: FoundItemReport["photo"] }) {
  return (
    <div className="h-20 w-20 shrink-0 overflow-hidden rounded-md bg-surface-sunken">
      {photo ? (
        <img src={photo.thumbnailUrl ?? photo.url} alt="" className="h-full w-full object-cover" />
      ) : (
        <div className="flex h-full items-center justify-center text-xs text-text-faint">No photo</div>
      )}
    </div>
  );
}

function ResultGrid({ results }: { results: SearchResult[] }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {results.map((result) => (
        <div key={result.id} className="rounded-lg border border-border bg-surface p-4">
          <Link to={`/found-items/${result.item.id}`} className="flex gap-4 hover:opacity-90">
            <Thumbnail photo={result.item.photo} />
            <div className="min-w-0 flex-1">
              <div className="flex items-start justify-between gap-2">
                <h3 className="text-sm font-medium text-text">{result.item.title}</h3>
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
  );
}
