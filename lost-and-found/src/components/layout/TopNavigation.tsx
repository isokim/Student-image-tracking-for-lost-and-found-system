import { Link, useNavigate } from "react-router-dom";
import { Bell, LogOut, Menu, Settings, User as UserIcon } from "lucide-react";
import { Avatar } from "@/components/ui/Avatar";
import { Dropdown } from "@/components/ui/Dropdown";
import { useAuth } from "@/features/auth/AuthContext";

export function TopNavigation({
  onMenuClick,
  unreadCount = 0,
}: {
  onMenuClick: () => void;
  unreadCount?: number;
}) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  async function handleLogout() {
    await logout();
    navigate("/login", { replace: true, state: null });
  }

  return (
    <header className="flex h-14 items-center justify-between border-b border-border bg-surface px-4">
      <button
        type="button"
        onClick={onMenuClick}
        className="rounded-sm p-1.5 text-text-muted hover:bg-surface-sunken lg:hidden"
        aria-label="Open navigation menu"
      >
        <Menu className="h-5 w-5" aria-hidden="true" />
      </button>

      <div className="ml-auto flex items-center gap-1">
        <Link
          to="/notifications"
          className="relative rounded-sm p-2 text-text-muted hover:bg-surface-sunken hover:text-text"
          aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : "Notifications"}
        >
          <Bell className="h-4.5 w-4.5" aria-hidden="true" />
          {unreadCount > 0 && (
            <span className="absolute right-1.5 top-1.5 h-1.5 w-1.5 rounded-full bg-error" aria-hidden="true" />
          )}
        </Link>

        {user && (
          <Dropdown
            trigger={
              <span className="flex items-center gap-2 rounded-md px-2 py-1 hover:bg-surface-sunken">
                <Avatar name={user.name} src={user.avatarUrl} size="sm" />
                <span className="hidden text-sm font-medium text-text sm:inline">{user.name}</span>
              </span>
            }
            items={[
              { label: "Profile", onSelect: () => (window.location.href = "/profile") },
              { label: "Settings", onSelect: () => (window.location.href = "/settings") },
              { label: "Log out", onSelect: () => void handleLogout(), destructive: true },
            ]}
          />
        )}
      </div>
    </header>
  );
}

export { UserIcon, Settings, LogOut };
