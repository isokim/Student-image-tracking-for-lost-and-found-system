import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Textarea } from "@/components/ui/Textarea";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { useAuth } from "@/features/auth/AuthContext";
import { claimService } from "@/features/claims/claimService";
import { foundItemService } from "@/features/found-items/foundItemService";
import { lostItemService } from "@/features/lost-items/lostItemService";
import { matchService } from "@/features/matches/matchService";
import { normalizeApiError } from "@/lib/apiClient";
import type { LostItemReport } from "@/types";

/**
 * Asked in the same words as the Report Lost Item form, so a claimant is
 * never asked about something they had no chance to record, and staff
 * can check each answer against the found item (where and when it was
 * found) and, if the claim came from a match, the claimant's own report.
 */
const VERIFICATION_QUESTIONS: { question: string; type: "text" | "date"; placeholder?: string }[] = [
  { question: "Where you last had it", type: "text", placeholder: "e.g. Library, 2nd floor near the printers" },
  { question: "Date lost", type: "date" },
];

// Mirrors the min:20 rule in StoreClaimRequest, so the claimant finds
// out on this step rather than after submitting from the next one.
const MIN_PROOF_LENGTH = 20;

// Select value for "I didn't report it lost".
const NO_REPORT = "none";

// Mirrors LostItemStatus::isOpen() - the API only accepts an open report.
function isOpenReport(report: LostItemReport) {
  return report.status === "active" || report.status === "matched";
}

/** The step 1 and step 2 answers a lost report fills in. */
function prefillFrom(report: LostItemReport) {
  return {
    proof: report.additionalInfo ?? "",
    answers: [report.locationText ?? "", report.lostAt ? report.lostAt.slice(0, 10) : ""],
  };
}

export function NewClaimPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const foundItemId = searchParams.get("foundItemId") ?? "";
  const matchId = searchParams.get("matchId") ?? undefined;
  const { user } = useAuth();

  // The finder cannot claim the item they handed in. The API refuses it
  // too (FoundItemPolicy::claim); this just stops them filling in the
  // whole form first when they arrive here by URL or from a match.
  const { data: foundItem } = useQuery({
    queryKey: ["found-items", foundItemId],
    queryFn: () => foundItemService.get(foundItemId),
    enabled: !!foundItemId,
  });
  const isFinder = !!user && !!foundItem && user.id === foundItem.reportedBy.id;
  // Mirrors FoundItemStatus::isClaimable() - only staff-verified items.
  const notClaimable = !!foundItem && !isFinder && foundItem.status !== "verified";
  const blocked = isFinder || notClaimable;

  /**
   * "Which of your lost reports is this?"
   *
   * The picked report's "Details that prove it's yours" become the claim's
   * proof, which staff compare with the found item's hidden details.
   */
  const myLostItems = useQuery({ queryKey: ["lost-items", "mine"], queryFn: lostItemService.mine });
  const openReports = (myLostItems.data ?? []).filter(isOpenReport);
  const match = useQuery({
    queryKey: ["matches", matchId],
    queryFn: () => matchService.get(matchId as string),
    enabled: !!matchId,
  });

  const [step, setStep] = useState<1 | 2>(1);
  const [lostItemId, setLostItemId] = useState("");
  const [identifyingInformation, setIdentifyingInformation] = useState("");
  const [answers, setAnswers] = useState<string[]>(VERIFICATION_QUESTIONS.map(() => ""));
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const selectedReport = openReports.find((r) => r.id === lostItemId);
  const reportProofTooShort =
    !!selectedReport && (selectedReport.additionalInfo ?? "").trim().length < MIN_PROOF_LENGTH;

  function applyReport(report: LostItemReport) {
    setLostItemId(report.id);
    const prefill = prefillFrom(report);
    setIdentifyingInformation(prefill.proof);
    setAnswers(prefill.answers);
  }

  function pickReport(id: string) {
    const report = openReports.find((r) => r.id === id);
    if (report) {
      applyReport(report);
    } else {
      // "I didn't report it lost" - keep whatever they've typed.
      setLostItemId(id);
    }
  }

  /**
   * Opened from a match: preselect that match's lost report, once both
   * queries have loaded, unless the student has already chosen something.
   * Adjusted during render (React's pattern for state that follows other
   * data) rather than in an effect, so there is no extra render pass.
   */
  const [matchPreselectDone, setMatchPreselectDone] = useState(false);
  const matchedReportId = match.data?.lostItem.id;
  if (!matchPreselectDone && matchedReportId && myLostItems.isSuccess) {
    setMatchPreselectDone(true);
    const report = openReports.find((r) => r.id === matchedReportId);
    if (report && lostItemId === "") applyReport(report);
  }

  const hasReports = openReports.length > 0;
  // With reports to choose from, the student says which one (or none)
  // before continuing.
  const reportChoiceMissing = hasReports && lostItemId === "";

  async function handleSubmit() {
    setError(null);
    setIsSubmitting(true);
    try {
      // Both "where and when" questions are optional: send only the ones
      // the claimant actually answered, and omit the field if neither was.
      const answered = VERIFICATION_QUESTIONS.map(({ question }, i) => ({ question, answer: answers[i].trim() })).filter(
        (a) => a.answer !== "",
      );
      const claim = await claimService.create({
        foundItemId,
        matchId,
        ...(lostItemId && lostItemId !== NO_REPORT ? { lostItemId } : {}),
        identifyingInformation,
        ...(answered.length > 0 ? { verificationAnswers: answered } : {}),
      });
      navigate(`/claims/${claim.id}`);
    } catch (err) {
      setError(normalizeApiError(err).message);
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex max-w-xl flex-col gap-6">
      <Breadcrumbs items={[{ label: "Claims", href: "/claims" }, { label: "Start a claim" }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">Start a claim</h1>
        <p className="mt-1 text-sm text-text-muted">
          Step {step} of 2 — staff compare your answers with what the finder recorded before the item is released.
        </p>
      </div>

      {error && <Alert tone="error" title="Couldn't submit your claim">{error}</Alert>}

      {isFinder && (
        <Alert tone="error" title="You can't claim this item">
          You reported this item as found, so you can't claim it.{" "}
          <Link to={`/found-items/${foundItemId}`} className="underline">
            Back to the item
          </Link>
        </Alert>
      )}

      {notClaimable && (
        <Alert tone="info" title="This item can't be claimed yet">
          {foundItem?.status === "pending_verification"
            ? "Staff haven't confirmed this item has been handed in yet. You can claim it once it's been verified — check back soon."
            : "This item is no longer available to claim."}{" "}
          <Link to={`/found-items/${foundItemId}`} className="underline">
            Back to the item
          </Link>
        </Alert>
      )}

      {!blocked && step === 1 && (
        <div className="flex flex-col gap-4">
          {hasReports ? (
            <Select
              label="Which of your lost reports is this?"
              placeholder="Choose a lost report"
              options={[
                ...openReports.map((r) => ({ value: r.id, label: r.title })),
                { value: NO_REPORT, label: "I didn't report it lost" },
              ]}
              value={lostItemId}
              onChange={(e) => pickReport(e.target.value)}
            />
          ) : (
            myLostItems.isSuccess && (
              <Alert tone="info" title="Tip: report it lost first">
                A lost report helps staff verify your claim, because they can compare what you recorded with the item.{" "}
                <Link to="/lost-items/report" className="underline">
                  Report it lost
                </Link>
                , or carry on below.
              </Alert>
            )
          )}

          {selectedReport && (
            <p className="text-sm text-text-muted">
              We've filled in the answers below from your lost report — you can still edit them.
            </p>
          )}

          <Textarea
            label="Details that prove it's yours"
            required
            hint={
              reportProofTooShort
                ? "Your lost report doesn't have enough detail to prove it's yours. Add more here — things only the owner would know, e.g. what's inside, a serial number, a scratch or sticker. At least 20 characters."
                : "Only staff see this. Include things only the owner would know, e.g. what's inside, a serial number, a scratch or sticker. At least 20 characters."
            }
            value={identifyingInformation}
            onChange={(e) => setIdentifyingInformation(e.target.value)}
          />
          <div className="flex gap-3">
            <Button
              onClick={() => setStep(2)}
              disabled={reportChoiceMissing || identifyingInformation.trim().length < MIN_PROOF_LENGTH}
            >
              Continue
            </Button>
            <Button variant="outline" onClick={() => navigate(-1)}>
              Cancel
            </Button>
          </div>
        </div>
      )}

      {!blocked && step === 2 && (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-text-muted">
            These are optional, but they help staff match your claim to where and when the item was found.
          </p>
          {VERIFICATION_QUESTIONS.map(({ question, type, placeholder }, i) => (
            <Input
              key={question}
              label={`${question} (optional)`}
              type={type}
              placeholder={placeholder}
              value={answers[i]}
              onChange={(e) =>
                setAnswers((prev) => prev.map((a, idx) => (idx === i ? e.target.value : a)))
              }
            />
          ))}
          <div className="flex gap-3">
            <Button onClick={handleSubmit} isLoading={isSubmitting}>
              Submit claim
            </Button>
            <Button variant="outline" onClick={() => setStep(1)}>
              Back
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
