import CatalogCollection from "@/components/sections/CatalogCollection";
import pageData from "@/data/products.json";

export const metadata = { title: pageData.search.page };

export default async function SearchPage({ searchParams }: { searchParams: Promise<{ q?: string | string[] }> }) {
  const { q } = await searchParams;
  const query = (typeof q === "string" ? q : "").trim().slice(0, 150);
  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container pb-16">
    <CatalogCollection key={query} initialSearch={query} searchPage sectionId="search-results" />
  </main>;
}
