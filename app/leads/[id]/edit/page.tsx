// import EditLeadPage from "./EditLeadClient";

// export default function Page() {
//   return <EditLeadPage />;
// }
import EditLeadPage from "./EditLeadClient";

export const dynamic = 'force-static';

export async function generateStaticParams() {
  return [{ id: 'placeholder' }];
}

export default function Page() {
  return <EditLeadPage />;
}