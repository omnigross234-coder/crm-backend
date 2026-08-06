"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Layout from "@/components/Layout";
import { api, ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";

interface SmsSetting {
  message: string;
}

export default function SmsSettingsPage() {
  const { isAdmin, loading: authLoading } = useAuth();
  const router = useRouter();
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!authLoading && !isAdmin) {
      router.replace("/dashboard");
      return;
    }

    if (!authLoading && isAdmin) {
      api
        .get<ApiResponse<SmsSetting>>("/app-settings/post-call-sms")
        .then((response) => setMessage(response.data.message))
        .catch((err: unknown) =>
          setError(err instanceof Error ? err.message : "Failed to load SMS settings.")
        )
        .finally(() => setLoading(false));
    }
  }, [authLoading, isAdmin, router]);

  async function handleSave() {
    const trimmedMessage = message.trim();
    if (!trimmedMessage) {
      setError("The SMS message cannot be empty.");
      return;
    }

    setSaving(true);
    setSaved(false);
    setError("");
    try {
      const response = await api.put<ApiResponse<SmsSetting>>(
        "/app-settings/post-call-sms",
        { message: trimmedMessage }
      );
      setMessage(response.data.message);
      setSaved(true);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to save SMS settings.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Layout>
      <div className="p-8">
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Post-call SMS</h1>
          <p className="text-sm text-gray-500 mt-1">
            Set the same message sent after calls from the CRM mobile app.
          </p>
        </div>

        {saved && (
          <div className="mb-4 bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg px-4 py-3">
            SMS message saved. Mobile users will receive it automatically before their next call.
          </div>
        )}
        {error && (
          <div className="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
            {error}
          </div>
        )}

        <div className="max-w-3xl bg-white rounded-xl border border-gray-200 p-6">
          <label htmlFor="sms-message" className="block font-semibold text-gray-800 mb-2">
            Message
          </label>
          <textarea
            id="sms-message"
            value={message}
            onChange={(event) => {
              setMessage(event.target.value);
              setSaved(false);
            }}
            disabled={loading || saving}
            maxLength={1000}
            rows={7}
            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            placeholder={loading ? "Loading..." : "Enter the message sent after each call"}
          />
          <div className="flex items-center justify-between gap-4 mt-2">
            <p className="text-xs text-gray-500">
              The message is sent exactly as written. Customer names are not added.
            </p>
            <span className="text-xs text-gray-400">{message.length}/1000</span>
          </div>
          <button
            type="button"
            onClick={handleSave}
            disabled={loading || saving || !message.trim()}
            className="mt-5 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white rounded-lg text-sm font-medium transition-colors"
          >
            {saving ? "Saving..." : "Save SMS Message"}
          </button>
        </div>
      </div>
    </Layout>
  );
}
