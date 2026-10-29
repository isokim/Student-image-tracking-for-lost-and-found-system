import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { ClaimStatus } from "@/components/common/ClaimStatus";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { claimService } from "@/features/claims/claimService";

export function ClaimsPage() {
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ["claims"], queryFn: claimService.list });

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Claims" }]} />
      <h1 className="text-xl font-semibold text-text">Your claims</h1>

      {isLoading ? (
        <LoadingState rows={3} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load your claims right now." />
      ) : data && data.length > 0 ? (
        <ul className="flex flex-col gap-3">
          {data.map((claim) => (
            <li key={claim.id} className="rounded-lg border border-border bg-surface p-4">
              <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium text-text">{claim.foundItem.title}</p>
                <Link to={`/claims/${claim.id}`} className="text-sm font-medium text-navy-700 hover:underline">
                  View details
                </Link>
              </div>
              <div className="mt-3">
                <ClaimStatus status={claim.status} />
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState
          title="No claims yet"
          description="Start a claim from a possible match or a found item you recognize as yours."
        />
      )}
    </div>
  );
}
