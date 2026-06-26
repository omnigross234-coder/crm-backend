"use client";
import { useEffect, useState, useCallback } from "react";
import { useRouter } from "next/navigation";
import {
  FaChartBar,
  FaCheck,
  FaChevronLeft,
  FaChevronRight,
  FaPhoneAlt,
  FaPhoneSlash,
  FaSyncAlt,
  FaTimes,
} from "react-icons/fa";
import Layout from "@/components/Layout";
import { api, ApiResponse, CallReportResponse, ExecutiveSummary, CallLog } from "@/lib/api";
import { useAuth } from "@/lib/auth";

// ── Helpers ────────────────────────────────────────────────────────────────

function formatSeconds(s: number): string {
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m ${sec}s`;
}

function formatDate(iso: string): string {
  try {
    const d = new Date(iso);
    return d.toLocaleDateString("en-IN", {
      day: "2-digit", month: "short", year: "numeric",
    }) + "  " + d.toLocaleTimeString("en-IN", {
      hour: "2-digit", minute: "2-digit", hour12: true,
    });
  } catch {
    return iso;
  }
}

function dateStr(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function monthStr(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

const MONTH_NAMES = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

// ── Types ──────────────────────────────────────────────────────────────────

type ViewMode = "daily" | "monthly" | "logs";

// ── Main Page ──────────────────────────────────────────────────────────────

export default function PerformancePage() {
  const { isAdmin, loading: authLoading } = useAuth();
  const router = useRouter();

  const [mode, setMode] = useState<ViewMode>("daily");
  const [selectedDate, setSelectedDate] = useState(new Date());
  const [selectedMonth, setSelectedMonth] = useState(() => startOfMonth(new Date()));
  const [summary, setSummary] = useState<ExecutiveSummary[]>([]);
  const [logs, setLogs] = useState<CallLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  // Drawer state
  const [drawerUser, setDrawerUser] = useState<ExecutiveSummary | null>(null);
  const [drawerLogs, setDrawerLogs] = useState<CallLog[]>([]);

  // Redirect non-admins
  useEffect(() => {
    if (!authLoading && !isAdmin) router.replace("/dashboard");
  }, [authLoading, isAdmin, router]);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (mode === "daily") params.set("date", dateStr(selectedDate));
      if (mode === "monthly") params.set("month", monthStr(selectedMonth));

      const res = await api.get<ApiResponse<CallReportResponse>>(
        `/call-logs?${params}`
      );
      setSummary(res.data.summary ?? []);
      setLogs(res.data.logs ?? []);
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : "Failed to load data");
    } finally {
      setLoading(false);
    }
  }, [mode, selectedDate, selectedMonth]);

  useEffect(() => {
    if (!authLoading && isAdmin) fetchData();
  }, [authLoading, isAdmin, fetchData]);

  // Open drawer for a specific executive
  function openDrawer(exec: ExecutiveSummary) {
    setDrawerUser(exec);
    setDrawerLogs(logs.filter((l) => l.user?.id === exec.user_id));
  }

  const displayedSummary = [...summary];
  const summarizedUserIds = new Set(summary.map((item) => item.user_id));
  const missingSummaries = new Map<number, ExecutiveSummary>();

  for (const log of logs) {
    if (!log.user || summarizedUserIds.has(log.user.id)) continue;

    const item = missingSummaries.get(log.user.id) ?? {
      user_id: log.user.id,
      user_name: log.user.name,
      total_seconds: 0,
      call_count: 0,
    };

    if (log.is_connected) {
      item.total_seconds += log.duration_seconds;
      item.call_count += 1;
    }

    missingSummaries.set(log.user.id, item);
  }

  displayedSummary.push(...missingSummaries.values());

  const maxSeconds = displayedSummary.length > 0
    ? Math.max(...displayedSummary.map((s) => s.total_seconds), 1)
    : 1;

  const sorted = [...displayedSummary].sort((a, b) => b.total_seconds - a.total_seconds);

  // Total stats across all executives
  const totalCalls = logs.length;
  const connectedCalls = logs.filter((l) => l.is_connected).length;
  const totalSeconds = summary.reduce((a, b) => a + b.total_seconds, 0);

  return (
    <Layout>
      <div className="p-4 sm:p-6 lg:p-8">

        {/* ── Header ── */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Executive Performance</h1>
            <p className="text-sm text-gray-500 mt-0.5">Call tracking & activity report</p>
          </div>
          <button
            onClick={fetchData}
            className="flex items-center gap-2 px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
          >
            <FaSyncAlt className="w-3.5 h-3.5" /> Refresh
          </button>
        </div>

        {/* ── Tab bar ── */}
        <div className="flex gap-2 mb-6 overflow-x-auto pb-1">
          {(["daily", "monthly", "logs"] as ViewMode[]).map((m) => (
            <button
              key={m}
              onClick={() => setMode(m)}
              className={`flex-shrink-0 px-5 py-2 rounded-full text-sm font-medium transition-colors ${
                mode === m
                  ? "bg-[#006A68] text-white"
                  : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"
              }`}
            >
              {m === "daily" ? "Daily" : m === "monthly" ? "Monthly" : "All Logs"}
            </button>
          ))}
        </div>

        {/* ── Date navigator (daily / monthly) ── */}
        {mode !== "logs" && (
          <div className="flex items-center justify-between gap-4 mb-6 bg-white border border-gray-200 rounded-xl px-4 sm:px-6 py-3 w-full sm:w-fit">
            <button
              onClick={() => {
                if (mode === "daily") {
                  const d = new Date(selectedDate);
                  d.setDate(d.getDate() - 1);
                  setSelectedDate(d);
                } else {
                  setSelectedMonth(new Date(
                    selectedMonth.getFullYear(),
                    selectedMonth.getMonth() - 1,
                    1
                  ));
                }
              }}
              className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-600 text-lg"
            >
              <FaChevronLeft className="w-3.5 h-3.5" />
            </button>
            <span className="font-semibold text-[#006A68] min-w-[130px] text-center">
              {mode === "daily"
                ? selectedDate.toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" })
                : `${MONTH_NAMES[selectedMonth.getMonth()]} ${selectedMonth.getFullYear()}`}
            </span>
            <button
              onClick={() => {
                if (mode === "daily") {
                  const d = new Date(selectedDate);
                  d.setDate(d.getDate() + 1);
                  if (d <= new Date()) setSelectedDate(d);
                } else {
                  const d = new Date(
                    selectedMonth.getFullYear(),
                    selectedMonth.getMonth() + 1,
                    1
                  );
                  if (d <= startOfMonth(new Date())) setSelectedMonth(d);
                }
              }}
              className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-600 text-lg"
            >
              <FaChevronRight className="w-3.5 h-3.5" />
            </button>
          </div>
        )}

        {/* ── Error ── */}
        {error && (
          <div className="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
            {error}
          </div>
        )}

        {/* ── Loading ── */}
        {loading ? (
          <div className="flex items-center justify-center py-24 gap-2 text-gray-400">
            <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
            Loading…
          </div>
        ) : mode === "logs" ? (
          // ── All Logs view ──
          <AllLogsView logs={logs} />
        ) : (
          <>
            {/* ── Summary stat chips ── */}
            {displayedSummary.length > 0 && (
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <StatChip
                  label="Total Talk Time"
                  value={formatSeconds(totalSeconds)}
                  color="text-[#006A68]"
                  bg="bg-blue-50"
                />
                <StatChip
                  label="Total Calls"
                  value={String(totalCalls)}
                  color="text-purple-700"
                  bg="bg-purple-50"
                />
                <StatChip
                  label="Connected"
                  value={`${connectedCalls} / ${totalCalls}`}
                  color="text-green-700"
                  bg="bg-green-50"
                />
              </div>
            )}

            {/* ── Executive leaderboard ── */}
            {sorted.length === 0 ? (
              <EmptyState />
            ) : (
              <div className="space-y-4">
                {sorted.map((exec, i) => (
                  <ExecutiveCard
                    key={exec.user_id}
                    exec={exec}
                    rank={i + 1}
                    maxSeconds={maxSeconds}
                    logs={logs}
                    onClick={() => openDrawer(exec)}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>

      {/* ── Drawer ── */}
      {drawerUser && (
        <ExecutiveDrawer
          exec={drawerUser}
          logs={drawerLogs}
          onClose={() => setDrawerUser(null)}
        />
      )}
    </Layout>
  );
}

// ── Stat chip ──────────────────────────────────────────────────────────────

function StatChip({ label, value, color, bg }: {
  label: string; value: string; color: string; bg: string;
}) {
  return (
    <div className={`${bg} rounded-xl p-4 border border-white`}>
      <p className="text-xs text-gray-500 mb-1">{label}</p>
      <p className={`text-2xl font-bold ${color}`}>{value}</p>
    </div>
  );
}

// ── Executive card ─────────────────────────────────────────────────────────

function ExecutiveCard({
  exec, rank, maxSeconds, logs, onClick,
}: {
  exec: ExecutiveSummary;
  rank: number;
  maxSeconds: number;
  logs: CallLog[];
  onClick: () => void;
}) {
  const pct = maxSeconds > 0 ? (exec.total_seconds / maxSeconds) * 100 : 0;
  const userLogs = logs.filter((l) => l.user?.id === exec.user_id);
  const connected = userLogs.filter((l) => l.is_connected).length;
  const notConnected = userLogs.length - connected;

  const rankColors = [
    "bg-yellow-100 text-yellow-700 border-yellow-200",
    "bg-gray-100 text-gray-600 border-gray-200",
    "bg-orange-100 text-orange-600 border-orange-200",
  ];
  const barColors = ["bg-yellow-400", "bg-blue-500", "bg-green-500"];
  const rankStyle = rankColors[rank - 1] ?? "bg-blue-50 text-blue-600 border-blue-100";
  const barColor = barColors[rank - 1] ?? "bg-blue-400";

  return (
    <button
      type="button"
      onClick={onClick}
      className="w-full text-left bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md hover:-translate-y-0.5 transition-all"
    >
      <div className="flex flex-wrap sm:flex-nowrap items-center gap-4">
        {/* Rank badge */}
        <div className={`w-10 h-10 rounded-full border flex items-center justify-center font-bold text-sm flex-shrink-0 ${rankStyle}`}>
          #{rank}
        </div>

        {/* Avatar */}
        <div className="w-10 h-10 rounded-full bg-[#006A68] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
          {exec.user_name[0]?.toUpperCase()}
        </div>

        {/* Name + call count */}
        <div className="flex-1 min-w-0">
          <p className="font-semibold text-gray-900 truncate">{exec.user_name}</p>
          <p className="text-xs text-gray-500">{exec.call_count} connected call{exec.call_count !== 1 ? "s" : ""}</p>
        </div>

        {/* Talk time */}
        <div className="text-left sm:text-right flex-shrink-0 ml-14 sm:ml-0">
          <p className="font-bold text-[#006A68] text-lg">{formatSeconds(exec.total_seconds)}</p>
          <p className="text-xs text-gray-400">talk time</p>
        </div>
      </div>

      {/* Progress bar */}
      <div className="mt-4 h-2 bg-gray-100 rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full transition-all ${barColor}`}
          style={{ width: `${pct}%` }}
        />
      </div>

      {/* Connected / Not connected pills */}
      <div className="flex flex-wrap gap-3 mt-3">
        <span className="flex items-center gap-1.5 text-xs text-green-700 bg-green-50 px-2.5 py-1 rounded-full">
          <span className="w-1.5 h-1.5 rounded-full bg-green-500 inline-block" />
          {connected} connected
        </span>
        <span className="flex items-center gap-1.5 text-xs text-red-600 bg-red-50 px-2.5 py-1 rounded-full">
          <span className="w-1.5 h-1.5 rounded-full bg-red-400 inline-block" />
          {notConnected} not connected
        </span>
        <span className="sm:ml-auto inline-flex items-center gap-1 text-xs text-gray-400">
          Click for details <FaChevronRight className="w-2.5 h-2.5" />
        </span>
      </div>
    </button>
  );
}

// ── All Logs view ──────────────────────────────────────────────────────────

function AllLogsView({ logs }: { logs: CallLog[] }) {
  if (logs.length === 0) return <EmptyState />;

  return (
    <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table className="w-full min-w-[760px] text-sm">
        <thead className="bg-gray-50 border-b border-gray-200">
          <tr>
            <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Executive</th>
            <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Lead</th>
            <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Date & Time</th>
            <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Duration</th>
            <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {logs.map((log) => (
            <tr key={log.id} className="hover:bg-gray-50">
              <td className="px-6 py-3">
                <div className="flex items-center gap-2">
                  <div className="w-7 h-7 rounded-full bg-[#006A68] flex items-center justify-center text-white text-xs font-bold">
                    {log.user?.name?.[0]?.toUpperCase() ?? "?"}
                  </div>
                  <span className="font-medium text-gray-800">{log.user?.name ?? "—"}</span>
                </div>
              </td>
              <td className="px-6 py-3 text-gray-600">{log.lead?.name ?? "—"}</td>
              <td className="px-6 py-3 text-gray-500 text-xs">{formatDate(log.called_at)}</td>
              <td className="px-6 py-3 text-gray-700 font-medium">
                {log.is_connected ? formatSeconds(log.duration_seconds) : "—"}
              </td>
              <td className="px-6 py-3">
                {log.is_connected ? (
                  <span className="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-full bg-green-100 text-green-700">
                    <FaCheck className="w-2.5 h-2.5" /> Connected
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-full bg-red-100 text-red-600">
                    <FaTimes className="w-2.5 h-2.5" /> Not Connected
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ── Executive detail drawer ────────────────────────────────────────────────

function ExecutiveDrawer({
  exec, logs, onClose,
}: {
  exec: ExecutiveSummary;
  logs: CallLog[];
  onClose: () => void;
}) {
  const connected = logs.filter((l) => l.is_connected);
  const notConnected = logs.filter((l) => !l.is_connected);
  const connectionRate = logs.length > 0
    ? Math.round((connected.length / logs.length) * 100)
    : 0;

  return (
    <>
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/30 z-40"
        onClick={onClose}
      />

      {/* Drawer panel */}
      <div className="fixed right-0 top-0 h-full w-full sm:max-w-md bg-white shadow-2xl z-50 flex flex-col">

        {/* Drawer header */}
        <div className="flex items-center gap-4 px-6 py-5 border-b border-gray-100 bg-[#006A68]">
          <div className="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-white font-bold text-lg">
            {exec.user_name[0]?.toUpperCase()}
          </div>
          <div className="flex-1">
            <p className="font-bold text-white text-lg">{exec.user_name}</p>
            <p className="text-blue-200 text-sm">Performance Details</p>
          </div>
          <button
            onClick={onClose}
            className="w-8 h-8 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors"
          >
            <FaTimes className="w-3.5 h-3.5" />
          </button>
        </div>

        {/* Stats row */}
        <div className="grid grid-cols-1 min-[380px]:grid-cols-3 gap-3 p-4 border-b border-gray-100">
          <div className="bg-blue-50 rounded-lg p-3 text-center">
            <p className="text-lg font-bold text-[#006A68]">{formatSeconds(exec.total_seconds)}</p>
            <p className="text-xs text-gray-500">Talk Time</p>
          </div>
          <div className="bg-green-50 rounded-lg p-3 text-center">
            <p className="text-lg font-bold text-green-700">{connected.length}</p>
            <p className="text-xs text-gray-500">Connected</p>
          </div>
          <div className="bg-purple-50 rounded-lg p-3 text-center">
            <p className="text-lg font-bold text-purple-700">{connectionRate}%</p>
            <p className="text-xs text-gray-500">Connect Rate</p>
          </div>
        </div>

        {/* Connection rate bar */}
        <div className="px-4 py-3 border-b border-gray-100">
          <div className="flex justify-between text-xs text-gray-500 mb-1.5">
            <span>Connection rate</span>
            <span>{connected.length} of {logs.length} calls</span>
          </div>
          <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
            <div
              className="h-full bg-green-500 rounded-full"
              style={{ width: `${connectionRate}%` }}
            />
          </div>
        </div>

        {/* Call log list */}
        <div className="flex-1 overflow-y-auto">
          {logs.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-full text-gray-400">
              <FaPhoneAlt className="w-10 h-10 mb-3" />
              <p>No calls in this period</p>
            </div>
          ) : (
            <div className="divide-y divide-gray-50">
              {logs.map((log) => (
                <div key={log.id} className="flex items-center gap-3 px-4 py-3">
                  <div className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${
                    log.is_connected ? "bg-green-100" : "bg-red-100"
                  }`}>
                    {log.is_connected ? (
                      <FaPhoneAlt className="w-3.5 h-3.5 text-green-700" />
                    ) : (
                      <FaPhoneSlash className="w-3.5 h-3.5 text-red-600" />
                    )}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-800 truncate">
                      {log.lead?.name ?? "Unknown Lead"}
                    </p>
                    <p className="text-xs text-gray-400">{formatDate(log.called_at)}</p>
                  </div>
                  <div className="text-right flex-shrink-0">
                    {log.is_connected ? (
                      <span className="text-sm font-semibold text-green-700">
                        {formatSeconds(log.duration_seconds)}
                      </span>
                    ) : (
                      <span className="text-xs font-medium text-red-500 bg-red-50 px-2 py-0.5 rounded-full">
                        Not Connected
                      </span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  );
}

// ── Empty state ────────────────────────────────────────────────────────────

function EmptyState() {
  return (
    <div className="flex flex-col items-center justify-center py-24 text-gray-400">
      <FaChartBar className="w-12 h-12 mb-4" />
      <p className="font-medium text-gray-500">No calls recorded for this period</p>
      <p className="text-sm mt-1">Try selecting a different date or month</p>
    </div>
  );
}
