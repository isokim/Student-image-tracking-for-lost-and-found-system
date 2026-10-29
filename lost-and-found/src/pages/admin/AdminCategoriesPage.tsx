import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { ITEM_CATEGORIES } from "@/types";

interface CategoryRow {
  value: string;
  label: string;
}

export function AdminCategoriesPage() {
  const columns: DataTableColumn<CategoryRow>[] = [
    { key: "label", header: "Category", render: (row) => row.label },
    { key: "value", header: "Key", render: (row) => row.value },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Categories" }]} />
      <div>
        <h1 className="text-xl font-semibold text-text">Categories</h1>
        <p className="mt-1 text-sm text-text-muted">
          Categories used across lost and found reports. Editing requires a backend endpoint — currently fixed.
        </p>
      </div>
      <DataTable columns={columns} rows={ITEM_CATEGORIES} rowKey={(row) => row.value} caption="Item categories" />
    </div>
  );
}
