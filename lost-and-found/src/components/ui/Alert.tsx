import type { ReactNode } from "react";
import { AlertCircle, AlertTriangle, CheckCircle2, Info } from "lucide-react";
import { cn } from "@/lib/cn";

type Tone = "info" | "success" | "warning" | "error";

const config: Record<Tone, { icon: typeof Info; classes: string }> = {
  info: { icon: Info, classes: "border-info/30 bg-info-bg text-info" },
  success: { icon: CheckCircle2, classes: "border-success/30 bg-success-bg text-success" },
  warning: { icon: AlertTriangle, classes: "border-warning/30 bg-warning-bg text-warning" },
  error: { icon: AlertCircle, classes: "border-error/30 bg-error-bg text-error" },
};

export function Alert({
  tone = "info",
  title,
  children,
}: {
  tone?: Tone;
  title: string;
  children?: ReactNode;
}) {
  const { icon: Icon, classes } = config[tone];
  return (
    <div className={cn("flex gap-3 rounded-md border p-3", classes)} role={tone === "error" ? "alert" : "status"}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <div>
        <p className="text-sm font-medium">{title}</p>
        {children && <div className="mt-1 text-sm opacity-90">{children}</div>}
      </div>
    </div>
  );
}
