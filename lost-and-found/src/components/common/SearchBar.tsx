import { Search } from "lucide-react";

export function SearchBar({
  value,
  onChange,
  onSubmit,
  placeholder = "Search by item, colour, or location…",
}: {
  value: string;
  onChange: (value: string) => void;
  onSubmit?: () => void;
  placeholder?: string;
}) {
  return (
    <form
      role="search"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit?.();
      }}
      className="flex items-center gap-2 rounded-md border border-border-strong bg-surface px-3 h-10"
    >
      <Search className="h-4 w-4 text-text-faint" aria-hidden="true" />
      <label htmlFor="global-search" className="sr-only">
        Search items
      </label>
      <input
        id="global-search"
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full bg-transparent text-sm text-text placeholder:text-text-faint focus:outline-none"
      />
    </form>
  );
}
