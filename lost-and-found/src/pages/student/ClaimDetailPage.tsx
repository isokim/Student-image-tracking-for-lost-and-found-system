import { useQuery } from "@tanstack/react-query";
import { useParams } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState, NotFoundState } from "@/components/ui/ErrorState";
import { Alert } from "@/components/ui/Alert";
import { formatDateTime } from "@/lib/format";
import { claimService } from "@/features/claims/claimService";

export function ClaimDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: claim, isLoading, isError, refetch } = useQuery({
    queryKey: ["claims", id],
    queryFn: () => claimService.get(id as string),
    enabled: !!id,
  });

  if (isLoading) return <LoadingState rows={4} />;
  if (isError) return <ErrorState onRetry={refetch} description="Couldn't load this claim right now." />;
  if (!claim) return <NotFoundState resource="claim" />;

  return (
    <div className="flex max-w-xl flex-col gap-6">
      <Breadcrumbs items={[{ label: "Claims", href: "/claims" }, { label: claim.foundItem.title }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">{claim.foundItem.title}</h1>
        <p className="mt-1 text-sm text-text-muted">Submitted {formatDateTime(claim.createdAt)}</p>
      </div>

      <ClaimStatus status={claim.status} />

      {claim.status === "rejected" && claim.reviewNotes && (
        <Alert tone="error" title="Claim rejected">
          {claim.reviewNotes}
        </Alert>
      )}

      {claim.status === "approved" && (
        <Alert tone="success" title="Claim approved">
          Visit the lost &amp; found office with your student ID to collect your item.
        </Alert>
      )}

      {claim.status === "completed" && (
        <Alert tone="success" title="Item returned">
          This item was handed back to you. If that's a mistake, contact the lost &amp; found office.
        </Alert>
      )}

      <div className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text">Identifying information you provided</h2>
        <p className="mt-2 text-sm text-text">{claim.identifyingInformation}</p>
      </div>

      <div className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text">Verification answers</h2>
        <dl className="mt-2 flex flex-col gap-3">
          {claim.verificationAnswers.map((qa) => (
            <div key={qa.question}>
              <dt className="text-sm text-text-muted">{qa.question}</dt>
              <dd className="text-sm text-text">{qa.answer}</dd>
            </div>
          ))}
        </dl>
      </div>
    </div>
  );
}
