"use client";

import { api, ApiResponse } from "@/lib/api";

export type LeadExtraFieldKey = string;

export type LeadExtraFieldType = "text" | "textarea" | "number" ;

export interface LeadExtraFieldDefinition {
  key: LeadExtraFieldKey;
  label: string;
  type: LeadExtraFieldType;
}

export interface LeadFieldSetting {
  key: LeadExtraFieldKey;
  label: string;
  type: LeadExtraFieldType;
  active: boolean;
  required: boolean;
  isCustom?: boolean;
}

interface LeadFieldSettingResponse {
  field_key: LeadExtraFieldKey;
  label: string;
  field_type?: LeadExtraFieldType;
  active: boolean;
  required: boolean;
  is_custom?: boolean;
  sort_order: number;
}

export const LEAD_EXTRA_FIELDS: LeadExtraFieldDefinition[] = [
  { key: "address", label: "Address", type: "textarea" },
  { key: "city", label: "City", type: "text" },
  { key: "state", label: "State", type: "text" },
  { key: "country", label: "Country", type: "text" },
  { key: "pin_code", label: "PIN Code", type: "text" },
  { key: "referral_name", label: "Referral Name", type: "text" },
  { key: "industry_type", label: "Industry Type", type: "text" },
  { key: "business_type", label: "Business Type", type: "text" },
  { key: "product_service_interested_in", label: "Product/Service Interested In", type: "text" },
  { key: "budget", label: "Budget", type: "text" },
  { key: "documents", label: "Documents", type: "textarea" },
  { key: "annual_turnover", label: "Annual Turnover", type: "text" },
  { key: "gst_number", label: "GST Number", type: "text" },
  { key: "requirement", label: "Requirement", type: "textarea" },
];

const STORAGE_KEY = "crm_lead_field_settings";

export const DEFAULT_LEAD_FIELD_SETTINGS: LeadFieldSetting[] = LEAD_EXTRA_FIELDS.map((field) => ({
  key: field.key,
  label: field.label,
  type: field.type,
  active: true,
  required: false,
  isCustom: false,
}));

function mergeWithDefaults(settings: Partial<LeadFieldSetting>[]): LeadFieldSetting[] {
  const defaults = DEFAULT_LEAD_FIELD_SETTINGS.map((fallback) => {
    const saved = settings.find((item) => item.key === fallback.key);
    return {
      key: fallback.key,
      label: saved?.label ?? fallback.label,
      type: saved?.type ?? fallback.type,
      active: saved?.active ?? fallback.active,
      required: saved?.required ?? fallback.required,
      isCustom: saved?.isCustom ?? fallback.isCustom,
    };
  });
  const custom = settings
    .filter((item) => item.key && !defaults.some((fallback) => fallback.key === item.key))
    .map((item) => ({
      key: item.key as string,
      label: item.label ?? item.key ?? "Custom Field",
      type: item.type ?? "text",
      active: item.active ?? true,
      required: item.required ?? false,
      isCustom: item.isCustom ?? true,
    }));

  return [...defaults, ...custom];
}

export function getLeadFieldSettings(): LeadFieldSetting[] {
  if (typeof window === "undefined") return DEFAULT_LEAD_FIELD_SETTINGS;

  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return DEFAULT_LEAD_FIELD_SETTINGS;
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return DEFAULT_LEAD_FIELD_SETTINGS;
    return mergeWithDefaults(parsed);
  } catch {
    return DEFAULT_LEAD_FIELD_SETTINGS;
  }
}

export function saveLeadFieldSettings(settings: LeadFieldSetting[]) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
}

function fromApi(settings: LeadFieldSettingResponse[]): LeadFieldSetting[] {
  return settings.map((setting) => ({
    key: setting.field_key,
    label: setting.label,
    type: setting.field_type ?? "text",
    active: setting.active,
    required: setting.required,
    isCustom: setting.is_custom,
  }));
}

export async function fetchLeadFieldSettings(): Promise<LeadFieldSetting[]> {
  try {
    const res = await api.get<ApiResponse<LeadFieldSettingResponse[]>>("/lead-field-settings");
    const settings = fromApi(res.data);
    saveLeadFieldSettings(settings);
    return settings;
  } catch {
    return getLeadFieldSettings();
  }
}

export async function updateLeadFieldSettings(settings: LeadFieldSetting[]): Promise<LeadFieldSetting[]> {
  const res = await api.put<ApiResponse<LeadFieldSettingResponse[]>>("/lead-field-settings", {
    fields: settings.map((setting) => ({
      key: setting.key,
      active: setting.active,
      required: setting.required,
    })),
  });
  const saved = fromApi(res.data);
  saveLeadFieldSettings(saved);
  return saved;
}

export async function createLeadFieldSetting(input: {
  label: string;
  type: LeadExtraFieldType;
  required: boolean;
}): Promise<LeadFieldSetting[]> {
  const res = await api.post<ApiResponse<LeadFieldSettingResponse[]>>("/lead-field-settings", {
    label: input.label,
    field_type: input.type,
    required: input.required,
  });
  const saved = fromApi(res.data);
  saveLeadFieldSettings(saved);
  return saved;
}

export async function deleteLeadFieldSetting(key: string): Promise<LeadFieldSetting[]> {
  const res = await api.delete<ApiResponse<LeadFieldSettingResponse[]>>(
    `/lead-field-settings/${encodeURIComponent(key)}`
  );
  const saved = fromApi(res.data);
  saveLeadFieldSettings(saved);
  return saved;
}
