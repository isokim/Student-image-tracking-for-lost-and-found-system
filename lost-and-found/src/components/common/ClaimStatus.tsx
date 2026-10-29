import { Check, Clock, X } from "lucide-react";
import { cn } from "@/lib/cn";
import type { ClaimStatus as ClaimStatusType } from "@/types";

const steps: { key: ClaimStatusType; label: string }[] = [
  { key: "pending_review", label: "Submitted" },
  { key: "approved", label: "Approved" },
  { key: "completed", label: "Returned" },
];

const stepIndex: Record<ClaimStatusType, number> = {
  draft: -1,
  pending_review: 0,
  approved: 1,
  rejected: 1,
  completed: 2,
};

export function ClaimStatus({ status }: { status: ClaimStatusType }) {
  if (status === "rejected") {
    return (
      <div className="flex items-center gap-2 text-sm text-error">
        <X className="h-4 w-4" aria-hidden="true" />
        <span className="font-medium">Claim rejected</span>
      </div>
    );
  }

  const current = stepIndex[status];

  return (
    <ol className="flex items-center gap-2" aria-label="Claim progress">
      {steps.map((step, i) => {
        const done = i < current || (status === "completed" && i <= current);
        const active = i === current && status !== "completed";
        return (
          <li key={step.key} className="flex items-center gap-2">
            <span
              className={cn(
                "flex h-6 w-6 items-center justify-center rounded-full border text-xs font-medium",
                done && "border-success bg-success-bg text-success",
                active && "border-navy-900 bg-navy-900 text-white",
                !done && !active && "border-border-strong text-text-faint",
              )}
              aria-current={active ? "step" : undefined}
            >
              {done ? <Check className="h-3.5 w-3.5" aria-hidden="true" /> : i + 1}
            </span>
            <span className={cn("text-sm", active ? "font-medium text-text" : "text-text-muted")}>
              {step.label}
            </span>
            {i < steps.length - 1 && (
              <span className="mx-1 h-px w-6 bg-border-strong" aria-hidden="true" />
            )}
          </li>
        );
      })}
      {status === "pending_review" && (
        <li className="ml-1 flex items-center gap-1 text-xs text-text-faint">
          <Clock className="h-3.5 w-3.5" aria-hidden="true" />
          Awaiting staff review
        </li>
      )}
    </ol>
  );
}
