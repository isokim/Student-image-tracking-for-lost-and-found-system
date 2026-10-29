import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { Avatar } from "@/components/ui/Avatar";
import { useAuth } from "@/features/auth/AuthContext";

export function ProfilePage() {
  const { user } = useAuth();
  if (!user) return null;

  return (
    <div className="flex max-w-lg flex-col gap-6">
      <Breadcrumbs items={[{ label: "Profile" }]} />
      <h1 className="text-xl font-semibold text-text">Profile</h1>

      <div className="flex items-center gap-4 rounded-lg border border-border bg-surface p-4">
        <Avatar name={user.name} src={user.avatarUrl} size="lg" />
        <div>
          <p className="text-sm font-medium text-text">{user.name}</p>
          <p className="text-sm text-text-muted">{user.email}</p>
        </div>
      </div>

      <dl className="grid grid-cols-2 gap-4 rounded-lg border border-border bg-surface p-4 text-sm">
        <div>
          <dt className="text-text-muted">Role</dt>
          <dd className="capitalize text-text">{user.role}</dd>
        </div>
        {user.admissionNumber && (
          <div>
            <dt className="text-text-muted">Admission number</dt>
            <dd className="text-text">{user.admissionNumber}</dd>
          </div>
        )}
        {user.department && (
          <div>
            <dt className="text-text-muted">Department</dt>
            <dd className="text-text">{user.department}</dd>
          </div>
        )}
        <div>
          <dt className="text-text-muted">Member since</dt>
          <dd className="text-text">{new Date(user.createdAt).toLocaleDateString()}</dd>
        </div>
      </dl>
    </div>
  );
}
