"use client";

import { useEffect, useState } from "react";
import { FolderTree, Layers, Package, Tags } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { loadCatalogTotals, type CatalogTotals } from "@/admin/services/dashboard";
import { loadTaxonomy } from "@/admin/services/products";
import ProductManager from "./ProductManager";
import TaxonomyManager from "./TaxonomyManager";

type Section = "products" | "categories" | "tags" | "cms";

export default function CatalogWorkspace({ initialCreate = false }: { initialCreate?: boolean }) {
  const { request } = useAdminAuth();
  const [section, setSection] = useState<Section>("products");
  const [visited, setVisited] = useState<Section[]>(["products"]);
  const [revision, setRevision] = useState(0);
  const [totals, setTotals] = useState<CatalogTotals | null>(null);
  const [categoryCounts, setCategoryCounts] = useState<{ roots: number; children: number } | null>(null);
  const [browse, setBrowse] = useState<{ kind: "category" | "tag"; slug: string; key: number } | undefined>();
  useEffect(() => {
    const controller = new AbortController();
    setTotals(null); setCategoryCounts(null);
    loadTaxonomy(request, "categories", controller.signal).then((items) => { if (!controller.signal.aborted) { setCategoryCounts({ roots: items.filter((item) => item.is_taxonomy === true).length, children: items.filter((item) => item.is_taxonomy !== true).length }); } }).catch(() => {});
    loadCatalogTotals(request, AbortSignal.any([controller.signal, AbortSignal.timeout(15000)])).then((data) => {
      if (!controller.signal.aborted) { setTotals(data); }
    });
    return () => controller.abort();
  }, [request, revision]);
  function select(next: Section) { setSection(next); setVisited((items) => items.includes(next) ? items : [...items, next]); }
  function changed() { setRevision((value) => value + 1); }
  function browseProducts(kind: "category" | "tag", slug: string) { setBrowse((previous) => ({ kind, slug, key: (previous?.key ?? 0) + 1 })); select("products"); }
  const sections = [
    { key: "products", label: "Products", icon: Package, value: totals?.products.value, error: totals?.products.error },
    { key: "categories", label: "Categories", icon: FolderTree, value: categoryCounts?.children, error: totals?.categories.error },
    { key: "tags", label: "Tags", icon: Tags, value: totals?.tags.value, error: totals?.tags.error },
    { key: "cms", label: "Taxonomy", icon: Layers, value: categoryCounts?.roots, error: totals?.categories.error },
  ] as const;
  return <div className="mx-auto flex max-w-7xl flex-col gap-6" style={{ fontFamily: "Arial, sans-serif" }}>
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Catalog totals">{sections.map((item) => <button type="button" key={item.key} onClick={() => select(item.key)} aria-pressed={section === item.key} className={`flex flex-col gap-3 rounded-2xl border p-5 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive motion-reduce:transition-none ${section === item.key ? "border-olive/25 bg-olive/8" : "border-cocoa/10 bg-[#fffdfb] hover:border-olive/30"}`}><span className="flex items-center justify-between gap-2 text-xs font-medium text-brown">{item.label}<item.icon size={18} aria-hidden="true" className="text-olive" /></span><span className="text-3xl font-semibold tabular-nums">{!totals ? <span className="text-sm font-normal">Loading...</span> : item.value == null ? <span className="text-sm">Unavailable</span> : new Intl.NumberFormat("en").format(item.value)}</span><span className="text-[11px] text-brown">{item.key === "cms" ? "Parent categories" : "All " + item.label.toLowerCase()}{item.error ? " · " + item.error : ""}</span></button>)}</div>
    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-cocoa/10 pb-4"><div role="group" aria-label="Catalog sections" className="flex flex-wrap gap-2">{sections.map((item) => <Button key={item.key} variant={section === item.key ? "primary" : "ghost"} aria-pressed={section === item.key} startIcon={<item.icon size={16} />} onClick={() => select(item.key)}>{item.label}</Button>)}</div><Button variant="outline" size="sm" onClick={changed}>Refresh catalog</Button></div>
    <div hidden={section !== "products"}><ProductManager initialCreate={initialCreate} catalogRevision={revision} onCatalogChanged={changed} browse={browse} /></div>
    {visited.includes("categories") && <div hidden={section !== "categories"}><TaxonomyManager kind="categories" revision={revision} onChanged={changed} onBrowse={browseProducts} /></div>}
    {visited.includes("tags") && <div hidden={section !== "tags"}><TaxonomyManager kind="tags" revision={revision} onChanged={changed} onBrowse={browseProducts} /></div>}
    {visited.includes("cms") && <div hidden={section !== "cms"}><TaxonomyManager kind="categories" rootOnly revision={revision} onChanged={changed} onBrowse={browseProducts} /></div>}
  </div>;
}
