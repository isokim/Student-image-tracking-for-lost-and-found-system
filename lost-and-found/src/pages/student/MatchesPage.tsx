import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { formatDate, formatLocation } from "@/lib/format";
import { matchService } from "@/features/matches/matchService";

export function MatchesPage() {
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ["matches"], queryFn: matchService.list });

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Matches" }]} />
      <h1 className="text-xl font-semibold text-text">Possible matches</h1>

      {isLoading ? (
        <LoadingState rows={4} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load matches right now." />
      ) : data && data.length > 0 ? (
        <ul className="flex flex-col gap-2">
          {data.map((match) => (
            <li key={match.id}>
              <Link
                to={`/matches/${match.id}`}
                className="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4 hover:border-border-strong"
              >
                <div>
                  <p className="text-sm font-medium text-text">{match.lostItem.title}</p>
                  <p className="mt-0.5 text-xs text-text-muted">
                    Found at {formatLocation(match.foundItem)} · {formatDate(match.foundItem.foundAt)}
                  </p>
                </div>
                <span className="rounded-sm bg-info-bg px-2 py-0.5 text-xs font-medium text-info">
                  {match.overallConfidence === "likely" ? "Likely match" : "Possible match"}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState
          title="No possible matches yet"
          description="We'll let you know as soon as a found item looks like something you've reported lost."
        />
      )}
    </div>
  );
}
