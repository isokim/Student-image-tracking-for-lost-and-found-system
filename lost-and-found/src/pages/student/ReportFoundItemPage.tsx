import { useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { ImageUploader } from "@/components/ui/ImageUploader";
import { Button, buttonStyles } from "@/components/ui/Button";
import { Alert } from "@/components/ui/Alert";
import { useToast } from "@/components/ui/Toast";
import { useAuth } from "@/features/auth/AuthContext";
import { foundItemService } from "@/features/found-items/foundItemService";
import { normalizeApiError } from "@/lib/apiClient";
import { ITEM_CATEGORIES, type ApiError, type FoundItemReport } from "@/types";

// Mirrors the min:10 rule on privateVerificationInfo in StoreFoundItemRequest.
const MIN_HIDDEN_DETAILS_LENGTH = 10;

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

/**
 * Staff and admins log found items here - students hand items in at the
 * office instead (the route is only mounted under /staff and /admin, and
 * the API refuses students with a 403).
 *
 * Built for logging several items in a row: after a successful submit the
 * form is replaced by a confirmation offering "View item" or "Log another
 * item", which clears the form and stays on the page.
 */
export function ReportFoundItemPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const { show } = useToast();
  const [form, setForm] = useState(emptyFoundItemForm);
  const [photo, setPhoto] = useState<File | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [lastLogged, setLastLogged] = useState<FoundItemReport | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const isAdmin = user?.role === "admin";
  const foundItemsPath = isAdmin ? "/admin/found-items" : "/staff/found-items";
  const itemPath = (id: string) => (isAdmin ? `/admin/found-items/${id}` : `/staff/items/${id}`);

  function logAnother() {
    setForm(emptyFoundItemForm);
    setPhoto(null);
    setError(null);
    setLastLogged(null);
  }

  function update<K extends keyof typeof form>(field: K) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm((f) => ({ ...f, [field]: e.target.value }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (form.privateVerificationInfo.trim().length < MIN_HIDDEN_DETAILS_LENGTH) {
      const message = "Add a hidden detail only the owner would know (at least 10 characters), so staff can check claims.";
      setError({ message, errors: { privateVerificationInfo: [message] } });
      return;
    }
    setIsSubmitting(true);
    try {
      const created = await foundItemService.create({ ...form, photo: photo ?? undefined });
      // Staff lists and the dashboard queue should show the new item.
      queryClient.invalidateQueries({ queryKey: ["found-items"] });
      queryClient.invalidateQueries({ queryKey: ["admin", "found-items"] });
      show({
        tone: "success",
        title: "Found item logged",
        description: "It is listed for students now, and owners of matching lost reports will be notified.",
      });
      setLastLogged(created);
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
          Add items received at the Lost and Found desk. They are available to claim straight away and matched against lost reports.
        </p>
      </div>

      {lastLogged && (
        <div className="flex max-w-xl flex-col gap-3">
          <Alert tone="success" title={`Logged: ${lastLogged.title}`}>
            It is now available to claim, and owners of matching lost reports will be notified.
          </Alert>
          <div className="flex flex-wrap gap-3">
            <Button type="button" onClick={logAnother}>
              Log another item
            </Button>
            <Link to={itemPath(lastLogged.id)} className={buttonStyles("outline", "md")}>
              View item
            </Link>
          </div>
        </div>
      )}

      {error && (
        <Alert tone="error" title="Couldn't submit your report">
          {error.message}
        </Alert>
      )}

      {!lastLogged && (
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
          label="Where it was found"
          required
          placeholder="e.g. Bench outside the cafeteria"
          hint="The finder's own words are fine — the more specific the better."
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
            label="Hidden details only the owner would know — not shown publicly"
            required
            hint="Used to check claims: staff compare what a claimant says against this. It is never shown publicly or to the person claiming. e.g. what's inside, a serial number, a scratch or sticker. At least 10 characters."
            value={form.privateVerificationInfo}
            onChange={update("privateVerificationInfo")}
            error={error?.errors?.privateVerificationInfo?.[0]}
          />
        </div>

        <Textarea
          label="Additional information"
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
      )}
    </div>
  );
}
