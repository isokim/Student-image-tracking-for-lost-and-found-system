import { NavLink } from "react-router-dom";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/cn";

export interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
}

export function Sidebar({ items, isOpen, onClose }: { items: NavItem[]; isOpen: boolean; onClose: () => void }) {
  return (
    <>
      {isOpen && (
        <div
          className="fixed inset-0 z-30 bg-navy-900/40 lg:hidden"
          onClick={onClose}
          aria-hidden="true"
        />
      )}
      <nav
        aria-label="Main navigation"
        className={cn(
          "fixed inset-y-0 left-0 z-40 w-60 shrink-0 border-r border-border bg-surface transition-transform lg:static lg:translate-x-0",
          isOpen ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <div className="flex h-14 items-center border-b border-border px-4">
          <span className="text-sm font-semibold text-navy-900">Campus Lost &amp; Found</span>
        </div>
        <ul className="flex flex-col gap-0.5 p-2">
          {items.map((item) => (
            <li key={item.href}>
              <NavLink
                to={item.href}
                end={item.href.split("/").length <= 2}
                onClick={onClose}
                className={({ isActive }) =>
                  cn(
                    "flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-text-muted hover:bg-surface-sunken hover:text-text",
                    isActive && "bg-navy-50 font-medium text-navy-900",
                  )
                }
              >
                <item.icon className="h-4 w-4" aria-hidden="true" />
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </>
  );
}
