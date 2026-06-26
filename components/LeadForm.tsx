"use client";
import { useEffect, useState, FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Lead, api, ApiResponse } from "@/lib/api";
import {
  DEFAULT_LEAD_FIELD_SETTINGS,
  LeadExtraFieldKey,
  fetchLeadFieldSettings,
} from "@/lib/leadFieldConfig";

const STATUSES = ["new", "contacted", "followup", "converted", "lost"];
const SOURCES = ["", "website", "referral", "cold_call", "social", "other"];
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const NAME_PATTERN = /^[\p{L}\s.'-]+$/u;

const inputClass =
  "w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100";
const labelClass = "block text-sm font-semibold text-gray-700 mb-1.5";
const sectionTitleClass = "text-sm font-bold uppercase tracking-wide text-blue-700";

interface Props {
  initial?: Partial<Lead>;
  leadId?: number;
}

type LeadFormState = Record<string, string>;

export default function LeadForm({ initial, leadId }: Props) {
  const router = useRouter();
  const [fieldSettings, setFieldSettings] = useState(DEFAULT_LEAD_FIELD_SETTINGS);
  const [form, setForm] = useState<LeadFormState>({
    name: initial?.name ?? "",
    email: initial?.email ?? "",
    phone: initial?.phone ?? "",
    company: initial?.company ?? "",
    source: initial?.source ?? "",
    status: initial?.status ?? "new",
    notes: initial?.notes ?? "",
    address: initial?.address ?? "",
    city: initial?.city ?? "",
    state: initial?.state ?? "",
    country: initial?.country ?? "",
    pin_code: initial?.pin_code ?? "",
    referral_name: initial?.referral_name ?? "",
    industry_type: initial?.industry_type ?? "",
    business_type: initial?.business_type ?? "",
    product_service_interested_in: initial?.product_service_interested_in ?? "",
    budget: initial?.budget ?? "",
    documents: initial?.documents ?? "",
    annual_turnover: initial?.annual_turnover ?? "",
    gst_number: initial?.gst_number ?? "",
    requirement: initial?.requirement ?? "",
  });
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetchLeadFieldSettings().then((settings) => {
      setFieldSettings(settings);
      setForm((prev) => {
        const initialRecord = initial as Record<string, unknown> | undefined;
        const next = { ...prev };
        for (const setting of settings) {
          if (next[setting.key] === undefined) {
            const value = initialRecord?.[setting.key];
            next[setting.key] = typeof value === "string" ? value : "";
          }
        }
        return next;
      });
    });
  }, [initial]);

  function set(field: string, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function validate(): string | null {
    const name = form.name.trim();
    const phone = form.phone.trim();
    const email = form.email.trim();
    const company = form.company.trim();
    const notes = form.notes.trim();

    if (!name) return "Name is required.";
    if (name.length < 2) return "Name must be at least 2 characters.";
    if (name.length > 80) return "Name must be 80 characters or less.";
    if (!NAME_PATTERN.test(name)) {
      return "Name can contain letters, spaces, apostrophes, hyphens, and periods only.";
    }
    if (!phone) return "Phone is required.";
    if (!/^\d{10}$/.test(phone)) return "Phone must contain exactly 10 digits.";
    if (email && !EMAIL_PATTERN.test(email)) return "Enter a valid email address.";
    if (email.length > 255) return "Email must be 255 characters or less.";
    if (company.length > 100) return "Company must be 100 characters or less.";
    if (!form.source) return "Please select a source.";
    if (notes.length > 500) return "Notes must be 500 characters or less.";

    for (const setting of fieldSettings) {
      if (!setting.active) continue;

      const value = form[setting.key]?.trim() ?? "";
      if (setting.required && !value) return `${setting.label} is required.`;
      if (value && setting.type === "number" && !Number.isFinite(Number(value))) {
        return `${setting.label} must be a valid number.`;
      }
      if (value && setting.type !== "textarea" && value.length > 255) {
        return `${setting.label} must be 255 characters or less.`;
      }
    }

    return null;
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const validationError = validate();
    if (validationError) return setError(validationError);

    setError("");
    setSaving(true);
    try {
      const extraFields = fieldSettings
        .filter((setting) => setting.active)
        .reduce<Record<LeadExtraFieldKey, string | null>>((acc, setting) => {
          acc[setting.key] = form[setting.key].trim() || null;
          return acc;
        }, {} as Record<LeadExtraFieldKey, string | null>);

      const body = {
        name: form.name.trim(),
        email: form.email.trim() || null,
        phone: form.phone.trim(),
        company: form.company.trim() || null,
        source: form.source,
        status: form.status,
        notes: form.notes.trim() || null,
        ...extraFields,
      };

      if (leadId) {
        await api.put<ApiResponse<Lead>>(`/leads/${leadId}`, body);
        sessionStorage.setItem("lead_success_message", "Lead updated successfully.");
        router.push(`/leads/${leadId}`);
      } else {
        const res = await api.post<ApiResponse<Lead>>("/leads", body);
        sessionStorage.setItem("lead_success_message", "Lead added successfully.");
        router.push(`/leads/${res.data.id}`);
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Save failed");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-8">
      {error && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
          {error}
        </div>
      )}

      <section className="rounded-2xl border border-gray-100 bg-gray-50/70 p-5 sm:p-6">
        <div className="mb-5 flex flex-col gap-3 border-b border-gray-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className={sectionTitleClass}>Lead Details</p>
            <p className="mt-1 text-sm text-gray-500">Primary contact and pipeline information.</p>
          </div>
          <span className="w-fit rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
            Required fields marked *
          </span>
        </div>

        <div className="grid grid-cols-1 gap-5 lg:grid-cols-12">
          <div className="lg:col-span-6">
            <label className={labelClass}>
              Name <span className="text-red-500">*</span>
            </label>
            <input
              value={form.name}
              onChange={(e) =>
                set("name", e.target.value.replace(/[^\p{L}\s.'-]/gu, ""))
              }
              required
              minLength={2}
              maxLength={80}
              className={inputClass}
              placeholder="John Doe"
            />
          </div>

          <div className="lg:col-span-3">
            <label className={labelClass}>Email</label>
            <input
              type="email"
              value={form.email}
              onChange={(e) => set("email", e.target.value)}
              maxLength={255}
              className={inputClass}
              placeholder="name@company.com"
            />
          </div>

          <div className="lg:col-span-3">
            <label className={labelClass}>Phone</label>
            <input
              value={form.phone}
              onChange={(e) => set("phone", e.target.value.replace(/\D/g, "").slice(0, 10))}
              inputMode="numeric"
              autoComplete="tel"
              pattern="[0-9]{10}"
              minLength={10}
              maxLength={10}
              required
              className={inputClass}
              placeholder="9876543210"
            />
          </div>

          <div className="lg:col-span-6">
            <label className={labelClass}>Company</label>
            <input
              value={form.company}
              onChange={(e) => set("company", e.target.value)}
              maxLength={100}
              className={inputClass}
              placeholder="Company name"
            />
          </div>

          <div className="lg:col-span-3">
            <label className={labelClass}>
              Source <span className="text-red-500">*</span>
            </label>
            <select
              value={form.source}
              onChange={(e) => set("source", e.target.value)}
              required
              className={inputClass}
            >
              {SOURCES.map((s) => (
                <option key={s} value={s}>
                  {s === "" ? "-- Select --" : s.replace("_", " ").replace(/^\w/, (c) => c.toUpperCase())}
                </option>
              ))}
            </select>
          </div>

          <div className="lg:col-span-3">
            <label className={labelClass}>Status</label>
            <select
              value={form.status}
              onChange={(e) => set("status", e.target.value)}
              className={inputClass}
            >
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </option>
              ))}
            </select>
          </div>
        </div>
      </section>

      <section className="rounded-2xl border border-gray-100 bg-white p-5 sm:p-6">
        <div className="mb-5 border-b border-gray-200 pb-4">
          <p className={sectionTitleClass}>Additional Information</p>
          <p className="mt-1 text-sm text-gray-500">Only active fields selected by admin appear here.</p>
        </div>

        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          {fieldSettings.map((setting) => {
            if (!setting.active) return null;

            return (
              <div key={setting.key} className={setting.type === "textarea" ? "md:col-span-2 xl:col-span-3" : undefined}>
                <label className={labelClass}>
                  {setting.label} {setting.required && <span className="text-red-500">*</span>}
                </label>
               {setting.type === "textarea" ? (
  <textarea
    value={form[setting.key] ?? ""}
    onChange={(e) => set(setting.key, e.target.value)}
    required={setting.required}
    rows={3}
    className={`${inputClass} min-h-28 resize-y`}
  />
) : setting.type === "number" ? (
  <input
    type="number"
    value={form[setting.key] ?? ""}
    onChange={(e) => set(setting.key, e.target.value)}
    required={setting.required}
    maxLength={255}
    className={inputClass}
  />
) : (
  <input
    type="text"
    value={form[setting.key] ?? ""}
    onChange={(e) => set(setting.key, e.target.value)}
    required={setting.required}
    maxLength={255}
    className={inputClass}
  />
)}
              </div>
            );
          })}
        </div>
      </section>

      <section className="rounded-2xl border border-gray-100 bg-white p-5 sm:p-6">
        <label className={labelClass}>Notes</label>
        <textarea
          value={form.notes}
          onChange={(e) => set("notes", e.target.value)}
          rows={4}
          maxLength={500}
          className={`${inputClass} min-h-32 resize-y`}
          placeholder="Add context, call notes, or special requirements..."
        />
      </section>

      <div className="sticky bottom-0 z-10 -mx-6 -mb-6 flex flex-col gap-3 border-t border-gray-200 bg-white/95 px-6 py-4 backdrop-blur sm:flex-row sm:justify-end">
        <button
          type="button"
          onClick={() => router.back()}
          className="rounded-xl border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
        >
          Cancel
        </button>
        <button
          type="submit"
          disabled={saving}
          className="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:bg-blue-400"
        >
          {saving ? "Saving..." : leadId ? "Save Changes" : "Create Lead"}
        </button>
      </div>
    </form>
  );
}
