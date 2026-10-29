import { useState } from "react";
import { Outlet } from "react-router-dom";
import {
  ClipboardList,
  Home,
  ListTree,
  ShieldCheck,
  UserCog,
  Users,
} from "lucide-react";
import { Sidebar, type NavItem } from "@/components/layout/Sidebar";
import { TopNavigation } from "@/components/layout/TopNavigation";

const navItems: NavItem[] = [
  { label: "Overview", href: "/admin/dashboard", icon: Home },
  { label: "Users", href: "/admin/users", icon: Users },
  { label: "Students", href: "/admin/students", icon: UserCog },
  { label: "Staff", href: "/admin/staff", icon: UserCog },
  { label: "Lost items", href: "/admin/lost-items", icon: ClipboardList },
  { label: "Found items", href: "/admin/found-items", icon: ClipboardList },
  { label: "Claims", href: "/admin/claims", icon: ShieldCheck },
  { label: "Matches", href: "/admin/matches", icon: ShieldCheck },
  { label: "Categories", href: "/admin/categories", icon: ListTree },
  { label: "Audit logs", href: "/admin/audit-logs", icon: ClipboardList },
];

export function AdminLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="flex min-h-screen bg-bg">
      <Sidebar items={navItems} isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <div className="flex min-w-0 flex-1 flex-col">
        <TopNavigation onMenuClick={() => setSidebarOpen(true)} />
        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
