import { useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { AuthPageShell } from "@/features/auth/AuthPageShell";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { authService } from "@/features/auth/authService";
import { normalizeApiError } from "@/lib/apiClient";

export function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      await authService.requestPasswordReset(email);
      setSent(true);
    } catch (err) {
      setError(normalizeApiError(err).message);
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <AuthPageShell
      title="Reset your password"
      subtitle="We'll send a reset link to your school email."
      footer={
        <Link to="/login" className="font-medium text-navy-700 underline underline-offset-2">
          Back to log in
        </Link>
      }
    >
      {sent ? (
        <Alert tone="success" title="Check your email">
          If an account exists for {email}, a reset link is on its way.
        </Alert>
      ) : (
        <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
          {error && <Alert tone="error" title="Couldn't send the reset link">{error}</Alert>}
          <Input
            label="School email"
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Button type="submit" className="w-full" isLoading={isSubmitting}>
            Send reset link
          </Button>
        </form>
      )}
    </AuthPageShell>
  );
}
