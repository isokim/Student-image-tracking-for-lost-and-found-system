import { CheckCircle2, Circle, Clock, XCircle } from "lucide-react";
import { Badge } from "@/components/ui/Badge";
import type { FoundItemStatus, LostItemStatus } from "@/types";

const lostConfig: Record<LostItemStatus, { label: string; tone: "neutral" | "success" | "warning" | "info"; icon: typeof Circle }> = {
  active: { label: "Active", tone: "info", icon: Circle },
  matched: { label: "Possible match", tone: "warning", icon: Clock },
  resolved: { label: "Resolved", tone: "success", icon: CheckCircle2 },
  closed: { label: "Closed", tone: "neutral", icon: XCircle },
};

const foundConfig: Record<FoundItemStatus, { label: string; tone: "neutral" | "success" | "warning" | "info" | "error"; icon: typeof Circle }> = {
  pending_verification: { label: "Pending verification", tone: "warning", icon: Clock },
  verified: { label: "Verified", tone: "info", icon: CheckCircle2 },
  claimed: { label: "Claimed", tone: "warning", icon: Clock },
  returned: { label: "Returned", tone: "success", icon: CheckCircle2 },
  rejected: { label: "Rejected", tone: "error", icon: XCircle },
};

export function LostItemStatusBadge({ status }: { status: LostItemStatus }) {
  const { label, tone, icon: Icon } = lostConfig[status];
  return (
    <Badge tone={tone} icon={<Icon className="h-3.5 w-3.5" aria-hidden="true" />}>
      {label}
    </Badge>
  );
}

export function FoundItemStatusBadge({ status }: { status: FoundItemStatus }) {
  const { label, tone, icon: Icon } = foundConfig[status];
  return (
    <Badge tone={tone} icon={<Icon className="h-3.5 w-3.5" aria-hidden="true" />}>
      {label}
    </Badge>
  );
}
