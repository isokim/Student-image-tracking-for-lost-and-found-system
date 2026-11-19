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
import { claimService } from "@/features/claims/claimService";
import { normalizeApiError } from "@/lib/apiClient";
import { useToast } from "@/components/ui/Toast";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/features/auth/AuthContext";
import type { ItemMatch } from "@/types";

/**
 * The claim sent by "This is mine", built from the student's own lost
 * report so they don't type it all again. Staff see the linked report in
 * full on the review screen; this text is the summary on the claim itself.
 */
function claimFromLostReport(match: ItemMatch) {
  const report = match.lostItem;
  const identifyingInformation = [
    report.additionalInfo?.trim(),
    `Description from my lost report: ${report.description}`,
  ]
    .filter(Boolean)
    .join("\n\n")
    // The API caps this at 2000 characters; the full report is linked anyway.
    .slice(0, 2000);

  const verificationAnswers = [
    report.locationText ? { question: "Where you last had it", answer: report.locationText } : null,
    report.lostAt ? { question: "Date lost", answer: report.lostAt.slice(0, 10) } : null,
  ].filter((a): a is { question: string; answer: string } => a !== null);

  return {
    foundItemId: match.foundItem.id,
    matchId: match.id,
    lostItemId: report.id,
    identifyingInformation,
    verificationAnswers: verificationAnswers.length ? verificationAnswers : undefined,
  };
}

export function MatchDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [showDismiss, setShowDismiss] = useState(false);
  const [isDismissing, setIsDismissing] = useState(false);
  const [showClaim, setShowClaim] = useState(false);
  const [isClaiming, setIsClaiming] = useState(false);
  const { show } = useToast();

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

  const canClaim = match.status === "pending_review" && match.foundItem.status === "verified";
  const hasProofDetails = !!match.lostItem.additionalInfo?.trim();

  async function handleClaim() {
    setIsClaiming(true);
    try {
      const claim = await claimService.create(claimFromLostReport(match!));
      show({ tone: "success", title: "Claim sent to staff", description: "They'll check it and let you know." });
      navigate(`/claims/${claim.id}`);
    } catch (error) {
      show({ tone: "error", title: "Couldn't send your claim", description: normalizeApiError(error).message });
    } finally {
      setIsClaiming(false);
      setShowClaim(false);
    }
  }

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
          <h2 className="text-sm font-semibold text-text">{isStudent ? "Your lost report" : "Lost report"}</h2>
          <MatchPhoto url={match.lostItem.photo?.url} />
          <p className="mt-2 text-sm text-text">{match.lostItem.description}</p>
        </div>
        <div className="rounded-lg border border-border bg-surface p-4">
          <h2 className="text-sm font-semibold text-text">Found item</h2>
          <MatchPhoto url={match.foundItem.photo?.url} />
          <p className="mt-2 text-sm text-text">{match.foundItem.description}</p>
        </div>
      </div>

      <MatchScoreCard scores={match.scores} confidence={match.overallConfidence} />

      {isStudent && match.status === "claim_started" && (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface p-4">
          <p className="text-sm text-text">You've already sent a claim for this item. Staff will let you know.</p>
          <Link to="/claims" className={buttonStyles("outline", "sm")}>
            View my claims
          </Link>
        </div>
      )}

      {isStudent && match.status === "pending_review" && !canClaim && (
        <p className="text-sm text-text-muted">This item can't be claimed right now.</p>
      )}

      {isStudent && canClaim && (
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap gap-3">
            <Button onClick={() => setShowClaim(true)}>This is mine — send to staff</Button>
            <Button variant="outline" onClick={() => setShowDismiss(true)}>
              Not mine
            </Button>
          </div>
          <Link
            to={`/claims/new?matchId=${match.id}&foundItemId=${match.foundItem.id}`}
            className="w-fit text-sm text-text-muted underline"
          >
            Want to add more details first? Open the full claim form
          </Link>
        </div>
      )}

      <ConfirmationDialog
        isOpen={showClaim}
        onClose={() => setShowClaim(false)}
        onConfirm={handleClaim}
        title="Send a claim for this item?"
        description={
          hasProofDetails
            ? "We'll send the details from your lost report to staff. They'll compare them with what was recorded when the item was handed in."
            : "Your lost report has no \"details that prove it's yours\", so staff will only have its description to go on. You can open the full claim form instead to add more."
        }
        confirmLabel="Send claim"
        isLoading={isClaiming}
      />

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

/** Same-size frame on both sides so the two photos can be compared at a glance. */
function MatchPhoto({ url }: { url?: string }) {
  return (
    <div className="mt-3 aspect-[4/3] w-full overflow-hidden rounded-md bg-surface-sunken">
      {url ? (
        <img src={url} alt="" className="h-full w-full object-cover" />
      ) : (
        <div className="flex h-full items-center justify-center text-sm text-text-faint">No photo</div>
      )}
    </div>
  );
}
