"use client";

import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Search, X } from "lucide-react";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import type { ProductListing } from "@/admin/services/products";
import Button from "@/components/atoms/Button";
import { productInput } from "./ProductFields";

export type ProductChoice = { id: number; name: string; status: string };

export default function FrontPageProductPicker({ ids, products, onKnownProducts, onChange }: {
  ids: number[]; products: ProductChoice[]; onKnownProducts: (products: ProductChoice[]) => void; onChange: (ids: number[]) => void;
}) {
  const { request } = useAdminAuth();
  const [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [listing, setListing] = useState<ProductListing | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  useEffect(() => { const timer = window.setTimeout(() => { setSearch(query); setPage(1); }, 300); return () => window.clearTimeout(timer); }, [query]);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError("");
    const params = new URLSearchParams({ status: "published", per_page: "12", page: String(page), search });
    request<ProductListing>("/products?" + params, { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) }).then((data) => {
      if (!controller.signal.aborted) { setListing(data); onKnownProducts(data.products); }
    }).catch((failure) => { if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : "Products could not be loaded."); } }).finally(() => { if (!controller.signal.aborted) { setLoading(false); } });
    return () => controller.abort();
  }, [request, search, page, revision, onKnownProducts]);
  function move(index: number, direction: number) { const next = [...ids]; const target = index + direction; [next[index], next[target]] = [next[target], next[index]]; onChange(next); }

  return <div className="flex flex-col gap-4">
    <p className="text-xs leading-6 text-brown">Select published products below. Selected products appear in this order. Archived or draft products are hidden on the storefront.</p>
    <ol className="divide-y divide-cocoa/10 rounded-xl border border-cocoa/10 px-4">{ids.map((id, index) => <li key={id} className="flex items-center justify-between gap-3 py-3"><span className="min-w-0 text-sm"><span className="mr-2 text-brown">{index + 1}.</span>{products.find((item) => item.id === id)?.name || "Product #" + id}</span><div className="flex shrink-0 gap-1"><Button size="sm" variant="ghost" disabled={index === 0} aria-label={"Move product " + id + " earlier"} onClick={() => move(index, -1)}><ArrowUp size={15} /></Button><Button size="sm" variant="ghost" disabled={index === ids.length - 1} aria-label={"Move product " + id + " later"} onClick={() => move(index, 1)}><ArrowDown size={15} /></Button><Button size="sm" variant="ghost" aria-label={"Remove product " + id} onClick={() => onChange(ids.filter((value) => value !== id))}><X size={15} /></Button></div></li>)}{!ids.length && <li className="py-5 text-sm text-brown">No products selected.</li>}</ol>
    <label className="flex items-center gap-2"><Search size={18} className="shrink-0 text-olive" aria-hidden="true" /><span className="sr-only">Find published products</span><input type="search" maxLength={150} value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); setSearch(query.trim()); setPage(1); } }} placeholder="Find published products..." className={productInput} /></label>
    {error ? <div role="alert"><p className="text-sm text-[#9b3528]">{error}</p><Button size="sm" variant="outline" onClick={() => setRevision((value) => value + 1)}>Retry</Button></div> : loading ? <p role="status" className="py-4 text-sm text-brown">Loading products...</p> : <>
      <div className="grid gap-2 sm:grid-cols-2">{listing?.products.map((item) => <label key={item.id} className="flex cursor-pointer items-center gap-3 rounded-xl border border-cocoa/10 bg-ivory p-3 text-sm"><input type="checkbox" className="size-4 shrink-0 accent-olive" checked={ids.includes(item.id)} disabled={!ids.includes(item.id) && ids.length >= 100} onChange={() => onChange(ids.includes(item.id) ? ids.filter((id) => id !== item.id) : [...ids, item.id])} />{item.images[0] && <img src={item.images[0].url} alt="" width={40} height={40} className="size-10 rounded-lg object-contain" />}<span>{item.name}</span></label>)}</div>
      {!listing?.products.length && <p className="text-sm text-brown">No matching published products.</p>}
      <div className="flex items-center justify-between gap-3"><Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><span className="text-xs text-brown">Page {page} of {listing?.pagination.last_page || 1}</span><Button variant="outline" size="sm" disabled={page >= (listing?.pagination.last_page || 1)} onClick={() => setPage((value) => value + 1)}>Next</Button></div>
    </>}
  </div>;
}
