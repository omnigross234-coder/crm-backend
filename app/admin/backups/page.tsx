"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Layout from "@/components/Layout";
import { api, ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  FaCloudDownloadAlt,
  FaDatabase,
  FaHistory,
  FaRegClock,
  FaSyncAlt,
  FaExclamationTriangle,
  FaInfoCircle,
  FaCheckCircle,
} from "react-icons/fa";

interface BackupFile {
  name: string;
  size: number;
  modified: string;
}

export default function BackupsPage() {
  const { isAdmin, loading: authLoading } = useAuth();
  const router = useRouter();
  const [backups, setBackups] = useState<BackupFile[]>([]);
  const [loading, setLoading] = useState(true);
  const [backingUp, setBackingUp] = useState(false);
  const [statusMessage, setStatusMessage] = useState<{
    type: "success" | "error";
    text: string;
  } | null>(null);

  // Get current origin for dynamic cron URL documentation
  const [origin, setOrigin] = useState("");
  useEffect(() => {
    if (typeof window !== "undefined") {
      setOrigin(window.location.origin);
    }
  }, []);

  useEffect(() => {
    if (!authLoading && !isAdmin) {
      router.replace("/dashboard");
      return;
    }
    if (!authLoading && isAdmin) {
      fetchBackups();
    }
  }, [authLoading, isAdmin, router]);

  async function fetchBackups() {
    setLoading(true);
    try {
      const res = await api.get<ApiResponse<BackupFile[]>>("/backup/list");
      if (res.success) {
        setBackups(res.data);
      } else {
        setStatusMessage({
          type: "error",
          text: res.message || "Failed to load backups list.",
        });
      }
    } catch (err: unknown) {
      setStatusMessage({
        type: "error",
        text: err instanceof Error ? err.message : "An error occurred while fetching backups.",
      });
    } finally {
      setLoading(false);
    }
  }

  async function handleRunBackup() {
    if (backingUp) return;
    setBackingUp(true);
    setStatusMessage(null);
    try {
      const res = await api.post<ApiResponse<{ filename: string }>>("/backup/run", {});
      if (res.success) {
        setStatusMessage({
          type: "success",
          text: `Backup successfully created: ${res.data.filename}. File uploaded to Google Drive.`,
        });
        fetchBackups();
      } else {
        setStatusMessage({
          type: "error",
          text: res.message || "Backup execution failed.",
        });
      }
    } catch (err: unknown) {
      setStatusMessage({
        type: "error",
        text: err instanceof Error ? err.message : "An error occurred during backup creation.",
      });
    } finally {
      setBackingUp(false);
    }
  }

  function formatBytes(bytes: number, decimals = 2) {
    if (bytes === 0) return "0 Bytes";
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ["Bytes", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + " " + sizes[i];
  }

  return (
    <Layout>
      <div className="max-w-6xl mx-auto px-4 py-8">
        {/* Header section */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
          <div>
            <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-3">
              <FaDatabase className="text-blue-600 w-7 h-7" />
              Backup Settings & Manager
            </h1>
            <p className="text-sm text-gray-500 mt-1">
              Trigger manual backups and configure automated backups to keep your CRM data safe.
            </p>
          </div>
          <button
            onClick={handleRunBackup}
            disabled={backingUp}
            className={`inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold text-white transition-all shadow ${
              backingUp
                ? "bg-blue-400 cursor-not-allowed"
                : "bg-blue-600 hover:bg-blue-700 active:scale-95"
            }`}
          >
            <FaSyncAlt className={`w-4 h-4 ${backingUp ? "animate-spin" : ""}`} />
            {backingUp ? "Running Backup..." : "Run Backup Now"}
          </button>
        </div>

        {/* Status Message */}
        {statusMessage && (
          <div
            className={`flex items-start gap-3 p-4 rounded-lg border mb-6 text-sm ${
              statusMessage.type === "success"
                ? "bg-green-50 border-green-200 text-green-800"
                : "bg-red-50 border-red-200 text-red-800"
            }`}
          >
            {statusMessage.type === "success" ? (
              <FaCheckCircle className="w-5 h-5 text-green-600 shrink-0 mt-0.5" />
            ) : (
              <FaExclamationTriangle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />
            )}
            <div>
              <p className="font-semibold">
                {statusMessage.type === "success" ? "Success" : "Error"}
              </p>
              <p className="mt-1">{statusMessage.text}</p>
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Main Area: Backups History */}
          <div className="lg:col-span-2 space-y-6">
            <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
              <div className="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                <h2 className="text-sm font-bold text-gray-800 flex items-center gap-2 uppercase tracking-wider">
                  <FaHistory className="text-gray-500" />
                  Backup History (Google Drive)
                </h2>
                <button
                  onClick={fetchBackups}
                  disabled={loading}
                  className="text-gray-500 hover:text-blue-600 transition-colors p-1"
                  title="Refresh list"
                >
                  <FaSyncAlt className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
                </button>
              </div>

              {loading ? (
                <div className="p-12 text-center">
                  <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4" />
                  <p className="text-sm text-gray-500">Retrieving files from Google Drive...</p>
                </div>
              ) : backups.length === 0 ? (
                <div className="p-12 text-center">
                  <FaCloudDownloadAlt className="w-12 h-12 text-gray-300 mx-auto mb-3" />
                  <p className="text-sm font-medium text-gray-700">No backups found</p>
                  <p className="text-xs text-gray-400 mt-1 max-w-sm mx-auto">
                    No database SQL backups starting with "crm_backup_" were found in your configured Google Drive folder.
                  </p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-sm border-collapse">
                    <thead>
                      <tr className="bg-gray-100 text-xs font-semibold text-gray-500 uppercase border-b border-gray-200">
                        <th className="px-6 py-3">File Name</th>
                        <th className="px-6 py-3">File Size</th>
                        <th className="px-6 py-3">Created On</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200">
                      {backups.map((file) => (
                        <tr key={file.name} className="hover:bg-gray-50 transition-colors">
                          <td className="px-6 py-4 font-mono text-xs text-gray-800">{file.name}</td>
                          <td className="px-6 py-4 text-gray-600">{formatBytes(file.size)}</td>
                          <td className="px-6 py-4 text-gray-600">{file.modified}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          {/* Sidebar Area: Cron & Setup Docs */}
          <div className="space-y-6">
            {/* Automated Backups Setup Card */}
            <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
              <h3 className="text-sm font-bold text-gray-800 flex items-center gap-2 uppercase tracking-wider border-b border-gray-100 pb-3">
                <FaRegClock className="text-gray-500" />
                Automated Backups
              </h3>
              <p className="text-xs text-gray-600 leading-relaxed">
                Automated backups are configured to run daily at <strong>18:00 (6:00 PM)</strong>. To activate them, choose one of these two setup methods on your server:
              </p>

              <div className="space-y-3 pt-2">
                <div className="p-3 bg-gray-50 rounded-lg border border-gray-100">
                  <h4 className="text-xs font-bold text-gray-800 mb-1 flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                    Method 1: Server Cron Job
                  </h4>
                  <p className="text-[11px] text-gray-500 mb-2 leading-relaxed">
                    Add this cron job to your server panel (cPanel, Plesk, VPS) to run every minute:
                  </p>
                  <code className="block p-2 bg-slate-900 text-lime-400 text-[10px] font-mono rounded overflow-x-auto whitespace-pre leading-normal">
                    * * * * * cd /path-to-crm-project && php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1
                  </code>
                  <span className="block mt-1.5 text-[10px] text-yellow-600 leading-snug">
                    * Replace "/path-to-crm-project" with the absolute path on your server.
                  </span>
                </div>

                <div className="p-3 bg-gray-50 rounded-lg border border-gray-100">
                  <h4 className="text-xs font-bold text-gray-800 mb-1 flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                    Method 2: External Cron Service
                  </h4>
                  <p className="text-[11px] text-gray-500 mb-2 leading-relaxed">
                    Set up an external scheduler (e.g., cron-job.org) to query this URL once a day:
                  </p>
                  <code className="block p-2 bg-slate-900 text-lime-400 text-[10px] font-mono rounded break-all whitespace-pre-wrap leading-normal">
                    {origin ? `${origin}/api/run-backup?key=YOUR_BACKUP_RUN_KEY` : "https://yourdomain.com/api/run-backup?key=YOUR_BACKUP_RUN_KEY"}
                  </code>
                  <span className="block mt-1.5 text-[10px] text-yellow-600 leading-snug">
                    * Replace "YOUR_BACKUP_RUN_KEY" with the value of "BACKUP_RUN_KEY" from your server's .env file.
                  </span>
                </div>
              </div>
            </div>

            {/* Verification requirements */}
            <div className="bg-yellow-50 rounded-xl border border-yellow-200 p-5 space-y-3">
              <h3 className="text-xs font-bold text-yellow-800 flex items-center gap-2 uppercase tracking-wider">
                <FaInfoCircle className="text-yellow-600" />
                Server Environment Check
              </h3>
              <p className="text-[11px] text-yellow-800 leading-relaxed">
                Ensure your server's <code>.env</code> file has the following configurations:
              </p>
              <ul className="list-disc pl-4 text-[10px] text-yellow-700 space-y-1.5 font-mono">
                <li>GOOGLE_DRIVE_CLIENT_ID=...</li>
                <li>GOOGLE_DRIVE_CLIENT_SECRET=...</li>
                <li>GOOGLE_DRIVE_REFRESH_TOKEN=...</li>
                <li>GOOGLE_DRIVE_FOLDER=CRM_Backups</li>
                <li>BACKUP_RUN_KEY=your-secure-cron-key</li>
              </ul>
              <div className="text-[10px] text-yellow-800 border-t border-yellow-200/50 pt-2.5 mt-2.5">
                <strong>Prerequisite:</strong> The PHP executable on your hosting server must have <code>mysqldump</code> installed in its path to dump the SQL database file.
              </div>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
}
