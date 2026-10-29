import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { MatchScoreCard } from "@/components/common/MatchScore";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState, NotFoundState } from "@/components/ui/ErrorState";
import { Button, buttonStyles } from "@/components/ui/Button";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { formatDate, formatLocation } from "@/lib/format";
import { matchService } from "@/features/matches/matchService";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/features/auth/AuthContext";

export function MatchDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [showDismiss, setShowDismiss] = useState(false);
  const [isDismissing, setIsDismissing] = useState(false);

  const { data: match, isLoading, isError, refetch } = useQuery({
    queryKey: ["matches", id],
    queryFn: () => matchService.get(id as string),
    enabled: !!id,
  });

  if (isLoading) return <LoadingState rows={4} />;
  if (isError) return <ErrorState onRetry={refetch} description="Couldn't load this match right now." />;
  if (!match) return <NotFoundState resource="match" />;

  const isStudent = user?.role === "student";
  const matchesPath = user?.role === "admin" ? "/admin/matches" : "/matches";

  async function handleDismiss() {
    setIsDismissing(true);
    try {
      await matchService.dismiss(match!.id);
      queryClient.invalidateQueries({ queryKey: ["matches"] });
      navigate(matchesPath);
    } finally {
      setIsDismissing(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Matches", href: matchesPath }, { label: match.lostItem.title }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">{match.lostItem.title}</h1>
        <p className="mt-1 text-sm text-text-muted">
          A found item at {formatLocation(match.foundItem)} ({formatDate(match.foundItem.foundAt)}) may be a match.
        </p>
      </div>

      <div className="grid gap-6 sm:grid-cols-2">
        <div className="rounded-lg border border-border bg-surface p-4">
          <h2 className="text-sm font-semibold text-text">Your lost report</h2>
          <p className="mt-2 text-sm text-text">{match.lostItem.description}</p>
        </div>
        <div className="rounded-lg border border-border bg-surface p-4">
          <h2 className="text-sm font-semibold text-text">Found item</h2>
          <p className="mt-2 text-sm text-text">{match.foundItem.description}</p>
        </div>
      </div>

      <MatchScoreCard scores={match.scores} confidence={match.overallConfidence} />

      {isStudent && (
        <div className="flex gap-3">
          <Link
            to={`/claims/new?matchId=${match.id}&foundItemId=${match.foundItem.id}`}
            className={buttonStyles("primary", "md")}
          >
            This looks like mine - start a claim
          </Link>
          <Button variant="outline" onClick={() => setShowDismiss(true)}>
            Not a match
          </Button>
        </div>
      )}

      <ConfirmationDialog
        isOpen={showDismiss}
        onClose={() => setShowDismiss(false)}
        onConfirm={handleDismiss}
        title="Report this as not a match?"
        description="We'll stop suggesting this found item for your report and keep looking for other matches."
        confirmLabel="Not a match"
        isLoading={isDismissing}
      />
    </div>
  );
}
