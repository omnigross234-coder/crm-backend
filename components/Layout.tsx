"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, ReactNode } from "react";
import { FaChartLine, FaChartPie, FaListAlt, FaShieldAlt, FaUsers, FaCloudDownloadAlt } from "react-icons/fa";
import type { IconType } from "react-icons";
import BrandLogo from "@/components/BrandLogo";
import { useAuth } from "@/lib/auth";

const navItems: { href: string; label: string; icon: IconType }[] = [
  { href: "/dashboard", label: "Dashboard", icon: FaChartPie },
  { href: "/leads", label: "Leads", icon: FaUsers },
];

const adminItems: { href: string; label: string; icon: IconType }[] = [
  { href: "/admin/users",       label: "Users",       icon: FaShieldAlt },
  { href: "/admin/lead-fields",  label: "Lead Fields", icon: FaListAlt },
  { href: "/admin/backups",      label: "Backups",     icon: FaCloudDownloadAlt },
  { href: "/performance",       label: "Performance", icon: FaChartLine },
];

export default function Layout({ children }: { children: ReactNode }) {
  const { user, loading, logout, isAdmin } = useAuth();
  const pathname = usePathname();
  const router = useRouter();

  useEffect(() => {
    if (!loading && !user) router.push("/login");
  }, [loading, user, router]);

  if (loading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col md:flex-row bg-gray-50">
      {/* Sidebar */}
      <aside className="w-full md:w-60 bg-white border-b md:border-b-0 md:border-r border-gray-200 flex flex-col md:min-h-screen">
        <div className="h-16 flex items-center px-4 sm:px-6 border-b border-gray-200">
          <BrandLogo compact />
        </div>

        <nav className="flex md:flex-col md:flex-1 gap-2 md:gap-0 overflow-x-auto md:overflow-visible p-3 md:p-4 md:space-y-1">
          {navItems.map((item) => {
            const Icon = item.icon;
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`flex flex-shrink-0 md:flex-shrink items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                  pathname.startsWith(item.href)
                    ? "bg-blue-50 text-blue-700"
                    : "text-gray-600 hover:bg-gray-100"
                }`}
              >
                <Icon className="w-4 h-4 flex-shrink-0" />
                {item.label}
              </Link>
            );
          })}

          {isAdmin && (
            <>
              <div className="hidden md:block pt-4 pb-1 px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">
                Admin
              </div>
              {adminItems.map((item) => {
                const Icon = item.icon;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={`flex flex-shrink-0 md:flex-shrink items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                      pathname.startsWith(item.href)
                        ? "bg-blue-50 text-blue-700"
                        : "text-gray-600 hover:bg-gray-100"
                    }`}
                  >
                    <Icon className="w-4 h-4 flex-shrink-0" />
                    {item.label}
                  </Link>
                );
              })}
            </>
          )}
        </nav>

        <div className="hidden md:block p-4 border-t border-gray-200">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-bold">
              {user.name[0].toUpperCase()}
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-gray-800 truncate">{user.name}</p>
              <p className="text-xs text-gray-500 capitalize">{user.role}</p>
            </div>
          </div>
          <button
            onClick={logout}
            className="w-full text-sm text-gray-500 hover:text-red-600 text-left px-3 py-1.5 rounded-lg hover:bg-red-50 transition-colors"
          >
            Sign out
          </button>
        </div>
      </aside>

      {/* Main */}
      <main className="flex-1 min-w-0 overflow-auto">
        {children}
      </main>
    </div>
  );
}
