"use client";
import { Suspense, useEffect, useState, useCallback } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { FaArrowLeft, FaArrowRight } from "react-icons/fa";
import Layout from "@/components/Layout";
import { api, Lead, PaginatedLeads, ApiResponse, User } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { API_BASE_URL } from "@/lib/constants";

const STATUS_COLORS: Record<string, string> = {
  new: "bg-[#dbeafe] text-[#1d4ed8]",
  contacted: "bg-[#fef9c3] text-[#a16207]",
  followup: "bg-[#f3e8ff] text-[#7e22ce]",
  converted: "bg-[#dcfce7] text-[#15803d]",
  lost: "bg-[#fee2e2] text-[#b91c1c]",
};

const STATUSES = ["", "new", "contacted", "followup", "converted", "lost"];

const STATUS_LABELS: Record<string, string> = {
  "": "All Statuses",
  new: "New",
  contacted: "Contacted",
  followup: "Follow Up",
  converted: "Converted",
  lost: "Lost",
};

export default function LeadsPage() {
  return (
    <Suspense fallback={null}>
      <LeadsPageContent />
    </Suspense>
  );
}

function LeadsPageContent() {
  const { isAdmin } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const requestedStatus = searchParams.get("status") ?? "";
  const requestedFollowups = searchParams.get("followups") ?? "";
  const [leads, setLeads] = useState<Lead[]>([]);
  const [pagination, setPagination] = useState({ page: 1, last: 1, total: 0 });
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [showTodayFollowups, setShowTodayFollowups] = useState(false);
  const [filterReady, setFilterReady] = useState(false);
  const [loading, setLoading] = useState(true);
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [users, setUsers] = useState<User[]>([]);
  const [assignedTo, setAssignedTo] = useState<string>("");

  useEffect(() => {
    const savedFilter = sessionStorage.getItem("dashboard_lead_filter");
    sessionStorage.removeItem("dashboard_lead_filter");

    const urlStatus = STATUSES.includes(requestedStatus) ? requestedStatus : "";
    const urlToday = requestedFollowups === "today";

    setStatus(
      urlStatus || (savedFilter && STATUSES.includes(savedFilter) ? savedFilter : "")
    );
    setShowTodayFollowups(urlToday || savedFilter === "today");
    setFilterReady(true);
  }, [requestedFollowups, requestedStatus]);

  const fetchLeads = useCallback(
    async (page = 1) => {
      if (!filterReady) return;

      setLoading(true);
      try {
        const params = new URLSearchParams({ page: String(page), per_page: "15" });
        if (search) params.append("search", search);
        if (status) params.append("status", status);
        if (assignedTo) params.append("assigned_to", assignedTo);
        if (showTodayFollowups) params.append("followups_today", "1");
        const res = await api.get<ApiResponse<PaginatedLeads>>(`/leads?${params}`);
        setLeads(res.data.data);
        setPagination({ page: res.data.current_page, last: res.data.last_page, total: res.data.total });
      } finally {
        setLoading(false);
      }
   }, [search, status, assignedTo, showTodayFollowups, filterReady]
  );

  useEffect(() => {
    if (isAdmin) {
      api.get<ApiResponse<User[]>>("/users").then((r) => setUsers(r.data));
    }
  },
   [isAdmin]);


  useEffect(() => {
    fetchLeads(1);
  }, [fetchLeads]);

  async function handleDelete(id: number) {
    if (!confirm("Delete this lead?")) return;
    setDeleteId(id);
    try {
      await api.delete(`/leads/${id}`);
      setLeads((prev) => prev.filter((l) => l.id !== id));
    } finally {
      setDeleteId(null);
    }
  }

  async function handleExportExcel() {
  const params = new URLSearchParams();
  if (search) params.append("search", search);
  if (status) params.append("status", status);
  if (assignedTo) params.append("assigned_to", assignedTo);
  if (showTodayFollowups) params.append("followups_today", "1");
  params.append("export", "excel");

  const token = localStorage.getItem("crm_token");
  const res = await fetch(`${API_BASE_URL}/leads/export?${params}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    },
  });
  const blob = await res.blob();
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "leads.xlsx";
  a.click();
  window.URL.revokeObjectURL(url);
}

  return (
    <Layout>
      <div className="p-4 sm:p-6 lg:p-8">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {showTodayFollowups ? "Today's Follow-ups" : "Leads"}{" "}
              <span className="text-gray-400 font-normal text-lg">({pagination.total})</span>
            </h1>
            {showTodayFollowups && (
              <Link href="/leads" className="text-sm text-blue-600 hover:underline">
                Clear follow-up filter
              </Link>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-3">
            {isAdmin && (
              <button
                onClick={handleExportExcel}
                className="flex-1 sm:flex-none bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
              >
                ⬇ Export Excel
              </button>
            )}
            <Link
              href="/leads/new"
              className="flex-1 sm:flex-none text-center bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
            >
              + Add Lead
            </Link>
          </div>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap gap-3 mb-6">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search name, email, company…"
            className="w-full sm:flex-1 sm:min-w-[200px] sm:max-w-xs px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setShowTodayFollowups(false);
            }}
            className="w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {STATUS_LABELS[s]}
              </option>
            ))}
          </select>

          {/* User filter — admin only */}
          {isAdmin && users.length > 0 && (
            <select
              value={assignedTo}
              onChange={(e) => setAssignedTo(e.target.value)}
              className="w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Users</option>
              {users.map((u) => {
                const count = leads.filter(
                  (l) => l.assigned_to?.id === u.id
                ).length;
                return (
                  <option key={u.id} value={String(u.id)}>
                    {u.name} ({count})
                  </option>
                );
              })}
            </select>
          )}
        </div>

        {/* Table */}
        <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16 gap-2 text-gray-500">
              <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
              Loading…
            </div>
          ) : leads.length === 0 ? (
            <p className="text-center py-16 text-gray-400 text-sm">No leads found.</p>
          ) : (
            <table className="w-full min-w-[760px] text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Company</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Assigned To</th>
                  <th className="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {leads.map((lead) => (
                  <tr
                    key={lead.id}
                    onClick={() => router.push(`/leads/${lead.id}`)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        router.push(`/leads/${lead.id}`);
                      }
                    }}
                    tabIndex={0}
                    className="hover:bg-gray-50 transition-colors cursor-pointer focus:outline-none focus:bg-gray-50"
                  >
                    <td className="px-6 py-4">
                      <Link
                        href={`/leads/${lead.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="font-medium text-gray-900 hover:text-blue-600"
                      >
                        {lead.name}
                      </Link>
                      {lead.email && <p className="text-xs text-gray-400">{lead.email}</p>}
                    </td>
                    <td className="px-6 py-4 text-gray-600">{lead.company ?? "—"}</td>
                    <td className="px-6 py-4">
                      <span className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${STATUS_COLORS[lead.status] ?? "bg-gray-100 text-gray-600"}`}>
                        {lead.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-gray-600">{lead.assigned_to?.name ?? "Unassigned"}</td>
                    <td className="px-6 py-4 text-right space-x-3">
                      <Link
                        href={`/leads/${lead.id}/edit`}
                        onClick={(e) => e.stopPropagation()}
                        className="text-blue-600 hover:underline text-xs font-medium"
                      >
                        Edit
                      </Link>
                      {isAdmin && (
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleDelete(lead.id);
                          }}
                          disabled={deleteId === lead.id}
                          className="text-red-500 hover:underline text-xs font-medium disabled:opacity-40"
                        >
                          {deleteId === lead.id ? "…" : "Delete"}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* Pagination */}
        {pagination.last > 1 && (
          <div className="flex flex-wrap items-center justify-center gap-2 mt-6">
            <button
              disabled={pagination.page === 1}
              onClick={() => fetchLeads(pagination.page - 1)}
              className="inline-flex items-center gap-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50"
            >
              <FaArrowLeft className="w-3 h-3" /> Prev
            </button>
            <span className="text-sm text-gray-500">
              Page {pagination.page} of {pagination.last}
            </span>
            <button
              disabled={pagination.page === pagination.last}
              onClick={() => fetchLeads(pagination.page + 1)}
              className="inline-flex items-center gap-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50"
            >
              Next <FaArrowRight className="w-3 h-3" />
            </button>
          </div>
        )}
      </div>
    </Layout>
  );
}
