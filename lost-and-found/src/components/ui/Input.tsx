import { forwardRef, useId, useState, type InputHTMLAttributes } from "react";
import { Eye, EyeOff } from "lucide-react";
import { cn } from "@/lib/cn";

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  hint?: string;
  error?: string;
  hideLabel?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ label, hint, error, hideLabel, className, id, required, type, ...props }, ref) => {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const hintId = hint ? `${inputId}-hint` : undefined;
    const errorId = error ? `${inputId}-error` : undefined;

    /**
     * Reveal toggle, built in here rather than at each call site.
     *
     * Every password field in the app gets it automatically, and none can
     * be added later without it. Typing a password blind is the single
     * most common cause of a failed login, and this form also asks people
     * to confirm a password they cannot read.
     */
    const isPassword = type === "password";
    const [revealed, setRevealed] = useState(false);

    // The toggle only swaps what is rendered. The field keeps its
    // password semantics for autofill and for the browser's own password
    // manager, which key off the name/autocomplete attributes.
    const resolvedType = isPassword && revealed ? "text" : type;

    return (
      <div className="flex flex-col gap-1.5">
        <label
          htmlFor={inputId}
          className={cn("text-sm font-medium text-text", hideLabel && "sr-only")}
        >
          {label}
          {required && <span className="text-error"> *</span>}
        </label>

        <div className="relative">
          <input
            ref={ref}
            id={inputId}
            type={resolvedType}
            required={required}
            aria-invalid={!!error}
            aria-describedby={cn(hintId, errorId) || undefined}
            className={cn(
              "h-10 w-full rounded-md border border-border-strong bg-surface px-3 text-sm text-text placeholder:text-text-faint",
              "focus:outline-none focus-visible:outline-2 focus-visible:outline-navy-600 focus-visible:outline-offset-1",
              // Room for the toggle, so long values do not run underneath it.
              isPassword && "pr-10",
              error && "border-error",
              className,
            )}
            {...props}
          />

          {isPassword && (
            <button
              type="button"
              onClick={() => setRevealed((v) => !v)}
              // Without type="button" this would submit the form it sits in.
              className={cn(
                "absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md",
                "text-text-muted transition-colors hover:text-text",
                "focus:outline-none focus-visible:outline-2 focus-visible:outline-navy-600 focus-visible:outline-offset-1",
              )}
              // Announces both what it does and the current state, so a
              // screen reader user knows whether their password is visible.
              aria-label={revealed ? "Hide password" : "Show password"}
              aria-pressed={revealed}
              // The input it controls already has the value; the icon is
              // decorative once the button itself is labelled.
              aria-controls={inputId}
            >
              {revealed ? (
                <EyeOff className="h-4 w-4" aria-hidden="true" />
              ) : (
                <Eye className="h-4 w-4" aria-hidden="true" />
              )}
            </button>
          )}
        </div>

        {hint && !error && (
          <p id={hintId} className="text-xs text-text-muted">
            {hint}
          </p>
        )}
        {error && (
          <p id={errorId} className="text-xs text-error" role="alert">
            {error}
          </p>
        )}
      </div>
    );
  },
);
Input.displayName = "Input";
