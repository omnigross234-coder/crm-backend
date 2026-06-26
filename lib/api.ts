import { API_BASE_URL } from "./constants";

function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("crm_token");
}

function getErrorMessage(json: { message?: string; data?: unknown }, fallback: string) {
  if (json.data && typeof json.data === "object") {
    const errors = Object.values(json.data as Record<string, unknown>);
    const first = errors[0];
    if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    if (typeof first === "string") return first;
  }

  return json.message ?? fallback;
}

async function request<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...(options.headers as Record<string, string>),
  };
  if (token) headers["Authorization"] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}${path}`, { ...options, headers });
  const json = await res.json();

  if (!res.ok) {
    throw new Error(getErrorMessage(json, `Request failed: ${res.status}`));
  }
  return json as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body: unknown) =>
    request<T>(path, { method: "POST", body: JSON.stringify(body) }),
  put: <T>(path: string, body: unknown) =>
    request<T>(path, { method: "PUT", body: JSON.stringify(body) }),
  patch: <T>(path: string, body: unknown) =>
    request<T>(path, { method: "PATCH", body: JSON.stringify(body) }),
  delete: <T>(path: string) => request<T>(path, { method: "DELETE" }),
};

// ── Types ──────────────────────────────────────────────────────────────────

export interface User {
  id: number;
  name: string;
  email: string;
  role: "admin" | "sales";
  phone: string | null;         
  status: "active" | "inactive";
}

export interface Lead {
  [key: string]: unknown;
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  company: string | null;
  source: string | null;
  status: "new" | "contacted" | "followup" | "converted" | "lost";
  notes: string | null;
  address?: string | null;
  city?: string | null;
  state?: string | null;
  country?: string | null;
  pin_code?: string | null;
  referral_name?: string | null;
  industry_type?: string | null;
  business_type?: string | null;
  product_service_interested_in?: string | null;
  budget?: string | null;
  documents?: string | null;
  annual_turnover?: string | null;
  gst_number?: string | null;
  requirement?: string | null;
  call_notes: string | null;
  assigned_to: User | null;
  created_at: string;
  updated_at: string;
}

export interface Followup {
  id: number;
  lead_id: number;
  note: string;
  status: string;
  next_followup_datetime: string | null;
  created_at: string;
}

export interface DashboardStats {
  total_leads: number;
  new_leads: number;
  followup_leads: number;
  converted: number;
  followups_today: number;
  recent_leads: Lead[];
}

export interface PaginatedLeads {
  data: Lead[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
}
export interface CallLog {
  id: number;
  lead_id: number;
  user_id: number;
  called_at: string;
  duration_seconds: number;
  is_connected: boolean;
  lead: { id: number; name: string } | null;
  user: { id: number; name: string } | null;
}

export interface ExecutiveSummary {
  user_id: number;
  user_name: string;
  total_seconds: number;
  call_count: number;
}

export interface CallReportResponse {
  summary: ExecutiveSummary[];
  logs: CallLog[];
}
