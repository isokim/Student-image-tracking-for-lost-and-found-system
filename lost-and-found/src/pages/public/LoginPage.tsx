import { useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { AuthPageShell } from "@/features/auth/AuthPageShell";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { useAuth } from "@/features/auth/AuthContext";
import { normalizeApiError } from "@/lib/apiClient";
import { postLoginDestination } from "@/routes/routeAccess";

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      const user = await login({ email, password });
      /**
       * Validated against the role that just signed in - see
       * postLoginDestination. The page they were bounced off belongs to
       * whoever was signed in *then*, which is not necessarily them.
       */
      const from = (location.state as { from?: Location })?.from?.pathname;
      navigate(postLoginDestination(user.role, from), { replace: true });
    } catch (err) {
      setError(normalizeApiError(err).message);
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <AuthPageShell
      title="Log in"
      subtitle="Use your school email to access your reports and claims."
      footer={
        <>
          New here?{" "}
          <Link to="/register" className="font-medium text-navy-700 underline underline-offset-2">
            Create an account
          </Link>
        </>
      }
    >
      {error && <Alert tone="error" title="Couldn't log you in">{error}</Alert>}
      <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
        <Input
          label="Email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        <div className="flex flex-col gap-1.5">
          <Input
            label="Password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Link to="/forgot-password" className="self-end text-xs font-medium text-navy-700 underline underline-offset-2">
            Forgot password?
          </Link>
        </div>
        <Button type="submit" className="w-full" isLoading={isSubmitting}>
          Log in
        </Button>
      </form>
    </AuthPageShell>
  );
}
