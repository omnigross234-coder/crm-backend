"use client";
import Link from "next/link";
import { FaArrowLeft } from "react-icons/fa";
import Layout from "@/components/Layout";
import LeadForm from "@/components/LeadForm";

export default function NewLeadPage() {
  return (
    <Layout>
      <div className="p-4 sm:p-6 lg:p-8">
        <div className="mx-auto max-w-7xl">
          <Link href="/leads" className="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:underline">
            <FaArrowLeft className="w-3 h-3" /> Leads
          </Link>
          <div className="mb-6 mt-3">
            <h1 className="text-3xl font-bold text-gray-950">New Lead</h1>
            <p className="mt-1 text-sm text-gray-500">Capture contact details, requirements, and follow-up context in one place.</p>
          </div>
        </div>
        <div className="mx-auto max-w-7xl overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
          <LeadForm />
        </div>
      </div>
    </Layout>
  );
}
