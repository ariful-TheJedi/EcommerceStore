import OrderTracking from "@/components/sections/OrderTracking";

export const metadata = { title: "অর্ডার ট্র্যাকিং | ঘরগোছ", robots: { index: false, follow: false } };

export default async function TrackingPage({ searchParams }: { searchParams: Promise<{ order?: string | string[] }> }) {
  const query = await searchParams;
  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container py-8 pb-16 sm:py-12"><OrderTracking initialNumber={typeof query.order === "string" ? query.order.slice(0, 100) : ""} /></main>;
}
