import { useState, type FormEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { AuthPageShell } from "@/features/auth/AuthPageShell";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { authService } from "@/features/auth/authService";
import { normalizeApiError } from "@/lib/apiClient";
import type { ApiError } from "@/types";

export function ResetPasswordPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    email: searchParams.get("email") ?? "",
    password: "",
    passwordConfirmation: "",
  });
  const [error, setError] = useState<ApiError | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const token = searchParams.get("token") ?? "";

  function update(field: keyof typeof form) {
    return (e: React.ChangeEvent<HTMLInputElement>) => setForm((current) => ({ ...current, [field]: e.target.value }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);

    try {
      await authService.resetPassword({ token, ...form });
      navigate("/login", { replace: true, state: { passwordReset: true } });
    } catch (err) {
      setError(normalizeApiError(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <AuthPageShell
      title="Choose a new password"
      subtitle="Reset links expire after 60 minutes."
      footer={
        <Link to="/login" className="font-medium text-navy-700 underline underline-offset-2">
          Back to log in
        </Link>
      }
    >
      {!token && (
        <Alert tone="error" title="Reset link is missing">
          Ask an admin to send a new password reset email.
        </Alert>
      )}

      {error && <Alert tone="error" title="Couldn't reset your password">{error.message}</Alert>}

      <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
        <Input
          label="School email"
          type="email"
          required
          autoComplete="email"
          value={form.email}
          onChange={update("email")}
          error={error?.errors?.email?.[0]}
        />
        <Input
          label="New password"
          type="password"
          required
          autoComplete="new-password"
          hint="At least 8 characters with letters and numbers."
          value={form.password}
          onChange={update("password")}
          error={error?.errors?.password?.[0]}
        />
        <Input
          label="Confirm new password"
          type="password"
          required
          autoComplete="new-password"
          value={form.passwordConfirmation}
          onChange={update("passwordConfirmation")}
        />
        <Button type="submit" className="w-full" isLoading={isSubmitting} disabled={!token}>
          Reset password
        </Button>
      </form>
    </AuthPageShell>
  );
}
