"use client";
import { useEffect, useState } from "react";
import { useParams, usePathname } from "next/navigation";
import Link from "next/link";
import { FaArrowLeft } from "react-icons/fa";
import Layout from "@/components/Layout";
import LeadForm from "@/components/LeadForm";
import { api, Lead, ApiResponse } from "@/lib/api";

function getLeadIdFromPath(pathname: string, fallback: string) {
  const match = pathname.match(/\/leads\/([^/]+)\/edit\/?$/);
  return match?.[1] ?? fallback;
}

export default function EditLeadPage() {
  const { id: routeId } = useParams<{ id: string }>();
  const pathname = usePathname();
  const id = getLeadIdFromPath(pathname, routeId);
  const [lead, setLead] = useState<Lead | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get<ApiResponse<Lead>>(`/leads/${id}`)
      .then((r) => setLead(r.data))
      .finally(() => setLoading(false));
  }, [id]);

  return (
    <Layout>
      <div className="p-4 sm:p-6 lg:p-8">
        <div className="mx-auto max-w-7xl">
          <Link href={`/leads/${id}`} className="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:underline">
            <FaArrowLeft className="w-3 h-3" /> Back to Lead
          </Link>
          <div className="mb-6 mt-3">
            <h1 className="text-3xl font-bold text-gray-950">Edit Lead</h1>
            <p className="mt-1 text-sm text-gray-500">Update contact details, active custom fields, and lead notes.</p>
          </div>
        </div>

        {loading ? (
          <div className="mx-auto flex max-w-7xl items-center gap-2 text-gray-500">
            <div className="h-5 w-5 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
            Loading...
          </div>
        ) : lead ? (
          <div className="mx-auto max-w-7xl overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <LeadForm initial={lead} leadId={lead.id} />
          </div>
        ) : (
          <p className="mx-auto max-w-7xl text-gray-400">Lead not found.</p>
        )}
      </div>
    </Layout>
  );
}
