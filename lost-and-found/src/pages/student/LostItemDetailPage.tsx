import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { MapPin, CalendarClock, Search, Tag } from "lucide-react";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { LostItemStatusBadge } from "@/components/common/ItemStatusBadge";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState, NotFoundState } from "@/components/ui/ErrorState";
import { formatDateTime, formatLocation } from "@/lib/format";
import { useAuth } from "@/features/auth/AuthContext";
import { lostItemService } from "@/features/lost-items/lostItemService";
import { normalizeApiError } from "@/lib/apiClient";

export function LostItemDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const [isFindingMatches, setIsFindingMatches] = useState(false);
  const [matchMessage, setMatchMessage] = useState<string | null>(null);
  const [matchError, setMatchError] = useState<string | null>(null);
  const { data: item, isLoading, isError, refetch } = useQuery({
    queryKey: ["lost-items", id],
    queryFn: () => lostItemService.get(id as string),
    enabled: !!id,
  });

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <LoadingState rows={4} />
      </div>
    );
  }

  if (isError) {
    return <ErrorState onRetry={refetch} description="Couldn't load this report right now." />;
  }

  if (!item) {
    return <NotFoundState resource="report" />;
  }

  const isOwner = user?.id === item.reportedBy.id;
  const canFindMatches = isOwner && (item.status === "active" || item.status === "matched");
  const lostItemsPath = user?.role === "admin" ? "/admin/lost-items" : "/lost-items";
  const matchesPath = user?.role === "admin" ? "/admin/matches" : "/matches";

  async function handleFindMatches() {
    if (!item) return;

    setIsFindingMatches(true);
    setMatchMessage(null);
    setMatchError(null);

    try {
      const matches = await lostItemService.findMatches(item.id);
      setMatchMessage(
        matches.length > 0
          ? `Found ${matches.length} possible match${matches.length === 1 ? "" : "es"} from existing found reports.`
          : "No matching found reports yet. The system will keep checking as new found items are reported.",
      );
      await refetch();
    } catch (err) {
      setMatchError(normalizeApiError(err).message);
    } finally {
      setIsFindingMatches(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Lost items", href: lostItemsPath }, { label: item.title }]} />

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-text">{item.title}</h1>
          <p className="mt-1 text-sm text-text-muted">Reported by {item.reportedBy.name}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <LostItemStatusBadge status={item.status} />
          {canFindMatches && (
            <Button variant="outline" size="sm" onClick={handleFindMatches} isLoading={isFindingMatches}>
              <Search className="h-4 w-4" aria-hidden="true" />
              Find matches
            </Button>
          )}
        </div>
      </div>

      {matchMessage && <Alert tone="success" title="Match check complete">{matchMessage}</Alert>}
      {matchError && <Alert tone="error" title="Couldn't check matches">{matchError}</Alert>}

      <div className="grid gap-6 sm:grid-cols-[200px_1fr]">
        <div className="h-40 w-full overflow-hidden rounded-lg bg-surface-sunken sm:h-full">
          {item.photo ? (
            <img src={item.photo.url} alt="" className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full items-center justify-center text-sm text-text-faint">No photo</div>
          )}
        </div>

        <div className="flex flex-col gap-4">
          <p className="text-sm text-text">{item.description}</p>

          <dl className="grid grid-cols-2 gap-4 text-sm">
            {item.colour && (
              <div>
                <dt className="text-text-muted">Colour</dt>
                <dd className="text-text">{item.colour}</dd>
              </div>
            )}
            <div className="flex items-start gap-1.5">
              <MapPin className="mt-0.5 h-3.5 w-3.5 text-text-faint" aria-hidden="true" />
              <div>
                <dt className="text-text-muted">Location lost</dt>
                <dd className="text-text">{formatLocation(item)}</dd>
              </div>
            </div>
            <div className="flex items-start gap-1.5">
              <CalendarClock className="mt-0.5 h-3.5 w-3.5 text-text-faint" aria-hidden="true" />
              <div>
                <dt className="text-text-muted">Date lost</dt>
                <dd className="text-text">{formatDateTime(item.lostAt)}</dd>
              </div>
            </div>
            <div className="flex items-start gap-1.5">
              <Tag className="mt-0.5 h-3.5 w-3.5 text-text-faint" aria-hidden="true" />
              <div>
                <dt className="text-text-muted">Category</dt>
                <dd className="capitalize text-text">{item.category}</dd>
              </div>
            </div>
          </dl>

          {item.status === "matched" && (
            <Link to={matchesPath} className="text-sm font-medium text-navy-700 underline underline-offset-2">
              View possible matches for this report
            </Link>
          )}
        </div>
      </div>
    </div>
  );
}
