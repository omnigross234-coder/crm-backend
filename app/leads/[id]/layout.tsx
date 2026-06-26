import { ReactNode } from "react";

export const dynamic = "force-static";
export async function generateStaticParams() {
  return [{ id: "__placeholder__" }];
}

export default function LeadLayout({ children }: { children: ReactNode }) {
  return children;
}
