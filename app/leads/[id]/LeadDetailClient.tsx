"use client";
import { useEffect, useState, FormEvent } from "react";
import { useParams, usePathname, useRouter } from "next/navigation";
import Link from "next/link";
import { FaArrowLeft } from "react-icons/fa";
import Layout from "@/components/Layout";
import { api, Lead, Followup, User, ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";

const STATUS_COLORS: Record<string, string> = {
  new: "bg-[#dbeafe] text-[#1d4ed8]",
  contacted: "bg-[#fef9c3] text-[#a16207]",
  followup: "bg-[#f3e8ff] text-[#7e22ce]",
  converted: "bg-[#dcfce7] text-[#15803d]",
  lost: "bg-[#fee2e2] text-[#b91c1c]",
};

const STATUSES = ["new", "contacted", "followup", "converted", "lost"];

const LEAD_UPDATE_FIELDS = [
  "name",
  "email",
  "phone",
  "company",
  "source",
  "status",
  "notes",
  "address",
  "city",
  "state",
  "country",
  "pin_code",
  "referral_name",
  "industry_type",
  "business_type",
  "product_service_interested_in",
  "budget",
  "documents",
  "annual_turnover",
  "gst_number",
  "requirement",
] as const;

function getLeadIdFromPath(pathname: string, fallback: string) {
  const match = pathname.match(/\/leads\/([^/]+)(?:\/edit)?\/?$/);
  return match?.[1] ?? fallback;
}

function formatFollowupDate(dateStr: string) {
  try {
    const d = new Date(dateStr);
    return d.toLocaleString("en-IN", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });
  } catch {
    return dateStr;
  }
}

export default function LeadDetailPage() {
  const { id: routeId } = useParams<{ id: string }>();
  const pathname = usePathname();
  const id = getLeadIdFromPath(pathname, routeId);
  const router = useRouter();
  const { isAdmin } = useAuth();

  const [lead, setLead] = useState<Lead | null>(null);
  const [followups, setFollowups] = useState<Followup[]>([]);
  const [salesUsers, setSalesUsers] = useState<User[]>([]);
  const [successMessage, setSuccessMessage] = useState("");
  const [loading, setLoading] = useState(true);
  const [callNotes, setCallNotes] = useState("");
  const [savingCallNotes, setSavingCallNotes] = useState(false);

  // quick-status form
  const [newStatus, setNewStatus] = useState("");
  const [updatingStatus, setUpdatingStatus] = useState(false);

  // assign form
  const [assignTo, setAssignTo] = useState("");
  const [assigning, setAssigning] = useState(false);

  // followup form
  const [note, setNote] = useState("");
  const [nextDate, setNextDate] = useState("");
  const [nextTime, setNextTime] = useState("09:00");
  const [addingFollowup, setAddingFollowup] = useState(false);

  useEffect(() => {
    const message = sessionStorage.getItem("lead_success_message");
    if (!message) return;

    sessionStorage.removeItem("lead_success_message");
    setSuccessMessage(message);
  }, []);

  useEffect(() => {
    Promise.all([
      api.get<ApiResponse<Lead>>(`/leads/${id}`),
      api.get<ApiResponse<Followup[]>>(`/leads/${id}/followups`),
    ]).then(([lr, fr]) => {
      setLead(lr.data);
      setNewStatus(lr.data.status);
      setCallNotes(lr.data.call_notes ?? "");
      setFollowups(fr.data);
    }).finally(() => setLoading(false));

    if (isAdmin) {
      api.get<ApiResponse<User[]>>("/users").then((r) => setSalesUsers(r.data));
    }
  }, [id, isAdmin]);

  async function handleStatusUpdate() {
    if (!lead || newStatus === lead.status) return;
    setUpdatingStatus(true);
    try {
      const res = await api.patch<ApiResponse<Lead>>(`/leads/${id}/status`, { status: newStatus });
      setLead(res.data);
    } finally {
      setUpdatingStatus(false);
    }
  }

  async function handleAssign() {
    if (!assignTo) return;
    setAssigning(true);
    try {
      const res = await api.post<ApiResponse<Lead>>(`/leads/${id}/assign`, { assigned_to: Number(assignTo) });
      setLead(res.data);
    } finally {
      setAssigning(false);
    }
  }

  async function handleSaveCallNotes() {
    if (!lead) return;
    setSavingCallNotes(true);
    try {
      const body = LEAD_UPDATE_FIELDS.reduce<Record<string, unknown>>((acc, field) => {
        acc[field] = lead[field] ?? null;
        return acc;
      }, {});

      const res = await api.put<ApiResponse<Lead>>(`/leads/${id}`, {
        ...body,
        call_notes: callNotes.trim() ? callNotes : null,
      });
      setLead(res.data);
      setCallNotes(res.data.call_notes ?? "");
    } finally {
      setSavingCallNotes(false);
    }
  }

  async function handleAddFollowup(e: FormEvent) {
    e.preventDefault();
    if (!note.trim()) return;
    setAddingFollowup(true);
    try {
      const body: Record<string, string> = { note };
      if (nextDate) {
        // Combine date + time into "yyyy-MM-dd HH:mm:ss" — same format the
        // Flutter app sends and what the Laravel backend expects.
        const [year, month, day] = nextDate.split("-");
        const [hour, minute] = nextTime.split(":");
        body.next_followup_datetime = `${year}-${month}-${day} ${hour}:${minute}:00`;
      }
      await api.post<ApiResponse<Followup>>(`/leads/${id}/followups`, body);
      const followupsRes = await api.get<ApiResponse<Followup[]>>(`/leads/${id}/followups`);
      setFollowups(followupsRes.data);
      setNote("");
      setNextDate("");
      setNextTime("09:00");
    } finally {
      setAddingFollowup(false);
    }
  }

  async function handleDelete() {
    if (!confirm("Delete this lead? This cannot be undone.")) return;
    await api.delete(`/leads/${id}`);
    router.push("/leads");
  }

  if (loading) {
    return (
      <Layout>
        <div className="flex items-center justify-center h-64 gap-2 text-gray-500">
          <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
          Loading…
        </div>
      </Layout>
    );
  }

  if (!lead) {
    return (
      <Layout>
        <div className="p-4 sm:p-6 lg:p-8 text-center text-gray-400">Lead not found.</div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="p-4 sm:p-6 lg:p-8 max-w-4xl">
        {successMessage && (
          <div
            role="status"
            className="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700"
          >
            {successMessage}
          </div>
        )}

        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-6">
          <div>
            <Link href="/leads" className="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline">
              <FaArrowLeft className="w-3 h-3" /> Leads
            </Link>
            <h1 className="text-2xl font-bold text-gray-900 mt-1">{lead.name}</h1>
            {lead.company && <p className="text-gray-500 text-sm">{lead.company}</p>}
          </div>
          <div className="flex flex-wrap gap-2">
            <Link
              href={`/leads/${id}/edit`}
              className="text-sm px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
            >
              Edit
            </Link>
            {isAdmin && (
              <button
                onClick={handleDelete}
                className="text-sm px-4 py-2 bg-red-50 text-red-600 border border-red-200 rounded-lg hover:bg-red-100 transition-colors"
              >
                Delete
              </button>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Left column */}
          <div className="lg:col-span-2 space-y-6">
            {/* Lead info */}
            <div className="bg-white rounded-xl border border-gray-200 p-6">
              <h2 className="font-semibold text-gray-800 mb-4">Contact Info</h2>
              <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                {[
                  ["Email", lead.email ?? "—"],
                  ["Phone", lead.phone ?? "—"],
                  ["Source", lead.source ?? "—"],
                  ["Assigned To", lead.assigned_to?.name ?? "Unassigned"],
                ].map(([label, val]) => (
                  <div key={label}>
                    <dt className="text-xs text-gray-400 mb-0.5">{label}</dt>
                    <dd className="text-gray-800 font-medium">{val}</dd>
                  </div>
                ))}
              </dl>
              {lead.notes && (
                <div className="mt-4 pt-4 border-t border-gray-100">
                  <p className="text-xs text-gray-400 mb-1">Notes</p>
                  <p className="text-sm text-gray-700 whitespace-pre-wrap">{lead.notes}</p>
                </div>
              )}
            </div>

            {/* Call notes */}
            <div className="bg-white rounded-xl border border-gray-200 p-6">
              <h2 className="font-semibold text-gray-800 mb-4">Call Notes</h2>
              <textarea
                value={callNotes}
                onChange={(e) => setCallNotes(e.target.value)}
                rows={4}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Add notes from the latest call..."
              />
              <div className="flex justify-end mt-3">
                <button
                  type="button"
                  onClick={handleSaveCallNotes}
                  disabled={savingCallNotes || callNotes === (lead.call_notes ?? "")}
                  className="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-200 disabled:text-gray-400 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                >
                  {savingCallNotes ? "Saving..." : "Save Call Notes"}
                </button>
              </div>
            </div>

            {/* Followups */}
            <div className="bg-white rounded-xl border border-gray-200">
              <div className="px-6 py-4 border-b border-gray-100">
                <h2 className="font-semibold text-gray-800">Follow-ups</h2>
              </div>

              {/* Add followup form */}
              <form onSubmit={handleAddFollowup} className="px-6 py-4 border-b border-gray-100 space-y-3">
                <textarea
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Add a follow-up note… (required)"
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:items-center">
                  {/* Date picker */}
                  <input
                    type="date"
                    value={nextDate}
                    min={new Date().toISOString().split("T")[0]}
                    onChange={(e) => {
                      setNextDate(e.target.value);
                      if (!e.target.value) setNextTime("09:00");
                    }}
                    className="w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  {/* Time picker — only shown when a date is selected */}
                  {nextDate && (
                    <input
                      type="time"
                      value={nextTime}
                      onChange={(e) => setNextTime(e.target.value)}
                      className="w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  )}
                  <button
                    type="submit"
                    disabled={addingFollowup || !note.trim()}
                    className="sm:ml-auto bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                  >
                    {addingFollowup ? "Adding…" : "Add"}
                  </button>
                </div>
                {/* Reminder summary chip — shown when both date and time are set, mirrors Flutter */}
                {nextDate && nextTime && (
                  <div className="flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-xs font-medium px-3 py-2 rounded-lg">
                    🔔 Reminder will be sent 10 minutes before:{" "}
                    {new Date(`${nextDate}T${nextTime}`).toLocaleString("en-IN", {
                      day: "2-digit",
                      month: "short",
                      year: "numeric",
                      hour: "2-digit",
                      minute: "2-digit",
                      hour12: true,
                    })}
                  </div>
                )}
              </form>

              {/* Followup list */}
              <div className="divide-y divide-gray-50">
                {followups.length === 0 && (
                  <p className="px-6 py-8 text-center text-gray-400 text-sm">No follow-ups yet.</p>
                )}
                {followups.map((fu) => (
                  <div key={fu.id} className="px-6 py-4">
                    <div className="flex items-center gap-2 mb-1 flex-wrap">
                      <span className={`text-xs font-medium px-2 py-0.5 rounded-full capitalize ${STATUS_COLORS[fu.status] ?? "bg-gray-100 text-gray-600"}`}>
                        {fu.status}
                      </span>
                      <span className="text-xs text-gray-400">
                        {new Date(fu.created_at).toLocaleDateString("en-IN", {
                          day: "2-digit", month: "short", year: "numeric"
                        })}
                      </span>
                      {fu.next_followup_datetime && (
                        <span className="text-xs font-medium text-orange-500 bg-orange-50 px-2 py-0.5 rounded-full">
                          📅 Next: {formatFollowupDate(fu.next_followup_datetime)}
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-gray-700">{fu.note}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Right column */}
          <div className="space-y-4">
            {/* Status */}
            <div className="bg-white rounded-xl border border-gray-200 p-5">
              <h3 className="text-sm font-semibold text-gray-700 mb-3">Status</h3>
              <div className="space-y-2">
                <select
                  value={newStatus}
                  onChange={(e) => setNewStatus(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  {STATUSES.map((s) => (
                    <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                  ))}
                </select>
                <button
                  onClick={handleStatusUpdate}
                  disabled={updatingStatus || newStatus === lead.status}
                  className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-200 disabled:text-gray-400 text-white text-sm font-medium py-2 rounded-lg transition-colors"
                >
                  {updatingStatus ? "Saving…" : "Update status"}
                </button>
              </div>
            </div>

            {/* Assign (admin only) */}
            {isAdmin && salesUsers.length > 0 && (
              <div className="bg-white rounded-xl border border-gray-200 p-5">
                <h3 className="text-sm font-semibold text-gray-700 mb-3">Assign To</h3>
                <div className="space-y-2">
                  <select
                    value={assignTo}
                    onChange={(e) => setAssignTo(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">— Select user —</option>
                    {salesUsers.map((u) => (
                      <option key={u.id} value={u.id}>{u.name}</option>
                    ))}
                  </select>
                  <button
                    onClick={handleAssign}
                    disabled={assigning || !assignTo}
                    className="w-full bg-gray-800 hover:bg-gray-900 disabled:bg-gray-200 disabled:text-gray-400 text-white text-sm font-medium py-2 rounded-lg transition-colors"
                  >
                    {assigning ? "Assigning…" : "Assign"}
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </Layout>
  );
}
