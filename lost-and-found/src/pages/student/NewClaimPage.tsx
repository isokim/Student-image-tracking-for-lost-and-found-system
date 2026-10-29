import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Textarea } from "@/components/ui/Textarea";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { claimService } from "@/features/claims/claimService";
import { normalizeApiError } from "@/lib/apiClient";

// In production these would come from the found item's configured
// verification questions (set by staff). Kept generic here since the
// AI/backend verification service isn't implemented yet.
const VERIFICATION_QUESTIONS = [
  "What was inside or attached to the item when you lost it?",
  "Is there any identifying mark, scratch, or engraving on it?",
];

export function NewClaimPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const foundItemId = searchParams.get("foundItemId") ?? "";
  const matchId = searchParams.get("matchId") ?? undefined;

  const [step, setStep] = useState<1 | 2>(1);
  const [identifyingInformation, setIdentifyingInformation] = useState("");
  const [answers, setAnswers] = useState<string[]>(VERIFICATION_QUESTIONS.map(() => ""));
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit() {
    setError(null);
    setIsSubmitting(true);
    try {
      const claim = await claimService.create({
        foundItemId,
        matchId,
        identifyingInformation,
        verificationAnswers: VERIFICATION_QUESTIONS.map((question, i) => ({ question, answer: answers[i] })),
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
          Step {step} of 2 — staff will review your answers before the item is released.
        </p>
      </div>

      {error && <Alert tone="error" title="Couldn't submit your claim">{error}</Alert>}

      {step === 1 && (
        <div className="flex flex-col gap-4">
          <Textarea
            label="Identifying information"
            required
            hint="Describe details only the true owner would know — contents, wear, serial number, etc."
            value={identifyingInformation}
            onChange={(e) => setIdentifyingInformation(e.target.value)}
          />
          <div className="flex gap-3">
            <Button onClick={() => setStep(2)} disabled={!identifyingInformation.trim()}>
              Continue
            </Button>
            <Button variant="outline" onClick={() => navigate(-1)}>
              Cancel
            </Button>
          </div>
        </div>
      )}

      {step === 2 && (
        <div className="flex flex-col gap-4">
          {VERIFICATION_QUESTIONS.map((question, i) => (
            <Input
              key={question}
              label={question}
              required
              value={answers[i]}
              onChange={(e) =>
                setAnswers((prev) => prev.map((a, idx) => (idx === i ? e.target.value : a)))
              }
            />
          ))}
          <div className="flex gap-3">
            <Button onClick={handleSubmit} isLoading={isSubmitting} disabled={answers.some((a) => !a.trim())}>
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
