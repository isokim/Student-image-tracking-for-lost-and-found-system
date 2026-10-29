import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { ImageUploader } from "@/components/ui/ImageUploader";
import { Button } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { useToast } from "@/components/ui/Toast";
import { useAuth } from "@/features/auth/AuthContext";
import { foundItemService } from "@/features/found-items/foundItemService";
import { normalizeApiError } from "@/lib/apiClient";
import { ITEM_CATEGORIES, type ApiError } from "@/types";

const emptyFoundItemForm = {
  title: "",
  category: "",
  description: "",
  privateVerificationInfo: "",
  colour: "",
  location: "",
  foundAt: "",
  additionalInfo: "",
};

export function ReportFoundItemPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const { show } = useToast();
  const [form, setForm] = useState(emptyFoundItemForm);
  const [photo, setPhoto] = useState<File | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitMode, setSubmitMode] = useState<"view" | "addAnother">("view");
  const [error, setError] = useState<ApiError | null>(null);
  const foundItemsPath = user?.role === "staff" ? "/staff/found-items" : user?.role === "admin" ? "/admin/found-items" : "/found-items";
  const isStaffWorkflow = user?.role === "staff" || user?.role === "admin";

  function update<K extends keyof typeof form>(field: K) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      const created = await foundItemService.create({ ...form, photo: photo ?? undefined });
      show({
        tone: "success",
        title: "Found item report submitted",
        description: isStaffWorkflow
          ? "The item has been added to the verification queue."
          : "Please return the item to the Lost and Found department immediately.",
      });

      if (submitMode === "addAnother" && isStaffWorkflow) {
        setForm(emptyFoundItemForm);
        setPhoto(null);
        return;
      }

      navigate(user?.role === "staff" ? `/staff/items/${created.id}` : user?.role === "admin" ? `/admin/found-items/${created.id}` : `/found-items/${created.id}`);
    } catch (err) {
      setError(normalizeApiError(err));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: "Found items", href: foundItemsPath }, { label: "Report found item" }]} />

      <div>
        <h1 className="text-xl font-semibold text-text">Report a found item</h1>
        <p className="mt-1 text-sm text-text-muted">
          {isStaffWorkflow
            ? "Add items received at the Lost and Found desk so they can be verified and matched."
            : "Submit the details here, then return the physical item to the Lost and Found department immediately."}
        </p>
      </div>

      {!isStaffWorkflow && (
        <Alert tone="warning" title="Hand in the item immediately">
          Do not keep the item after reporting it. Take it to the Lost and Found department so staff can verify it and
          keep it safe for the owner.
        </Alert>
      )}

      {error && (
        <Alert tone="error" title="Couldn't submit your report">
          {error.message}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="flex max-w-xl flex-col gap-5" noValidate>
        <Input
          label="Item title"
          required
          placeholder="e.g. Blue backpack"
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
          label="Public description"
          required
          hint="Shown to anyone browsing found items — keep it general."
          placeholder="e.g. A backpack found near the library entrance"
          value={form.description}
          onChange={update("description")}
          error={error?.errors?.description?.[0]}
        />

        <Input
          label="Colour"
          placeholder="e.g. navy"
          value={form.colour}
          onChange={update("colour")}
          error={error?.errors?.colour?.[0]}
        />

        {/*
          Typed rather than picked from a list - see the note on the lost
          item form.
        */}
        <Input
          label="Where you found it"
          required
          placeholder="e.g. Bench outside the cafeteria"
          hint="Your own words are fine — the more specific the better."
          value={form.location}
          onChange={update("location")}
          error={error?.errors?.location?.[0]}
        />

        <Input
          label="Date found"
          type="date"
          required
          value={form.foundAt}
          onChange={update("foundAt")}
          error={error?.errors?.foundAt?.[0]}
        />

        <ImageUploader value={photo} onChange={setPhoto} label="Photograph (optional)" />

        <div className="rounded-md border border-border bg-surface-sunken p-3">
          <Textarea
            label="Private verification information"
            hint="Only visible to staff reviewing claims — never shown publicly. e.g. contents, serial number, a mark only the owner would know."
            value={form.privateVerificationInfo}
            onChange={update("privateVerificationInfo")}
          />
        </div>

        <Textarea
          label="Additional information"
          value={form.additionalInfo}
          onChange={update("additionalInfo")}
        />

        <div className="flex gap-3">
          <Button type="submit" isLoading={isSubmitting} onClick={() => setSubmitMode("view")}>
            Submit report
          </Button>
          {isStaffWorkflow && (
            <Button type="submit" variant="outline" isLoading={isSubmitting} onClick={() => setSubmitMode("addAnother")}>
              Submit and add another
            </Button>
          )}
          <Button type="button" variant="outline" onClick={() => navigate(-1)}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  );
}
