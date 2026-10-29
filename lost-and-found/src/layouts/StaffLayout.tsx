import { useState } from "react";
import { Outlet } from "react-router-dom";
import { CheckSquare, Home, PackageCheck } from "lucide-react";
import { Sidebar, type NavItem } from "@/components/layout/Sidebar";
import { TopNavigation } from "@/components/layout/TopNavigation";

const navItems: NavItem[] = [
  { label: "Dashboard", href: "/staff/dashboard", icon: Home },
  { label: "Found items", href: "/staff/found-items", icon: PackageCheck },
  { label: "Claims", href: "/staff/claims", icon: CheckSquare },
];

export function StaffLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="flex min-h-screen bg-bg">
      <Sidebar items={navItems} isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <div className="flex min-w-0 flex-1 flex-col">
        <TopNavigation onMenuClick={() => setSidebarOpen(true)} />
        <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
