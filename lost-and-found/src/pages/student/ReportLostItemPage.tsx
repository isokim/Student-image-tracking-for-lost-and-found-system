import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { ImageUploader } from "@/components/ui/ImageUploader";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { lostItemService } from "@/features/lost-items/lostItemService";
import { normalizeApiError } from "@/lib/apiClient";
import { ITEM_CATEGORIES, type ApiError } from "@/types";

export function ReportLostItemPage() {
  const navigate = useNavigate();
  const [form, setForm] = useState({
    title: "",
    category: "",
    description: "",
    colour: "",
    location: "",
    lostAt: "",
    additionalInfo: "",
  });
  const [photo, setPhoto] = useState<File | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  function update<K extends keyof typeof form>(field: K) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      const created = await lostItemService.create({ ...form, photo: photo ?? undefined });
      navigate(`/lost-items/${created.id}`);
    } catch (err) {
      setError(normalizeApiError(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Lost items", href: "/lost-items" }, { label: "Report lost item" }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">Report a lost item</h1>
        <p className="mt-1 text-sm text-text-muted">
          Give as much detail as you can — it helps the search match your item faster.
        </p>
      </div>

      {error && (
        <Alert tone="error" title="Couldn't submit your report">
          {error.message}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="flex max-w-xl flex-col gap-5" noValidate>
        <Input
          label="Item title"
          required
          placeholder="e.g. Black Dell laptop"
          value={form.title}
          onChange={update("title")}
          error={error?.errors?.title?.[0]}
        />

        <Select
          label="Category"
          required
          placeholder="Choose a category"
          options={ITEM_CATEGORIES.map((c) => ({ value: c.value, label: c.label }))}
          value={form.category}
          onChange={update("category")}
          error={error?.errors?.category?.[0]}
        />

        <Textarea
          label="Description"
          required
          placeholder="What does it look like? Any distinguishing marks?"
          value={form.description}
          onChange={update("description")}
          error={error?.errors?.description?.[0]}
        />

        <Input
          label="Colour"
          placeholder="e.g. black"
          value={form.colour}
          onChange={update("colour")}
          error={error?.errors?.colour?.[0]}
        />

        {/*
          Typed by the reporter rather than picked from a list. Places on
          campus change faster than a fixed list does, and "the bench
          outside the cafeteria" is more useful than a generic option.
        */}
        <Input
          label="Where you last had it"
          required
          placeholder="e.g. Library, 2nd floor near the printers"
          hint="Your own words are fine — the more specific the better."
          value={form.location}
          onChange={update("location")}
          error={error?.errors?.location?.[0]}
        />

        <Input
          label="Date lost"
          type="date"
          required
          value={form.lostAt}
          onChange={update("lostAt")}
          error={error?.errors?.lostAt?.[0]}
        />

        <ImageUploader value={photo} onChange={setPhoto} label="Photograph (optional)" />

        <Textarea
          label="Additional identifying information"
          hint="Only shared with staff during claim verification — not shown publicly."
          value={form.additionalInfo}
          onChange={update("additionalInfo")}
        />

        <div className="flex gap-3">
          <Button type="submit" isLoading={isSubmitting}>
            Submit report
          </Button>
          <Button type="button" variant="outline" onClick={() => navigate(-1)}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  );
}
