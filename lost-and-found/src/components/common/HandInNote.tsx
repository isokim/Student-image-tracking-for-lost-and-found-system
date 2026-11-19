import { Alert } from "@/components/ui/Alert";

/**
 * Shown to students where "Report found item" used to be.
 *
 * Students no longer log found items themselves - that let anyone post a
 * fabricated item and see who came to claim it. They hand the object in
 * at the office, and staff log it with the item in front of them.
 */
export function HandInNote() {
  return (
    <Alert tone="info" title="Found something?">
      Hand it in at the lost &amp; found office — staff will log it so the owner can claim it.
    </Alert>
  );
}
