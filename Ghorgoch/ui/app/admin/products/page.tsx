import CatalogWorkspace from "@/admin/components/CatalogWorkspace";

export default async function ProductsPage({ searchParams }: { searchParams: Promise<{ new?: string }> }) {
  const params = await searchParams;
  return <CatalogWorkspace initialCreate={params.new === "1"} />;
}
