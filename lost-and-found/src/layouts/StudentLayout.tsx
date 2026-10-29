import { useState } from "react";
import { Outlet } from "react-router-dom";
import { Bell, Home, LayoutList, PackageSearch, Search, Settings, ShieldCheck } from "lucide-react";
import { Sidebar, type NavItem } from "@/components/layout/Sidebar";
import { TopNavigation } from "@/components/layout/TopNavigation";

const navItems: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: Home },
  { label: "Lost items", href: "/lost-items", icon: PackageSearch },
  { label: "Found items", href: "/found-items", icon: LayoutList },
  { label: "Search", href: "/search", icon: Search },
  { label: "Matches", href: "/matches", icon: ShieldCheck },
  { label: "Claims", href: "/claims", icon: ShieldCheck },
  { label: "Notifications", href: "/notifications", icon: Bell },
  { label: "Settings", href: "/settings", icon: Settings },
];

export function StudentLayout() {
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
