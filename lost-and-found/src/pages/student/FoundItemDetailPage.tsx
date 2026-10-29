import { useQuery } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { MapPin, CalendarClock, Tag } from "lucide-react";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { FoundItemStatusBadge } from "@/components/common/ItemStatusBadge";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState, NotFoundState } from "@/components/ui/ErrorState";
import { buttonStyles } from "@/components/ui/Button";
import { formatDateTime, formatLocation } from "@/lib/format";
import { useAuth } from "@/features/auth/AuthContext";
import { foundItemService } from "@/features/found-items/foundItemService";

export function FoundItemDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { data: item, isLoading, isError, refetch } = useQuery({
    queryKey: ["found-items", id],
    queryFn: () => foundItemService.get(id as string),
    enabled: !!id,
  });

  if (isLoading) return <LoadingState rows={4} />;
  if (isError) return <ErrorState onRetry={refetch} description="Couldn't load this item right now." />;
  if (!item) return <NotFoundState resource="item" />;

  const isFinder = user?.id === item.reportedBy.id;
  const canClaim = !isFinder && (item.status === "pending_verification" || item.status === "verified");

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Found items", href: "/found-items" }, { label: item.title }]} />

      <div className="flex flex-wrap items-start justify-between gap-3">
        <h1 className="text-xl font-semibold text-text">{item.title}</h1>
        <FoundItemStatusBadge status={item.status} />
      </div>

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
                <dt className="text-text-muted">Location found</dt>
                <dd className="text-text">{formatLocation(item)}</dd>
              </div>
            </div>
            <div className="flex items-start gap-1.5">
              <CalendarClock className="mt-0.5 h-3.5 w-3.5 text-text-faint" aria-hidden="true" />
              <div>
                <dt className="text-text-muted">Date found</dt>
                <dd className="text-text">{formatDateTime(item.foundAt)}</dd>
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

          <p className="text-xs text-text-faint">
            Additional identifying details are kept private and are only checked during claim verification.
          </p>

          {canClaim && (
            <Link to={`/claims/new?foundItemId=${item.id}`} className={buttonStyles("primary", "md") + " w-fit"}>
              Start a claim
            </Link>
          )}
        </div>
      </div>
    </div>
  );
}
