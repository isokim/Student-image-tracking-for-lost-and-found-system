import { useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { AuthPageShell } from "@/features/auth/AuthPageShell";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { useAuth } from "@/features/auth/AuthContext";
import { normalizeApiError } from "@/lib/apiClient";
import type { ApiError } from "@/types";

export function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    name: "",
    email: "",
    admissionNumber: "",
    password: "",
    passwordConfirmation: "",
  });
  const [error, setError] = useState<ApiError | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  function update(field: keyof typeof form) {
    return (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      await register(form);
      navigate("/dashboard", { replace: true });
    } catch (err) {
      setError(normalizeApiError(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <AuthPageShell
      title="Create your account"
      subtitle="Register with your school email and admission number."
      footer={
        <>
          Already have an account?{" "}
          <Link to="/login" className="font-medium text-navy-700 underline underline-offset-2">
            Log in
          </Link>
        </>
      }
    >
      {error && <Alert tone="error" title="Couldn't create your account">{error.message}</Alert>}
      <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
        <Input label="Full name" required autoComplete="name" value={form.name} onChange={update("name")} error={error?.errors?.name?.[0]} />
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
          label="Admission number"
          required
          value={form.admissionNumber}
          onChange={update("admissionNumber")}
          error={error?.errors?.admissionNumber?.[0]}
        />
        <Input
          label="Password"
          type="password"
          required
          autoComplete="new-password"
          hint="At least 8 characters."
          value={form.password}
          onChange={update("password")}
          error={error?.errors?.password?.[0]}
        />
        <Input
          label="Confirm password"
          type="password"
          required
          autoComplete="new-password"
          value={form.passwordConfirmation}
          onChange={update("passwordConfirmation")}
        />
        <Button type="submit" className="w-full" isLoading={isSubmitting}>
          Create account
        </Button>
      </form>
    </AuthPageShell>
  );
}
