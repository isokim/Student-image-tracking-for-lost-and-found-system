import { useState, type ReactNode } from "react";
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
import { formatDate, formatDateTime, formatLocation } from "@/lib/format";
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

  const linkedReports = claim.lostItem ? [claim.lostItem] : (claim.claimantLostReports ?? []);
  const finder = claim.finder ?? claim.foundItem.reportedBy;

  return (
    <div className="flex max-w-5xl flex-col gap-6">
      <Breadcrumbs items={[{ label: "Claims", href: claimsPath }, { label: claim.foundItem.title }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">{claim.foundItem.title}</h1>
        <p className="mt-1 text-sm text-text-muted">
          Claimed by {claim.claimant.name} · Submitted {formatDateTime(claim.createdAt)}
        </p>
      </div>

      <ClaimStatus status={claim.status} />

      {/*
        Two columns: everything the claimant has told us on the left,
        everything the finder recorded on the right, so staff can check
        one against the other before deciding who the owner is.
      */}
      <div className="grid gap-4 md:grid-cols-2">
        {/* ---------------- Claimant ---------------- */}
        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
          <div>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-text-muted">Claimant · says it's theirs</h2>
            <Person
              name={claim.claimant.name}
              email={claim.claimant.email}
              admissionNumber={claim.claimant.admissionNumber}
            />
          </div>

          <Field label="Details that prove it's theirs">{claim.identifyingInformation}</Field>

          {(claim.verificationAnswers ?? []).length === 0 ? (
            <Field label="Where and when they lost it">
              <span className="text-text-faint">Not given (these questions are optional).</span>
            </Field>
          ) : (
            (claim.verificationAnswers ?? []).map((qa) => (
              <Field key={qa.question} label={qa.question}>
                {qa.answer}
              </Field>
            ))
          )}

          <div className="flex flex-col gap-3">
            <h3 className="text-sm font-semibold text-text">
              {claim.lostItem
                ? "Their lost report (linked to this claim)"
                : linkedReports.length > 0
                  ? "Their recent lost reports (not linked — check if one is this item)"
                  : "Their lost report"}
            </h3>
            {linkedReports.length === 0 ? (
              <p className="text-sm text-text-faint">
                {claim.claimantLostReports
                  ? "This student hasn't reported anything lost."
                  : "No lost report is linked to this claim."}
              </p>
            ) : (
              linkedReports.map((report) => (
                <ItemSummary
                  key={report.id}
                  title={report.title}
                  photoUrl={report.photo?.url}
                  category={report.category}
                  colour={report.colour}
                  description={report.description}
                  whereLabel="Where they last had it"
                  where={formatLocation(report)}
                  whenLabel="Date lost"
                  when={formatDate(report.lostAt)}
                  extraLabel="Details that prove it's theirs"
                  extra={report.additionalInfo}
                />
              ))
            )}
          </div>
        </section>

        {/* ---------------- Finder ---------------- */}
        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
          <div>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Finder · handed it in{finder.role !== "student" ? ` (${finder.role})` : ""}
            </h2>
            <Person name={finder.name} email={claim.finder?.email} admissionNumber={claim.finder?.admissionNumber} />
          </div>

          <div className="rounded-md border border-warning/30 bg-warning-bg p-3">
            <p className="text-sm font-medium text-warning">Hidden details only the owner would know</p>
            <p className="mt-1 text-sm text-text">
              {claim.foundItem.privateVerificationInfo || "The finder didn't record any hidden details."}
            </p>
          </div>

          <ItemSummary
            title={claim.foundItem.title}
            photoUrl={claim.foundItem.photo?.url}
            category={claim.foundItem.category}
            colour={claim.foundItem.colour}
            description={claim.foundItem.description}
            whereLabel="Where it was found"
            where={formatLocation(claim.foundItem)}
            whenLabel="Date found"
            when={formatDate(claim.foundItem.foundAt)}
            extraLabel="Other notes from the finder"
            extra={claim.foundItem.additionalInfo}
          />
        </section>
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

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <p className="text-sm text-text-muted">{label}</p>
      <p className="text-sm text-text">{children}</p>
    </div>
  );
}

function Person({ name, email, admissionNumber }: { name: string; email?: string; admissionNumber?: string | null }) {
  return (
    <div className="mt-1">
      <p className="font-medium text-text">{name}</p>
      {(email || admissionNumber) && (
        <p className="text-sm text-text-muted">
          {[email, admissionNumber && `Adm. ${admissionNumber}`].filter(Boolean).join(" · ")}
        </p>
      )}
    </div>
  );
}

function ItemSummary(props: {
  title: string;
  photoUrl?: string;
  category?: string;
  colour?: string;
  description: string;
  whereLabel: string;
  where: string;
  whenLabel: string;
  when: string;
  extraLabel: string;
  extra?: string;
}) {
  return (
    <div className="flex flex-col gap-3 rounded-md border border-border p-3">
      {props.photoUrl ? (
        <div className="aspect-video overflow-hidden rounded-md bg-surface-sunken">
          <img src={props.photoUrl} alt="" className="h-full w-full object-cover" />
        </div>
      ) : (
        <p className="text-xs text-text-faint">No photo</p>
      )}
      <p className="font-medium text-text">{props.title}</p>
      <dl className="grid grid-cols-2 gap-2 text-sm">
        <div>
          <dt className="text-text-muted">Category</dt>
          <dd className="capitalize text-text">{props.category ?? "—"}</dd>
        </div>
        <div>
          <dt className="text-text-muted">Colour</dt>
          <dd className="text-text">{props.colour || "—"}</dd>
        </div>
        <div>
          <dt className="text-text-muted">{props.whereLabel}</dt>
          <dd className="text-text">{props.where}</dd>
        </div>
        <div>
          <dt className="text-text-muted">{props.whenLabel}</dt>
          <dd className="text-text">{props.when}</dd>
        </div>
      </dl>
      <Field label="Description">{props.description}</Field>
      {props.extra && <Field label={props.extraLabel}>{props.extra}</Field>}
    </div>
  );
}
