import { Select } from "@/components/ui/Select";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { ITEM_CATEGORIES } from "@/types";
import type { SearchFilters } from "@/features/search/searchService";

export function FilterPanel({
  filters,
  onChange,
  onReset,
}: {
  filters: SearchFilters;
  onChange: (filters: SearchFilters) => void;
  onReset: () => void;
}) {
  return (
    <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold text-text">Filters</h2>
        <Button variant="ghost" size="sm" onClick={onReset}>
          Reset
        </Button>
      </div>

      <Select
        label="Category"
        placeholder="All categories"
        options={ITEM_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))}
        value={filters.category ?? ""}
        onChange={(e) => onChange({ ...filters, category: e.target.value || undefined })}
      />

      <Input
        label="Colour"
        value={filters.colour ?? ""}
        onChange={(e) => onChange({ ...filters, colour: e.target.value || undefined })}
        placeholder="e.g. black"
      />

      <Input
        label="Location"
        value={filters.location ?? ""}
        onChange={(e) => onChange({ ...filters, location: e.target.value || undefined })}
        placeholder="e.g. library"
      />

      <div className="grid grid-cols-2 gap-3">
        <Input
          type="date"
          label="From"
          value={filters.dateFrom ?? ""}
          onChange={(e) => onChange({ ...filters, dateFrom: e.target.value || undefined })}
        />
        <Input
          type="date"
          label="To"
          value={filters.dateTo ?? ""}
          onChange={(e) => onChange({ ...filters, dateTo: e.target.value || undefined })}
        />
      </div>

    </div>
  );
}
