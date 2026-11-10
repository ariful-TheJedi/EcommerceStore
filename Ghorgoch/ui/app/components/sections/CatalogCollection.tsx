"use client";



import pageData from "@/data/products.json";

import Link from "next/link";
import { Search, X } from "lucide-react";
import Skeleton from "@/components/atoms/Skeleton";
import { useEffect, useState } from "react";
import ProductCard from "@/components/molecules/ProductCard";
import { api, type Category, type ProductListing, lowestVariant, productImage } from "@/components/catalog";

const content = pageData.catalog;

export default function CatalogCollection({ category, title = content.label, initialSearch = "", showcase = false, searchPage = false, sectionId = "collection", initialSort = "-created_at" }: { category?: string; title?: string; initialSearch?: string; showcase?: boolean; searchPage?: boolean; sectionId?: string; initialSort?: "id" | "-id" | "-created_at" }) {
  const [listing, setListing] = useState<ProductListing | null>(null);
  const [categories, setCategories] = useState<Category[]>([]);
  const [selected, setSelected] = useState(category || "");
  const [sort, setSort] = useState<string>(initialSort);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState(initialSearch.slice(0, 150));
  const [debouncedSearch, setDebouncedSearch] = useState(search);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    const timeout = window.setTimeout(() => { setDebouncedSearch(search); setPage(1); }, 300);
    return () => window.clearTimeout(timeout);
  }, [search]);

  useEffect(() => {
    if (showcase) { return; }
    let active = true;
    api<{ items: Category[] }>("/catalog/categories").then((data) => { if (active) { setCategories(data.items); } }).catch(() => {});
    return () => { active = false; };
  }, [showcase]);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    setLoading(true); setError("");
    const query = new URLSearchParams({ page: String(page), per_page: showcase ? "4" : "12", sort });
    if (selected) { query.set("category", selected); }
    if (debouncedSearch.trim()) { query.set("search", debouncedSearch.trim()); }
    api<ProductListing>(`/catalog/products?${query}`, { signal: controller.signal }).then((data) => {
      if (active) { setListing(data); }
    }).catch((failure) => { if (active) { setError(failure.message); } }).finally(() => { if (active) { setLoading(false); } });
    return () => { active = false; controller.abort(); };
  }, [selected, sort, page, debouncedSearch, revision, showcase]);

  function resetSearch() { setSearch(""); setSelected(""); setPage(1); }

  return <section id={sectionId} className={searchPage ? "store-collection search-collection" : "section store-collection"} aria-labelledby={`${sectionId}-title`} aria-busy={loading}>
    {searchPage && <>
      <nav aria-label={pageData.search.page} className="flex items-center gap-3 py-6 text-xs text-brown"><Link href="/" className="hover:text-olive">{pageData.search.home}</Link><span aria-hidden="true">/</span><span aria-current="page">{pageData.search.page}</span></nav>
    </>}
    <div className="store-section-heading"><div>{!showcase && !searchPage && <p className="store-eyebrow">{content.pText}</p>}<h2 id={`${sectionId}-title`} aria-level={searchPage ? 1 : undefined}>{searchPage ? pageData.search.results : title}</h2>{searchPage && <p className="mt-2 text-sm text-brown">{debouncedSearch.trim() ? <>{pageData.search.matching} <span className="font-medium text-cocoa">&ldquo;{debouncedSearch.trim()}&rdquo;</span></> : pageData.search.browse}</p>}</div>
      {showcase ? <Link href="/products">{content.LinkText}</Link> : !searchPage && <label className="store-sort">{content.labelText}<select value={sort} onChange={(event) => { setSort(event.target.value); setPage(1); }}><option value="-created_at">{content.optionText}</option><option value="price">{content.optionText2}</option><option value="-price">{content.optionText3}</option><option value="name">{content.optionText4}</option></select></label>}
    </div>
    {!showcase && !category && <div className="store-categories" aria-label={content.arialabel}>{searchPage ? <><button className="store-category" aria-pressed={!selected} onClick={() => { setSelected(""); setPage(1); }}>{content.buttonText}</button>{categories.map((item) => <button className="store-category" key={item.id} aria-pressed={selected === item.slug} onClick={() => { setSelected(item.slug); setPage(1); }}>{item.name}</button>)}</> : <><Link className="store-category" href="/products" aria-current="page">{content.buttonText}</Link>{categories.map((item) => <Link className="store-category" key={item.id} href={`/categories/${encodeURIComponent(item.slug)}`}>{item.name}</Link>)}</>}</div>}
    {searchPage && (search || selected) && <button type="button" className="mb-5 inline-flex min-h-10 items-center gap-2 text-xs text-olive underline underline-offset-4" onClick={resetSearch}><X size={14} aria-hidden="true" />{pageData.search.reset}</button>}
    {error ? <div className="catalog-state" role="alert"><p>{error}</p><button className="button button-primary" onClick={() => setRevision((value) => value + 1)}>{content.buttonText2}</button></div> : loading ? searchPage ? <div role="status"><span className="sr-only">{content.pText2}</span><div className="product-grid">{Array.from({ length: 8 }, (_, index) => <div key={index} className="space-y-3"><Skeleton className="aspect-[4/5] motion-reduce:animate-none" /><Skeleton className="h-4 w-3/4 motion-reduce:animate-none" /><Skeleton className="h-4 w-1/3 motion-reduce:animate-none" /></div>)}</div></div> : <p className="catalog-state" role="status">{content.pText2}</p> : !listing?.products.length ? searchPage ? <div className="search-empty" role="status"><Search size={32} strokeWidth={1.3} aria-hidden="true" className="mb-5 text-olive" /><h3 className="text-xl">{pageData.search.empty}</h3><p className="mt-3 max-w-md text-sm leading-7 text-brown">{pageData.search.emptyHint}</p><button type="button" className="button button-secondary mt-6" onClick={resetSearch}>{pageData.search.reset}</button></div> : <p className="catalog-state">{content.pText3}</p> : <>
      {!showcase && <p className="caption mb-5" role="status">{listing.pagination.total.toLocaleString("bn-BD")}{content.pText4}</p>}
      <div className="product-grid">{listing.products.map((product) => {
        const variant = lowestVariant(product);
        return <ProductCard isNew={product.is_new} productId={product.id} variantId={variant?.id} key={product.id} name={product.name} image={productImage(product)} price={variant ? Number(variant.effective_price) : undefined} originalPrice={variant ? Number(variant.price) : undefined} currency={product.currency} available={variant?.available ?? false} href={`/products/${product.id}`} />;
      })}</div>
      {!showcase && <nav className="catalog-pagination" aria-label={content.arialabel2}><button className="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>{content.buttonText3}</button><span>{page.toLocaleString("bn-BD")} {content.spanText}{listing.pagination.last_page.toLocaleString("bn-BD")}</span><button className="button" disabled={page >= listing.pagination.last_page} onClick={() => setPage(page + 1)}>{content.buttonText4}</button></nav>}
    </>}
    {selected && !category && <Link className="catalog-category-link" href={`/categories/${encodeURIComponent(selected)}`}>{content.LinkText2}</Link>}
  </section>;
}
