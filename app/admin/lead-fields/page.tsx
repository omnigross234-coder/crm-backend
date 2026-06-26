"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Layout from "@/components/Layout";
import { useAuth } from "@/lib/auth";
import {
  DEFAULT_LEAD_FIELD_SETTINGS,
  LeadExtraFieldKey,
  LeadExtraFieldType,
  LeadFieldSetting,
  saveLeadFieldSettings,
  fetchLeadFieldSettings,
  updateLeadFieldSettings,
  createLeadFieldSetting,
} from "@/lib/leadFieldConfig";

export default function LeadFieldsPage() {
  const { isAdmin, loading: authLoading } = useAuth();
  const router = useRouter();
  const [settings, setSettings] = useState<LeadFieldSetting[]>(DEFAULT_LEAD_FIELD_SETTINGS);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");
  const [newLabel, setNewLabel] = useState("");
  const [newType, setNewType] = useState<LeadExtraFieldType>("text");
  const [newRequired, setNewRequired] = useState(false);
  const [adding, setAdding] = useState(false);

  useEffect(() => {
    if (!authLoading && !isAdmin) {
      router.replace("/dashboard");
      return;
    }
    if (!authLoading) fetchLeadFieldSettings().then(setSettings);
  }, [authLoading, isAdmin, router]);

  function updateField(key: LeadExtraFieldKey, patch: Partial<LeadFieldSetting>) {
    setSaved(false);
    setError("");
    setSettings((prev) =>
      prev.map((setting) => {
        if (setting.key !== key) return setting;
        const next = { ...setting, ...patch };
        return next.active ? next : { ...next, required: false };
      })
    );
  }

  async function handleSave() {
    setError("");
    try {
      const savedSettings = await updateLeadFieldSettings(settings);
      setSettings(savedSettings);
      setSaved(true);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to save field settings.");
    }
  }

  async function handleReset() {
    setError("");
    try {
      setSettings(DEFAULT_LEAD_FIELD_SETTINGS);
      saveLeadFieldSettings(DEFAULT_LEAD_FIELD_SETTINGS);
      await updateLeadFieldSettings(DEFAULT_LEAD_FIELD_SETTINGS);
      setSaved(true);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to reset field settings.");
    }
  }

  async function handleAddField() {
    if (!newLabel.trim()) {
      setError("Field name is required.");
      return;
    }

    setError("");
    setAdding(true);
    try {
      const savedSettings = await createLeadFieldSetting({
        label: newLabel.trim(),
        type: newType,
        required: newRequired,
      });
      setSettings(savedSettings);
      setNewLabel("");
      setNewType("text");
      setNewRequired(false);
      setSaved(true);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to add custom field.");
    } finally {
      setAdding(false);
    }
  }

  return (
    <Layout>
      <div className="p-8">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Lead Fields</h1>
            <p className="text-sm text-gray-500 mt-1">Control which extra fields appear on the lead form.</p>
          </div>
          <div className="flex gap-3">
            <button
              type="button"
              onClick={handleReset}
              className="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition-colors"
            >
              Reset
            </button>
            <button
              type="button"
              onClick={handleSave}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors"
            >
              Save Settings
            </button>
          </div>
        </div>

        {saved && (
          <div className="mb-4 bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg px-4 py-3">
            Field settings saved.
          </div>
        )}
        {error && (
          <div className="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
            {error}
          </div>
        )}

        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
          <h2 className="font-semibold text-gray-800 mb-4">Add Custom Field</h2>
          <div className="grid grid-cols-1 md:grid-cols-[1fr_160px_140px_120px] gap-3">
            <input
              value={newLabel}
              onChange={(e) => setNewLabel(e.target.value)}
              placeholder="Field name"
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <select
              value={newType}
              onChange={(e) => setNewType(e.target.value as LeadExtraFieldType)}
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="text">Text</option>
              <option value="textarea">Textarea</option>
              <option value="number">Number</option>  
            </select>
            <label className="inline-flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                checked={newRequired}
                onChange={(e) => setNewRequired(e.target.checked)}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              Required
            </label>
            <button
              type="button"
              onClick={handleAddField}
              disabled={adding}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white rounded-lg text-sm font-medium transition-colors"
            >
              {adding ? "Adding..." : "Add Field"}
            </button>
          </div>
        </div>

        <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Field</th>
                <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Active</th>
                <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Requirement</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {settings.map((setting) => {
                return (
                  <tr key={setting.key} className="hover:bg-gray-50">
                    <td className="px-6 py-4">
                      <div className="font-medium text-gray-900">{setting.label}</div>
                      <div className="text-xs text-gray-400">{setting.key}</div>
                    </td>
                    <td className="px-6 py-4 text-gray-600 capitalize">{setting.type}</td>
                    <td className="px-6 py-4">
                      <label className="inline-flex items-center gap-2 text-gray-700">
                        <input
                          type="checkbox"
                          checked={setting.active}
                          onChange={(e) => updateField(setting.key, { active: e.target.checked })}
                          className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        />
                        Active
                      </label>
                    </td>
                    <td className="px-6 py-4">
                      <select
                        value={setting.required ? "required" : "optional"}
                        onChange={(e) => updateField(setting.key, { required: e.target.value === "required" })}
                        disabled={!setting.active}
                        className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-400"
                      >
                        <option value="optional">Optional</option>
                        <option value="required">Required</option>
                      </select>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </Layout>
  );
}
