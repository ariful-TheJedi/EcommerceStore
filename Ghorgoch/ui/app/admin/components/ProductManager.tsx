"use client";

import Link from "next/link";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { ExternalLink, Package, Plus, RefreshCw, Search } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { loadTaxonomy, priceLabel, type Product, type ProductListing, type TaxonomyOptions } from "@/admin/services/products";
import ProductEditor from "./ProductEditor";
import { Field, productInput, productPanel } from "./ProductFields";

export default function ProductManager({ initialCreate = false, catalogRevision = 0, onCatalogChanged, browse }: {
  initialCreate?: boolean; catalogRevision?: number; onCatalogChanged?: () => void;
  browse?: { kind: "category" | "tag"; slug: string; key: number };
}) {
  const { request } = useAdminAuth();
  const [listing, setListing] = useState<ProductListing | null>(null);
  const [taxonomy, setTaxonomy] = useState<TaxonomyOptions | null>(null);
  const [taxonomyError, setTaxonomyError] = useState("");
  const [editable, setEditable] = useState(false);
  const [permissionError, setPermissionError] = useState("");
  const [editor, setEditor] = useState<Product | "new" | null>(initialCreate ? "new" : null);
  const [opening, setOpening] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [optionRevision, setOptionRevision] = useState(0);
  const [query, setQuery] = useState("");
  const [filters, setFilters] = useState({ search: "", status: "", category: "", tag: "", availability: "", sort: "-id" });
  const detailController = useRef<AbortController | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const focusList = useRef(false);

  useEffect(() => {
    if (browse) {
      if (editor && !window.confirm("Close the current product editor and browse matching products? Unsaved changes will be discarded.")) { return; }
      setEditor(null);
      setFilters({ search: "", status: "", category: browse.kind === "category" ? browse.slug : "", tag: browse.kind === "tag" ? browse.slug : "", availability: "", sort: "-id" });
      setQuery(""); setPage(1);
    }
  }, [browse]);

  useEffect(() => () => detailController.current?.abort(), []);
  useEffect(() => {
    if (focusList.current && editor === null) { heading.current?.focus(); focusList.current = false; }
  }, [editor]);

  useEffect(() => {
    const controller = new AbortController();
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)]);
    setTaxonomyError(""); setPermissionError("");
    Promise.all([loadTaxonomy(request, "categories", signal), loadTaxonomy(request, "tags", signal)]).then(([categories, tags]) => {
      if (!controller.signal.aborted) { setTaxonomy({ categories, tags }); }
    }).catch((failure) => { if (!controller.signal.aborted) { setTaxonomyError(failure instanceof Error ? failure.message : "Unable to load categories and tags."); } });
    request<{ user: { role: string; permissions: string[] } }>("/users/me", { signal }).then(({ user }) => {
      if (!controller.signal.aborted) { setEditable(user.role === "admin" || user.permissions.includes("catalog.manage")); }
    }).catch(() => { if (!controller.signal.aborted) { setPermissionError("Your editing permissions could not be loaded. Refresh to retry. Products remain read-only."); } });
    return () => controller.abort();
  }, [request, optionRevision, catalogRevision]);

  useEffect(() => {
    const controller = new AbortController();
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]);
    setLoading(true); setError(""); setListing(null);
    const params = new URLSearchParams({ page: String(page), per_page: "15" });
    Object.entries(filters).forEach(([key, value]) => { if (value) { params.set(key, value); } });
    request<ProductListing>("/products?" + params, { signal }).then((data) => {
      if (controller.signal.aborted) { return; }
      if (!Array.isArray(data?.products) || !Number.isSafeInteger(data?.pagination?.total)) { throw new Error("Unexpected product response. Please retry."); }
      if (page > data.pagination.last_page) { setPage(Math.max(1, data.pagination.last_page)); return; }
      setListing(data);
    }).catch((failure) => { if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : "Unable to load products."); } })
      .finally(() => { if (!controller.signal.aborted) { setLoading(false); } });
    return () => controller.abort();
  }, [request, page, filters, revision, catalogRevision]);

  async function openProduct(id: number) {
    detailController.current?.abort();
    const controller = new AbortController(); detailController.current = controller;
    setOpening(true); setError("");
    try {
      const { product } = await request<{ product: Product }>("/products/" + id, { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) });
      if (!controller.signal.aborted) { setEditor(product); }
    } catch (failure) { if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : "Unable to open product."); } }
    finally { if (!controller.signal.aborted) { setOpening(false); } }
  }
  function applyFilter(key: keyof typeof filters, value: string) { setFilters((previous) => ({ ...previous, [key]: value })); setPage(1); }
  function search(event: FormEvent<HTMLFormElement>) { event.preventDefault(); applyFilter("search", query.trim()); }
  function refresh() { setRevision((value) => value + 1); setOptionRevision((value) => value + 1); }

  if (editor) {
    return <div className="mx-auto max-w-7xl">
      {permissionError && <p role="alert" className="mb-4 text-sm text-brown">{permissionError}<Button variant="ghost" onClick={() => setOptionRevision((value) => value + 1)}>Retry</Button></p>}
      {taxonomyError && <p role="alert" className="mb-4 text-sm text-brown">{taxonomyError} Existing category and tag assignments will be preserved.<Button variant="ghost" onClick={() => setOptionRevision((value) => value + 1)}>Retry options</Button></p>}
      <ProductEditor initial={editor} taxonomy={taxonomy} taxonomyError={taxonomyError} editable={editable} onClose={() => { focusList.current = true; setEditor(null); }} onChanged={() => { setRevision((value) => value + 1); onCatalogChanged?.(); }} />
    </div>;
  }
  const hasFilters = Boolean(filters.search || filters.status || filters.category || filters.tag || filters.availability);
  return <section aria-labelledby="products-title" className="mx-auto flex max-w-7xl flex-col gap-6" style={{ fontFamily: "Arial, sans-serif" }}>
    <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="mb-3 text-[10px] font-semibold tracking-[0.18em] text-brown">CATALOG / PRODUCTS</p><h1 id="products-title" ref={heading} tabIndex={-1} className="font-[inherit] text-3xl font-semibold tracking-tight">Products</h1><p className="mt-2 text-sm text-brown">Manage your catalog, pricing, variants, and product images.</p></div><div className="flex gap-2"><Button variant="outline" aria-label="Refresh products" disabled={opening} onClick={refresh}><RefreshCw size={16} /></Button>{editable && <Button disabled={opening} onClick={() => setEditor("new")} startIcon={<Plus size={16} />}>Add product</Button>}</div></div>
    {permissionError && <p role="alert" className="text-sm text-brown">{permissionError}</p>}
    {taxonomyError && <p role="alert" className="text-sm text-brown">{taxonomyError}<Button variant="ghost" size="sm" onClick={() => setOptionRevision((value) => value + 1)}>Retry options</Button></p>}
    {error && <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#9b3528]/20 bg-[#fff0ec] p-4 text-sm text-[#9b3528]"><p>{error}</p><Button variant="outline" size="sm" onClick={() => setRevision((value) => value + 1)}>Retry</Button></div>}
    <div className={productPanel}>
      <form onSubmit={search} role="search" className="flex flex-wrap items-end gap-3"><div className="min-w-0 flex-1"><Field label="Search products" name="product-search"><input type="search" id="product-search" placeholder="Search name or description..." maxLength={150} value={query} onChange={(event) => setQuery(event.target.value)} className={productInput} /></Field></div><Button type="submit" variant="outline" startIcon={<Search size={15} />}>Search</Button></form>
      <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <Field label="Status" name="filter-status"><select id="filter-status" value={filters.status} onChange={(event) => applyFilter("status", event.target.value)} className={productInput}><option value="">All statuses</option><option value="published">Published</option><option value="draft">Draft</option><option value="archived">Archived</option></select></Field>
        {(["category", "tag"] as const).map((key) => <Field key={key} label={key === "category" ? "Category" : "Tag"} name={"filter-" + key}><select id={"filter-" + key} value={filters[key]} disabled={!taxonomy} onChange={(event) => applyFilter(key, event.target.value)} className={productInput}><option value="">All {key === "category" ? "categories" : "tags"}</option>{taxonomy?.[key === "category" ? "categories" : "tags"].map((item) => <option value={item.slug} key={item.id}>{item.name}</option>)}</select></Field>)}
        <Field label="Availability" name="filter-availability"><select id="filter-availability" value={filters.availability} onChange={(event) => applyFilter("availability", event.target.value)} className={productInput}><option value="">All stock levels</option><option value="in_stock">In stock</option><option value="low_stock">Low stock</option><option value="out_of_stock">Out of stock</option></select></Field>
        <Field label="Sort by" name="filter-sort"><select id="filter-sort" value={filters.sort} onChange={(event) => applyFilter("sort", event.target.value)} className={productInput}><option value="-id">Newest first</option><option value="id">Oldest first</option><option value="name">Name A–Z</option><option value="-name">Name Z–A</option><option value="price">Price low to high</option><option value="-price">Price high to low</option></select></Field>
      </div>
      {hasFilters && <Button variant="ghost" size="sm" className="mt-3" onClick={() => { setQuery(""); setFilters({ search: "", status: "", category: "", tag: "", availability: "", sort: "-id" }); setPage(1); }}>Clear filters</Button>}
    </div>
    <div className="overflow-hidden rounded-2xl border border-cocoa/10 bg-[#fffdfb]" aria-busy={loading || opening}>
      <div className="flex items-center justify-between gap-3 border-b border-cocoa/8 px-5 py-4"><h2 className="font-[inherit] text-sm font-semibold">Product catalog</h2><p role="status" className="text-xs text-brown">{opening ? "Opening product..." : loading ? "Loading..." : listing ? new Intl.NumberFormat("en").format(listing.pagination.total) + " products" : "Unavailable"}</p></div>
      {loading ? <p role="status" className="p-12 text-center text-sm text-brown">Loading products...</p> : listing && (listing.products.length ? <div className="overflow-x-auto" role="region" aria-label="Products" tabIndex={0}><table className="w-full min-w-190 text-left text-sm"><caption className="sr-only">Products</caption><thead className="bg-ivory text-xs text-brown"><tr>{["Product", "Status", "Price", "Variants", "Categories", "Actions"].map((label) => <th key={label} scope="col" className="px-5 py-3 font-medium">{label}</th>)}</tr></thead><tbody className="divide-y divide-cocoa/8">{listing.products.map((item) => {
        const image = item.images.find((image) => image.is_primary) ?? item.images[0];
        return <tr key={item.id} className="hover:bg-ivory/60"><td className="px-5 py-4"><div className="flex items-center gap-3"><div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-cocoa/8 bg-ivory">{image ? <img src={image.url} alt="" width={48} height={48} className="size-full object-contain" loading="lazy" /> : <Package size={19} aria-hidden="true" className="text-brown" />}</div><div className="max-w-64"><button type="button" disabled={opening} onClick={() => void openProduct(item.id)} className="text-left font-semibold break-words hover:underline focus-visible:outline-2 focus-visible:outline-olive">{item.name}</button><p className="mt-1 truncate text-xs text-brown">#{item.id} · {item.slug}</p></div></div></td><td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-[11px] capitalize ${item.status === "published" ? "bg-olive/10 text-olive" : item.status === "draft" ? "bg-[#fbecdd] text-[#97602b]" : "bg-cocoa/5 text-brown"}`}>{item.status}</span></td><td className="px-5 py-4 text-xs whitespace-nowrap">{priceLabel(item)}</td><td className="px-5 py-4 text-xs">{item.variants.filter((variant) => variant.is_active).length} active / {item.variants.length}</td><td className="max-w-52 px-5 py-4 text-xs text-brown">{item.categories.map((category) => category.name).join(", ") || "Uncategorized"}</td><td className="px-5 py-4"><div className="flex flex-wrap items-center gap-2"><Button size="sm" variant="outline" disabled={opening} onClick={() => void openProduct(item.id)}>{editable ? "Manage" : "View"}</Button>{item.status === "published" && <Link href={`/products/${item.id}`} target="_blank" rel="noopener noreferrer" aria-label={`View ${item.name} on site (opens in a new tab)`} className="inline-flex min-h-11 items-center gap-2 rounded-card px-3 py-2 text-xs font-medium whitespace-nowrap text-olive hover:bg-olive/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive">View on site<ExternalLink size={14} aria-hidden="true" /></Link>}</div></td></tr>;
      })}</tbody></table></div> : <div className="flex flex-col items-center gap-3 p-12 text-center"><Package size={30} strokeWidth={1.5} aria-hidden="true" className="text-olive" /><p className="text-sm font-semibold">{hasFilters ? "No matching products" : "Your catalog is empty"}</p><p className="text-xs text-brown">{hasFilters ? "Try changing your search or filters." : "Add your first product to get started."}</p></div>)}
      {listing && !loading && <nav aria-label="Product pagination" className="flex flex-wrap items-center justify-between gap-3 border-t border-cocoa/8 p-5"><span className="text-xs text-brown">Page {listing.pagination.current_page} of {listing.pagination.last_page}</span><div className="flex gap-2"><Button variant="outline" size="sm" disabled={opening || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><Button variant="outline" size="sm" disabled={opening || page >= listing.pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button></div></nav>}
    </div>
  </section>;
}


