import { AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/Button";

export function ErrorState({
  title = "Something went wrong",
  description,
  onRetry,
}: {
  title?: string;
  description?: string;
  onRetry?: () => void;
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-error/30 bg-error-bg px-6 py-12 text-center">
      <AlertTriangle className="mb-1 h-5 w-5 text-error" aria-hidden="true" />
      <p className="text-sm font-medium text-error">{title}</p>
      {description && <p className="max-w-sm text-sm text-text-muted">{description}</p>}
      {onRetry && (
        <Button variant="outline" size="sm" onClick={onRetry} className="mt-3">
          Try again
        </Button>
      )}
    </div>
  );
}

export function UnauthorizedState() {
  return (
    <ErrorState
      title="You don't have access to this page"
      description="If you think this is a mistake, contact the lost & found office."
    />
  );
}

export function NotFoundState({ resource = "page" }: { resource?: string }) {
  return <ErrorState title={`This ${resource} couldn't be found`} description="It may have been removed or the link is incorrect." />;
}
