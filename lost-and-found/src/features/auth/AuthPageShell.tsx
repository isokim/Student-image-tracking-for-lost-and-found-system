import type { ReactNode } from "react";
import { Link } from "react-router-dom";

export function AuthPageShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-bg px-4 py-12">
      <div className="w-full max-w-sm">
        <Link to="/" className="text-sm font-medium text-navy-700">
          Campus Lost &amp; Found
        </Link>
        <h1 className="mt-4 text-xl font-semibold text-text">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-text-muted">{subtitle}</p>}

        <div className="mt-6 flex flex-col gap-4">{children}</div>

        {footer && <p className="mt-6 text-sm text-text-muted">{footer}</p>}
      </div>
    </div>
  );
}
