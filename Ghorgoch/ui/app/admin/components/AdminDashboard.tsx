"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Activity, ArrowUpRight, CalendarDays, ChartNoAxesCombined, ClipboardList, FolderTree, Package, RefreshCw, ShoppingBag, Tags, Users } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { loadStoreSummary, type StoreSummary } from "@/admin/services/dashboard";
import content from "@/data/admin/dashboard.json";

const number = new Intl.NumberFormat("en");
const cards = [
  { key: "orders", label: content.orders, hint: content.ordersHint, icon: ShoppingBag, href: "/admin/orders" },
  { key: "newOrders", label: content.newOrders, hint: content.newOrdersHint, icon: ClipboardList, href: "/admin/orders" },
  { key: "products", label: content.products, hint: content.productsHint, icon: Package, href: "/admin/products" },
  { key: "customers", label: content.customers, hint: content.customersHint, icon: Users },
  { key: "categories", label: content.categories, hint: content.categoriesHint, icon: FolderTree },
  { key: "tags", label: content.tags, hint: content.tagsHint, icon: Tags },
] as const;

export default function AdminDashboard() {
  const { request } = useAdminAuth();
  const [revision, setRevision] = useState(0);
  const [summary, setSummary] = useState<StoreSummary | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]);
    setLoading(true); setSummary(null);
    loadStoreSummary(request, signal).then((data) => {
      if (!controller.signal.aborted) { setSummary(data); }
    }).finally(() => { if (!controller.signal.aborted) { setLoading(false); } });
    return () => controller.abort();
  }, [request, revision]);

  return <section aria-labelledby="dashboard-title" className="mx-auto flex max-w-7xl flex-col gap-8 text-cocoa" style={{ fontFamily: "Arial, sans-serif" }}>
    <div className="flex flex-wrap items-end justify-between gap-5">
      <div className="flex flex-col gap-3"><p className="text-[10px] font-semibold tracking-[0.18em] text-brown">{content.eyebrow}</p><h1 id="dashboard-title" className="font-[inherit] text-3xl font-semibold tracking-tight sm:text-4xl">{content.dashboard}</h1><p className="text-sm leading-relaxed text-brown">{content.description}</p></div>
      <Button variant="outline" size="sm" className="rounded-xl bg-[#fffdfb] shadow-sm" onClick={() => setRevision((value) => value + 1)} loading={loading} loadingLabel={content.refreshing} startIcon={<RefreshCw size={15} />}>{content.refresh}</Button>
    </div>
    <div className="flex flex-col gap-4">
    <div className="flex items-center justify-between gap-3"><h2 className="font-[inherit] text-sm font-semibold">{content.summary}</h2><span className="flex items-center gap-2 text-xs text-brown"><CalendarDays size={14} aria-hidden="true" />{content.allTime}</span></div>
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy={loading}>
      {cards.map((card) => {
        const metric = summary?.[card.key];
        const featured = card.key === "orders";
        return <article key={card.key} className={`relative flex flex-col gap-5 overflow-hidden rounded-2xl border p-5 shadow-[0_2px_10px_#382e2c04] sm:p-6 ${featured ? "border-olive bg-olive text-ivory" : "border-cocoa/8 bg-[#fffdfb]"}`}>
          <div className="flex items-center justify-between gap-3">
            <span className={`flex size-10 items-center justify-center rounded-xl ${featured ? "bg-ivory/10" : card.key === "newOrders" ? "bg-[#fbecdd] text-[#97602b]" : "bg-olive/7 text-olive"}`}><card.icon size={19} strokeWidth={1.7} aria-hidden="true" /></span>
            {"href" in card && <Link href={card.href} aria-label={card.label} className={`flex size-11 items-center justify-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 motion-reduce:transition-none ${featured ? "text-ivory/70 hover:bg-ivory/10 focus-visible:outline-ivory" : "text-brown hover:bg-cocoa/5 focus-visible:outline-olive"}`}><ArrowUpRight size={18} aria-hidden="true" /></Link>}
          </div>
          <div className="flex flex-col gap-2"><h3 className={`font-[inherit] text-sm font-medium ${featured ? "text-ivory/80" : "text-brown"}`}>{card.label}</h3>
          <p className="min-h-11 text-[2.5rem] leading-none font-semibold tracking-tight tabular-nums">{loading ? <><span aria-hidden="true" className={`inline-block h-9 w-20 animate-pulse rounded-md motion-reduce:animate-none ${featured ? "bg-ivory/15" : "bg-cocoa/8"}`} /><span className="sr-only">{content.loading}</span></> : metric?.data == null ? <span className="text-base font-medium">{content.unavailable}</span> : number.format(metric.data)}</p></div>
          <p className={`border-t pt-3 text-[11px] leading-relaxed ${featured ? "border-ivory/15 text-ivory/75" : "border-cocoa/6 text-brown"}`}>{card.hint}</p>
          {metric?.error && <p className={`text-xs ${featured ? "text-ivory/80" : "text-brown"}`}>{metric.error}</p>}
        </article>;
      })}
    </div>
    </div>
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
      <section aria-labelledby="traffic-summary-title" className="flex flex-col overflow-hidden rounded-2xl border border-cocoa/8 bg-[#fffdfb] shadow-[0_2px_10px_#382e2c04]">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-cocoa/6 px-6 py-5"><div><h2 id="traffic-summary-title" className="font-[inherit] text-base font-semibold">{content.traffic}</h2><p className="mt-1 text-xs text-brown">{content.trafficSubtitle}</p></div><span className="rounded-lg border border-cocoa/10 px-3 py-2 text-[11px] text-brown">{content.lastSevenDays}</span></div>
        <div className="m-5 flex min-h-60 flex-1 flex-col items-center justify-center gap-3 rounded-xl bg-ivory/70 p-6 text-center">
          <span className="mb-1 flex size-14 items-center justify-center rounded-2xl border border-cocoa/8 bg-[#fffdfb] shadow-sm"><ChartNoAxesCombined size={25} strokeWidth={1.5} aria-hidden="true" className="text-olive" /></span>
          <p className="text-sm font-semibold">{content.trafficUnavailable}</p><p className="max-w-64 text-xs leading-relaxed text-brown">{content.trafficHint}</p>
          <Link href="/admin/traffic" className="inline-flex min-h-11 items-center gap-2 text-xs font-semibold text-olive focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive">{content.viewTraffic}<ArrowUpRight size={15} aria-hidden="true" /></Link>
        </div>
        <div className="flex items-center gap-2 border-t border-cocoa/6 px-6 py-3 text-[11px] text-brown"><Activity size={13} aria-hidden="true" /><span className="size-1.5 rounded-full bg-brown/40" aria-hidden="true" />{content.notConnected}</div>
      </section>
      <section aria-labelledby="recent-categories-title" className="flex flex-col overflow-hidden rounded-2xl border border-cocoa/8 bg-[#fffdfb] shadow-[0_2px_10px_#382e2c04]">
        <div className="flex items-center justify-between gap-3 border-b border-cocoa/6 px-6 py-5"><div><h2 id="recent-categories-title" className="font-[inherit] text-base font-semibold">{content.recentCategories}</h2><p className="mt-1 text-xs text-brown">{content.recentHint}</p></div><FolderTree size={19} strokeWidth={1.5} aria-hidden="true" className="shrink-0 text-olive" /></div>
        {loading ? <p role="status" className="p-8 text-sm text-brown">{content.loading}</p>
          : summary?.recentCategories.error ? <p className="p-8 text-sm text-brown">{summary.recentCategories.error}</p>
          : !summary?.recentCategories.data?.length ? <div className="flex min-h-60 flex-1 flex-col items-center justify-center gap-3 p-8 text-center"><FolderTree size={26} strokeWidth={1.5} aria-hidden="true" className="text-olive" /><p className="text-sm text-brown">{content.noCategories}</p></div>
          : <ul className="flex-1 divide-y divide-cocoa/6 px-6">{summary.recentCategories.data.map((category) => <li key={category.id} className="flex items-center gap-3 py-5">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-olive/7 text-olive"><FolderTree size={17} strokeWidth={1.7} aria-hidden="true" /></span><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{category.name}</p><p className="mt-1 truncate text-[11px] text-brown">{category.slug}</p></div><span className={`flex items-center gap-1.5 rounded-full px-2 py-1 text-[10px] font-medium ${category.is_active ? "bg-olive/8 text-olive" : "bg-cocoa/5 text-brown"}`}><span aria-hidden="true" className="size-1 rounded-full bg-current" />{category.is_active ? content.active : content.inactive}</span>
          </li>)}</ul>}
        <p className="border-t border-cocoa/6 px-6 py-3 text-[11px] text-brown">{content.latest}</p>
      </section>
    </div>
  </section>;
}
