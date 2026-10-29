import type { ReactNode } from "react";
import { Link } from "react-router-dom";

export function DashboardSection({
  title,
  viewAllHref,
  children,
}: {
  title: string;
  viewAllHref?: string;
  children: ReactNode;
}) {
  return (
    <section className="flex flex-col gap-3">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold text-text">{title}</h2>
        {viewAllHref && (
          <Link to={viewAllHref} className="text-sm font-medium text-navy-700 hover:underline">
            View all
          </Link>
        )}
      </div>
      {children}
    </section>
  );
}
