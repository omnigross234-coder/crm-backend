"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { FaArrowRight } from "react-icons/fa";
import Layout from "@/components/Layout";
import { api, DashboardStats, ApiResponse, Lead, PaginatedLeads } from "@/lib/api";
import { useAuth } from "@/lib/auth";

const STATUS_COLORS: Record<string, string> = {
  new: "bg-[#dbeafe] text-[#1d4ed8]",
  contacted: "bg-[#fef9c3] text-[#a16207]",
  followup: "bg-[#f3e8ff] text-[#7e22ce]",
  converted: "bg-[#dcfce7] text-[#15803d]",
  lost: "bg-[#fee2e2] text-[#b91c1c]",
};

function StatCard({
  label,
  value,
  color,
  href,
  filter,
}: {
  label: string;
  value: number;
  color: string;
  href: string;
  filter: string;
}) {
  const card = (
    <div className="h-full bg-white rounded-xl border border-gray-200 p-6">
      <p className="text-sm text-gray-500">{label}</p>
      <p className={`text-3xl font-bold mt-1 ${color}`}>{value}</p>
    </div>
  );

  return (
    <Link
      href={href}
      onClick={() => sessionStorage.setItem("dashboard_lead_filter", filter)}
      className="block hover:-translate-y-0.5 transition-transform"
    >
      {card}
    </Link>
  );
}

export default function DashboardPage() {
  const { user, isAdmin, loading: authLoading } = useAuth();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [todayFollowups, setTodayFollowups] = useState<Lead[]>([]);
  const [showTodayFollowups, setShowTodayFollowups] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (authLoading || !user) {
      if (!authLoading) setLoading(false);
      return;
    }

    const followupParams = new URLSearchParams({
      followups_today: "1",
      per_page: "50",
    });

    if (!isAdmin && user?.id) {
      followupParams.set("assigned_to", String(user.id));
    }

    Promise.all([
      api.get<ApiResponse<DashboardStats>>("/dashboard/stats"),
      api.get<ApiResponse<PaginatedLeads>>(`/leads?${followupParams}`),
    ])
      .then(([statsRes, followupsRes]) => {
        setStats(statsRes.data);
        setTodayFollowups(followupsRes.data.data);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [authLoading, isAdmin, user?.id]);

  return (
    <Layout>
      <div className="p-4 sm:p-6 lg:p-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Dashboard</h1>

        {loading && (
          <div className="flex items-center gap-2 text-gray-500">
            <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
            Loading…
          </div>
        )}

        {error && (
          <div className="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
            {error}
          </div>
        )}

        {stats && (
          <>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
              <StatCard label="Total Leads" value={stats.total_leads} color="text-gray-900" href="/leads/" filter="all" />
              <StatCard label="New" value={stats.new_leads} color="text-blue-600" href="/leads/?status=new" filter="new" />
              <StatCard label="Follow-up" value={stats.followup_leads} color="text-purple-600" href="/leads/?status=followup" filter="followup" />
              <StatCard label="Converted" value={stats.converted} color="text-green-600" href="/leads/?status=converted" filter="converted" />
              <StatCard
                label="Follow-ups Today"
                value={stats.followups_today}
                color="text-orange-600"
                href="/leads/?followups=today"
                filter="today"
              />
            </div>

            {showTodayFollowups && (
              <div className="bg-white rounded-xl border border-gray-200 mb-8">
                <div className="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                  <h2 className="font-semibold text-gray-800">
                    Today&apos;s Follow-ups
                    <span className="text-sm font-normal text-gray-400 ml-2">
                      {new Date().toLocaleDateString("en-IN", {
                        day: "2-digit",
                        month: "short",
                        year: "numeric",
                      })}
                    </span>
                  </h2>
                  <button
                    type="button"
                    onClick={() => setShowTodayFollowups(false)}
                    className="text-sm text-gray-500 hover:text-gray-700"
                  >
                    Hide
                  </button>
                </div>
                <div className="divide-y divide-gray-50">
                  {todayFollowups.length === 0 && (
                    <p className="px-6 py-8 text-center text-gray-400 text-sm">
                      No follow-ups scheduled for today
                    </p>
                  )}
                  {todayFollowups.map((lead) => (
                    <Link
                      key={lead.id}
                      href={`/leads/${lead.id}`}
                      className="flex items-center gap-4 px-4 sm:px-6 py-4 hover:bg-gray-50 transition-colors"
                    >
                      <div className="w-9 h-9 rounded-full bg-orange-100 flex items-center justify-center text-orange-700 font-semibold text-sm">
                        {lead.name[0].toUpperCase()}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-gray-900 truncate">{lead.name}</p>
                        <p className="text-xs text-gray-500 truncate">
                          {lead.company ?? lead.phone ?? lead.email ?? "â€”"}
                        </p>
                      </div>
                      <span
                        className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${
                          STATUS_COLORS[lead.status] ?? "bg-gray-100 text-gray-600"
                        }`}
                      >
                        {lead.status}
                      </span>
                    </Link>
                  ))}
                </div>
              </div>
            )}

            <div className="bg-white rounded-xl border border-gray-200">
              <div className="px-4 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <h2 className="font-semibold text-gray-800">Recent Leads</h2>
                <Link href="/leads" className="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline">
                  View all <FaArrowRight className="w-3 h-3" />
                </Link>
              </div>
              <div className="divide-y divide-gray-50">
                {stats.recent_leads.length === 0 && (
                  <p className="px-6 py-8 text-center text-gray-400 text-sm">No leads yet</p>
                )}
                {stats.recent_leads.map((lead: Lead) => (
                  <Link
                    key={lead.id}
                    href={`/leads/${lead.id}`}
                    className="flex items-center gap-4 px-4 sm:px-6 py-4 hover:bg-gray-50 transition-colors"
                  >
                    <div className="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-semibold text-sm">
                      {lead.name[0].toUpperCase()}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">{lead.name}</p>
                      <p className="text-xs text-gray-500 truncate">{lead.company ?? lead.email ?? "—"}</p>
                    </div>
                    <span
                      className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${
                        STATUS_COLORS[lead.status] ?? "bg-gray-100 text-gray-600"
                      }`}
                    >
                      {lead.status}
                    </span>
                  </Link>
                ))}
              </div>
            </div>
          </>
        )}
      </div>
    </Layout>
  );
}
