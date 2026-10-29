import { Link } from "react-router-dom";
import { PackageSearch, Search, ShieldCheck } from "lucide-react";
import { buttonStyles } from "@/components/ui/Button";

export function LandingPage() {
  return (
    <div className="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-16">
      <p className="text-sm font-medium text-navy-700">Campus Lost &amp; Found</p>
      <h1 className="mt-2 text-3xl font-semibold text-text">
        Report a lost item, or hand in something you found.
      </h1>
      <p className="mt-3 max-w-xl text-text-muted">
        The office matches reports using descriptions and photos, so you don't have to check
        the lost property room every day. Staff verify every found item before it's returned.
      </p>

      <div className="mt-8 flex flex-wrap gap-3">
        <Link to="/register" className={buttonStyles("primary", "lg")}>
          Create an account
        </Link>
        <Link to="/login" className={buttonStyles("outline", "lg")}>
          Log in
        </Link>
      </div>

      <dl className="mt-14 grid grid-cols-1 gap-6 border-t border-border pt-8 sm:grid-cols-3">
        <div className="flex gap-3">
          <PackageSearch className="h-5 w-5 shrink-0 text-navy-700" aria-hidden="true" />
          <div>
            <dt className="text-sm font-medium text-text">Report what's missing</dt>
            <dd className="mt-1 text-sm text-text-muted">Add a description, location, and photo.</dd>
          </div>
        </div>
        <div className="flex gap-3">
          <Search className="h-5 w-5 shrink-0 text-navy-700" aria-hidden="true" />
          <div>
            <dt className="text-sm font-medium text-text">Search found items</dt>
            <dd className="mt-1 text-sm text-text-muted">Filter by category, colour, or upload a photo.</dd>
          </div>
        </div>
        <div className="flex gap-3">
          <ShieldCheck className="h-5 w-5 shrink-0 text-navy-700" aria-hidden="true" />
          <div>
            <dt className="text-sm font-medium text-text">Verified returns</dt>
            <dd className="mt-1 text-sm text-text-muted">Every claim is checked by staff before handover.</dd>
          </div>
        </div>
      </dl>
    </div>
  );
}
