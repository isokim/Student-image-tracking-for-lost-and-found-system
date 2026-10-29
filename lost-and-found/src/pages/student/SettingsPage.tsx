import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Alert } from "@/components/ui/Alert";

export function SettingsPage() {
  return (
    <div className="flex max-w-md flex-col gap-6">
      <Breadcrumbs items={[{ label: "Settings" }]} />
      <h1 className="text-xl font-semibold text-text">Settings</h1>

      <Alert tone="info" title="No self-service settings yet">
        Password changes and account updates are handled by the school account system. Contact the Lost and Found
        department or system admin if your profile details are wrong.
      </Alert>
    </div>
  );
}
