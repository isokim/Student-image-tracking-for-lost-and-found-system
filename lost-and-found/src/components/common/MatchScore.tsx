import { cn } from "@/lib/cn";
import type { MatchScoreBreakdown } from "@/types";

function ScoreBar({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center gap-3">
      <span className="w-44 shrink-0 text-sm text-text-muted">{label}</span>
      <div
        className="h-1.5 flex-1 rounded-full bg-surface-sunken"
        role="progressbar"
        aria-valuenow={value}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label}
      >
        <div
          className="h-full rounded-full bg-navy-700"
          style={{ width: `${value}%` }}
        />
      </div>
      <span className="w-10 shrink-0 text-right text-sm font-medium text-text">{value}%</span>
    </div>
  );
}

function MatchRow({ label, value }: { label: string; value: string }) {
  const isMatch = value === "Match" || value === "same";
  const isClose = value === "close";
  return (
    <div className="flex items-center justify-between text-sm">
      <span className="text-text-muted">{label}</span>
      <span
        className={cn(
          "font-medium",
          isMatch && "text-success",
          isClose && "text-warning",
          !isMatch && !isClose && "text-text-muted",
        )}
      >
        {value}
      </span>
    </div>
  );
}

export function MatchScoreCard({
  scores,
  confidence,
}: {
  scores: MatchScoreBreakdown;
  confidence: "possible" | "likely";
}) {
  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-text">Why this may be a match</h3>
        <span className="rounded-sm bg-info-bg px-2 py-0.5 text-xs font-medium text-info">
          {confidence === "likely" ? "Likely match" : "Possible match"}
        </span>
      </div>
      <div className="flex flex-col gap-3">
        {scores.visualSimilarity !== undefined && (
          <ScoreBar label="Visual similarity" value={scores.visualSimilarity} />
        )}
        {scores.descriptionSimilarity !== undefined && (
          <ScoreBar label="Description similarity" value={scores.descriptionSimilarity} />
        )}
        {/*
          Only rendered when the API actually measured these. On an image
          search there is no lost report to compare against, so showing
          "Category: Different" would be inventing a result.
        */}
        {(scores.categoryMatch !== undefined ||
          scores.colourMatch !== undefined ||
          scores.locationProximity !== undefined) && (
          <div className="mt-1 flex flex-col gap-2 border-t border-border pt-3">
            {scores.categoryMatch !== undefined && (
              <MatchRow label="Category" value={scores.categoryMatch ? "Match" : "Different"} />
            )}
            {scores.colourMatch !== undefined && (
              <MatchRow label="Colour" value={scores.colourMatch ? "Match" : "Different"} />
            )}
            {scores.locationProximity !== undefined && (
              <MatchRow
                label="Location"
                value={
                  scores.locationProximity === "same"
                    ? "Same location"
                    : scores.locationProximity === "close"
                      ? "Nearby"
                      : "Different area"
                }
              />
            )}
          </div>
        )}
      </div>
    </div>
  );
}
