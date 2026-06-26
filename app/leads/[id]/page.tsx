// import LeadDetailPage from "./LeadDetailClient";

// export default function Page() {
//   return <LeadDetailPage />;
// }
import LeadDetailPage from "./LeadDetailClient";

export const dynamic = 'force-static';

export async function generateStaticParams() {
  return [{ id: 'placeholder' }];
}

export default function Page() {
  return <LeadDetailPage />;
}