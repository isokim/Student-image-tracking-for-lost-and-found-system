import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useParams } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { FoundItemStatusBadge } from "@/components/common/ItemStatusBadge";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState, NotFoundState } from "@/components/ui/ErrorState";
import { Button } from "@/components/ui/Button";
import { Textarea } from "@/components/ui/Textarea";
import { Modal } from "@/components/ui/Modal";
import { formatDateTime, formatLocation } from "@/lib/format";
import { foundItemService } from "@/features/found-items/foundItemService";
import { useToast } from "@/components/ui/Toast";
import { useAuth } from "@/features/auth/AuthContext";

export function StaffItemDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const { show } = useToast();
  const [rejectReason, setRejectReason] = useState("");
  const [showReject, setShowReject] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const { data: item, isLoading, isError, refetch } = useQuery({
    queryKey: ["found-items", id],
    queryFn: () => foundItemService.get(id as string),
    enabled: !!id,
  });

  if (isLoading) return <LoadingState rows={4} />;
  if (isError) return <ErrorState onRetry={refetch} description="Couldn't load this item right now." />;
  if (!item) return <NotFoundState resource="item" />;

  const foundItemsPath = user?.role === "admin" ? "/admin/found-items" : "/staff/found-items";

  async function handleVerify() {
    setIsSubmitting(true);
    try {
      await foundItemService.verify(item!.id);
      queryClient.invalidateQueries({ queryKey: ["found-items"] });
      show({ tone: "success", title: "Item verified" });
      navigate(foundItemsPath);
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleReject() {
    setIsSubmitting(true);
    try {
      await foundItemService.reject(item!.id, rejectReason);
      queryClient.invalidateQueries({ queryKey: ["found-items"] });
      show({ tone: "info", title: "Item rejected" });
      navigate(foundItemsPath);
    } finally {
      setIsSubmitting(false);
      setShowReject(false);
    }
  }

  return (
    <div className="flex max-w-xl flex-col gap-6">
      <Breadcrumbs items={[{ label: "Found items", href: foundItemsPath }, { label: item.title }]} />

      <div className="flex items-start justify-between gap-3">
        <h1 className="text-xl font-semibold text-text">{item.title}</h1>
        <FoundItemStatusBadge status={item.status} />
      </div>

      <div className="rounded-lg border border-border bg-surface p-4">
        <p className="text-sm text-text">{item.description}</p>
        <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-text-muted">Reported by</dt>
            <dd className="text-text">{item.reportedBy.name}</dd>
          </div>
          <div>
            <dt className="text-text-muted">Location</dt>
            <dd className="text-text">{formatLocation(item)}</dd>
          </div>
          <div>
            <dt className="text-text-muted">Date found</dt>
            <dd className="text-text">{formatDateTime(item.foundAt)}</dd>
          </div>
        </dl>
      </div>

      {item.privateVerificationInfo && (
        <div className="rounded-lg border border-warning/30 bg-warning-bg p-4">
          <h2 className="text-sm font-semibold text-warning">Private verification information</h2>
          <p className="mt-1 text-sm text-text">{item.privateVerificationInfo}</p>
        </div>
      )}

      {item.status === "pending_verification" && (
        <div className="flex gap-3">
          <Button onClick={handleVerify} isLoading={isSubmitting}>
            Verify item
          </Button>
          <Button variant="danger" onClick={() => setShowReject(true)} disabled={isSubmitting}>
            Reject
          </Button>
        </div>
      )}

      <Modal
        isOpen={showReject}
        onClose={() => setShowReject(false)}
        title="Reject this report?"
        description="Add a short reason — the student who reported it will see this."
        size="sm"
        footer={
          <>
            <Button variant="outline" onClick={() => setShowReject(false)} disabled={isSubmitting}>
              Cancel
            </Button>
            <Button variant="danger" onClick={handleReject} isLoading={isSubmitting} disabled={!rejectReason.trim()}>
              Reject report
            </Button>
          </>
        }
      >
        <Textarea label="Reason" required value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} />
      </Modal>
    </div>
  );
}
