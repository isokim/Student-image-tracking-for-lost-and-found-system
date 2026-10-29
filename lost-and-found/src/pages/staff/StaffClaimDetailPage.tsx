import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useParams } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState, NotFoundState } from "@/components/ui/ErrorState";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { useToast } from "@/components/ui/Toast";
import { formatDateTime } from "@/lib/format";
import { claimService } from "@/features/claims/claimService";
import { useAuth } from "@/features/auth/AuthContext";

export function StaffClaimDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [showApprove, setShowApprove] = useState(false);
  const [showReject, setShowReject] = useState(false);
  const [rejectNotes, setRejectNotes] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const { data: claim, isLoading, isError, refetch } = useQuery({
    queryKey: ["claims", id],
    queryFn: () => claimService.get(id as string),
    enabled: !!id,
  });

  if (isLoading) return <LoadingState rows={4} />;
  if (isError) return <ErrorState onRetry={refetch} description="Couldn't load this claim right now." />;
  if (!claim) return <NotFoundState resource="claim" />;

  const claimsPath = user?.role === "admin" ? "/admin/claims" : "/staff/claims";

  async function refreshAndToast(title: string) {
    queryClient.invalidateQueries({ queryKey: ["claims"] });
    show({ tone: "success", title });
  }

  async function handleApprove() {
    setIsSubmitting(true);
    try {
      await claimService.approve(claim!.id);
      await refreshAndToast("Claim approved");
      navigate(claimsPath);
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleReject() {
    setIsSubmitting(true);
    try {
      await claimService.reject(claim!.id, rejectNotes);
      await refreshAndToast("Claim rejected");
      navigate(claimsPath);
    } finally {
      setIsSubmitting(false);
      setShowReject(false);
    }
  }

  async function handleMarkReturned() {
    setIsSubmitting(true);
    try {
      await claimService.markReturned(claim!.id);
      await refreshAndToast("Item marked as returned");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex max-w-xl flex-col gap-6">
      <Breadcrumbs items={[{ label: "Claims", href: claimsPath }, { label: claim.foundItem.title }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">{claim.foundItem.title}</h1>
        <p className="mt-1 text-sm text-text-muted">
          Claimed by {claim.claimant.name} · Submitted {formatDateTime(claim.createdAt)}
        </p>
      </div>

      <ClaimStatus status={claim.status} />

      <div className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text">Identifying information</h2>
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

      {claim.status === "pending_review" && (
        <div className="flex gap-3">
          <Button onClick={() => setShowApprove(true)}>Approve claim</Button>
          <Button variant="danger" onClick={() => setShowReject(true)}>
            Reject claim
          </Button>
        </div>
      )}

      {claim.status === "approved" && (
        <Button onClick={handleMarkReturned} isLoading={isSubmitting} className="w-fit">
          Mark item as returned
        </Button>
      )}

      <ConfirmationDialog
        isOpen={showApprove}
        onClose={() => setShowApprove(false)}
        onConfirm={handleApprove}
        title="Approve this claim?"
        description="The student will be notified they can collect the item from the office."
        confirmLabel="Approve"
        isLoading={isSubmitting}
      />

      <Modal
        isOpen={showReject}
        onClose={() => setShowReject(false)}
        title="Reject this claim?"
        description="Add a short reason — the claimant will see this."
        size="sm"
        footer={
          <>
            <Button variant="outline" onClick={() => setShowReject(false)} disabled={isSubmitting}>
              Cancel
            </Button>
            <Button variant="danger" onClick={handleReject} isLoading={isSubmitting} disabled={!rejectNotes.trim()}>
              Reject claim
            </Button>
          </>
        }
      >
        <Textarea label="Reason" required value={rejectNotes} onChange={(e) => setRejectNotes(e.target.value)} />
      </Modal>
    </div>
  );
}
