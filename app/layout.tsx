import type { Metadata } from "next";
import "./globals.css";
import { AuthProvider } from "@/lib/auth";

export const metadata: Metadata = {
  title: "Omnigross CRM",
  description: "Omnigross CRM",
  icons: {
    icon: {
      url: "/crm_web/OmniGrosslogo2.png?v=2",
      type: "image/png",
    },
    shortcut: "/crm_web/OmniGrosslogo2.png?v=2",
    apple: "/crm_web/OmniGrosslogo2.png?v=2",
  },
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="h-full">
      <body className="h-full">
        <AuthProvider>{children}</AuthProvider>
      </body>
    </html>
  );
}
