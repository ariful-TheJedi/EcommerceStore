"use client";

import { useEffect, useState, type FormEvent } from "react";
import { CalendarDays, Check, ChevronLeft, ChevronRight, RefreshCw, Search, SlidersHorizontal, X } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { orderStatuses, orderMoney, orderDate, type OrderListing, type OrderReport as Report, type OrderDetail } from "@/admin/services/orders";
import { Field, productInput } from "./ProductFields";
import OrderDetails from "./OrderDetails";
import OrderDrawer from "./OrderDrawer";
import OrderReport from "./OrderReport";
import OrderStatusBadge from "./OrderStatusBadge";
import ManualOrderForm from "./ManualOrderForm";
import OrderDeletion from "./OrderDeletion";
import BulkOrderDeletion from "./BulkOrderDeletion";
import DeliveryChargeButton from "./DeliveryChargeButton";

const emptyFilters = { search: "", phone: "", status: "", from: "", to: "" };
type Filters = typeof emptyFilters;

function periodDates(period: string): { from: string; to: string } {
  const end = new Date();
  const start = new Date(end);
  if (period === "week") { start.setUTCDate(start.getUTCDate() - 6); }
  if (period === "month") { start.setUTCDate(1); }
  return period === "all" ? { from: "", to: "" } : { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) };
}

export default function OrderManager() {
  const { request } = useAdminAuth();

  const [view, setView] = useState<"orders" | "create" | "calculations" | "delete">("orders");
  const [draft, setDraft] = useState<Filters>(emptyFilters);
  const [filters, setFilters] = useState<Filters>(emptyFilters);
  const [period, setPeriod] = useState("all");
  const [moreFilters, setMoreFilters] = useState(false);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [detailRevision, setDetailRevision] = useState(0);
  const [listing, setListing] = useState<OrderListing | null>(null);
  const [report, setReport] = useState<Report | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState<number | null>(null);
  const [detail, setDetail] = useState<OrderDetail | null>(null);
  const [detailError, setDetailError] = useState("");
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => { if (value) { params.set(key, value); } });
    const reportParams = new URLSearchParams(params);
    reportParams.delete("status");
    if (view === "delete") { params.set("deletion_list", "1"); }
    setLoading(true); setError(""); setListing(null);
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)]);
    Promise.all([
      request<OrderListing>(`/orders?${params}&page=${page}&per_page=15`, { signal }),
      request<Report>(`/orders/report?${reportParams}`, { signal }),
    ]).then(([orders, summary]) => { if (!controller.signal.aborted) { setListing(orders); setReport(summary); } })
      .catch((failure) => { if (!controller.signal.aborted) { setReport(null); setError(failure instanceof Error ? failure.message : "Unable to load orders."); } })
      .finally(() => { if (!controller.signal.aborted) { setLoading(false); } });
    return () => controller.abort();
  }, [request, filters, page, revision, view]);

  useEffect(() => {
    if (selected === null) { setDetail(null); return; }
    const controller = new AbortController();
    setDetail(null); setDetailError("");
    request<{ order: OrderDetail }>(`/orders/${selected}`, { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) })
      .then((data) => { if (!controller.signal.aborted) { setDetail(data.order); } })
      .catch((failure) => { if (!controller.signal.aborted) { setDetailError(failure instanceof Error ? failure.message : "Unable to load order."); } });
    return () => controller.abort();
  }, [request, selected, detailRevision]);

  function apply(event: FormEvent) {
    event.preventDefault(); setPage(1); setFilters({ ...draft });
  }
  function change(key: keyof Filters, value: string) { setDraft((current) => ({ ...current, [key]: value })); }
  function reset() { const cleared = { ...emptyFilters, status: filters.status }; setDraft(cleared); setFilters(cleared); setPeriod("all"); setPage(1); }
  function selectStatus(status: string) {
    setFilters((current) => ({ ...current, status })); setDraft((current) => ({ ...current, status })); setPage(1);
  }
  const hasFilters = Boolean(filters.search || filters.phone || filters.from || filters.to);
  const dateLabel = filters.from || filters.to ? `${filters.from || "Any date"} to ${filters.to || "Any date"}` : "All time";
  const services = [
    { key: "orders" as const, label: "Manage Orders", description: "Update order statuses and find orders by status or date range." },
    { key: "create" as const, label: "Add New Order", description: "Create a customer order and reserve its products." },
    { key: "calculations" as const, label: "Calculations", description: "Income, expenses, returns, and profit or loss for a date range." },
    { key: "delete" as const, label: "Delete Orders", description: "Review and delete orders in any status, including new orders." },
  ];
  const activeService = services.find((item) => item.key === view) || services[0];
  const serviceDescription = activeService.description;

  return <div className="mx-auto flex max-w-7xl flex-col gap-5" style={{ fontFamily: "Arial, sans-serif" }}>
    <header className="overflow-hidden rounded-2xl border border-cocoa/10 bg-[#fffdfb]">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-cocoa/8 px-5 py-5 sm:px-6"><div><h1 className="text-2xl font-semibold">Orders</h1><p className="mt-1.5 text-sm text-brown">Manage your orders, create new ones, and track your results.</p></div><DeliveryChargeButton /></div>
      <nav aria-label="Order page services" className="grid grid-cols-2 gap-1 p-2 lg:grid-cols-4">{services.map((item, index) => <button key={item.key} type="button" aria-current={view === item.key ? "page" : undefined} onClick={() => {
        if (view === item.key) { return; }
        setListing(null); setView(item.key); selectStatus(""); setNotice("");
      }} className={`flex min-h-12 items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-olive ${view === item.key ? "bg-olive text-ivory" : "text-brown hover:bg-cocoa/5"}`}><span className={`flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] ${view === item.key ? "bg-ivory/15" : "bg-cocoa/5"}`}>{index + 1}</span>{item.label}</button>)}</nav>
    </header>
    <header className="flex flex-wrap items-center justify-between gap-4">
      <div><h2 className="text-lg font-semibold">{activeService.label}</h2><p className="mt-1.5 text-xs leading-5 text-brown">{serviceDescription}</p></div>
      <Button variant="outline" size="sm" startIcon={<RefreshCw size={15} />} disabled={loading} onClick={() => setRevision((value) => value + 1)}>Refresh</Button>
    </header>

    {view !== "create" && <form onSubmit={apply} className="rounded-2xl border border-cocoa/10 bg-[#fffdfb] p-4 sm:p-5">
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1"><label htmlFor="order-search" className="mb-2 block text-xs font-medium text-brown">Search orders</label><div className="relative"><Search size={18} aria-hidden="true" className="pointer-events-none absolute top-3.5 left-3 text-brown" /><input id="order-search" className={`${productInput} pl-10`} value={draft.search} maxLength={150} onChange={(event) => change("search", event.target.value)} placeholder="Search order number, product or SKU..." /></div></div>
        {view !== "calculations" && <div className="min-w-40 flex-1 sm:max-w-48"><label htmlFor="order-status-filter" className="mb-2 block text-xs font-medium text-brown">Order status</label><select id="order-status-filter" className={productInput} value={draft.status} onChange={(event) => selectStatus(event.target.value)}><option value="">All statuses</option>{Object.entries(orderStatuses).map(([status, label]) => <option key={status} value={status}>{label}</option>)}</select></div>}
        <div className="min-w-40 flex-1 sm:max-w-48"><label htmlFor="order-period" className="mb-2 block text-xs font-medium text-brown">Time period</label><select id="order-period" className={productInput} value={period} onChange={(event) => {
          const value = event.target.value; setPeriod(value);
          if (value === "custom") { setMoreFilters(true); return; }
          const dates = periodDates(value);
          setDraft((current) => ({ ...current, ...dates })); setFilters((current) => ({ ...current, ...dates })); setPage(1);
        }}><option value="all">All time</option><option value="today">Today</option><option value="week">Last 7 days</option><option value="month">This month</option><option value="custom">Custom dates</option></select></div>
        <Button variant={moreFilters ? "outline" : "ghost"} startIcon={<SlidersHorizontal size={16} />} aria-expanded={moreFilters} aria-controls="advanced-order-filters" onClick={() => setMoreFilters((value) => !value)}>Filters{filters.phone ? " (1)" : ""}</Button>
        <Button type="submit">Search</Button>
      </div>
      <div id="advanced-order-filters" hidden={!moreFilters} className="mt-5 border-t border-cocoa/8 pt-5">
        <div className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Customer or delivery phone" name="order-phone"><input id="order-phone" type="tel" className={productInput} value={draft.phone} maxLength={20} onChange={(event) => change("phone", event.target.value)} placeholder="Enter phone number" /></Field>
          <Field label="Placed from (UTC)" name="orders-from"><input id="orders-from" type="date" className={productInput} value={draft.from} onChange={(event) => { change("from", event.target.value); setPeriod("custom"); }} /></Field>
          <Field label="Placed to (UTC)" name="orders-to"><input id="orders-to" type="date" className={productInput} min={draft.from || undefined} value={draft.to} onChange={(event) => { change("to", event.target.value); setPeriod("custom"); }} /></Field>
          <Button type="submit" variant="outline">Apply filters</Button>
        </div><p className="mt-3 text-xs text-brown">For a single day, choose the same date in both fields.</p>
      </div>
      {hasFilters && <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-cocoa/8 pt-4 text-xs text-brown">
        {filters.search && <span className="rounded-lg bg-cocoa/5 px-3 py-1.5">Search: {filters.search}</span>}
        {filters.phone && <span className="rounded-lg bg-cocoa/5 px-3 py-1.5">Phone: {filters.phone}</span>}
        {(filters.from || filters.to) && <span className="inline-flex items-center gap-2 rounded-lg bg-cocoa/5 px-3 py-1.5"><CalendarDays size={13} aria-hidden="true" />{dateLabel}</span>}
        <button type="button" onClick={reset} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2 font-medium hover:bg-cocoa/5 focus-visible:outline-2 focus-visible:outline-olive"><X size={13} aria-hidden="true" />Clear filters</button>
      </div>}
    </form>}

    {notice && <div role="status" className="flex items-center gap-2 rounded-xl border border-olive/15 bg-olive/7 px-4 py-3 text-sm text-olive"><Check size={17} aria-hidden="true" /><span className="flex-1">{notice}</span><button type="button" onClick={() => setNotice("")} aria-label="Dismiss confirmation" className="flex size-9 items-center justify-center rounded-lg hover:bg-olive/10 focus-visible:outline-2 focus-visible:outline-olive"><X size={16} aria-hidden="true" /></button></div>}
    {error && <div role="alert" className="rounded-xl border border-[#9b3528]/20 bg-[#9b3528]/5 p-4 text-sm text-[#9b3528]">{error} <Button variant="ghost" size="sm" onClick={() => setRevision((value) => value + 1)}>Retry</Button></div>}

    {view === "create" ? <ManualOrderForm onCreated={(order) => {
      setView("orders"); setFilters(emptyFilters); setDraft(emptyFilters); setPeriod("all"); setPage(1);
      setNotice("Order " + order.order_number + " created successfully."); setRevision((value) => value + 1);
    }} /> : view === "calculations" ? <>
      <section aria-label="Order totals" className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[
        ["Total orders", report?.total_orders], ["Delivered orders", report?.counts.delivered ?? 0],
        ["Returned orders", report?.counts.returned ?? 0], ["New orders", report?.counts.pending ?? 0],
      ].map(([label, value]) => <div key={label} className="rounded-xl border border-cocoa/10 bg-[#fffdfb] p-4"><p className="text-xs text-brown">{label}</p><p className="mt-2 text-2xl font-semibold tabular-nums">{loading ? "—" : value ?? "—"}</p></div>)}</section>
      <OrderReport report={report} loading={loading} service="profit" />
    </> : <>
      <section className="overflow-hidden rounded-2xl border border-cocoa/10 bg-[#fffdfb]" aria-label="Order list">
        <div className="flex flex-wrap items-center justify-between gap-2 px-5 py-5"><h2 className="font-semibold">{activeService.label}</h2><p aria-live="polite" className="text-xs text-brown">{loading ? "Updating..." : `${listing?.pagination.total ?? 0} ${filters.status ? orderStatuses[filters.status].toLowerCase() + " · " : ""}matching orders`}</p></div>
        {loading ? <div role="status" className="flex min-h-64 items-center justify-center gap-3 border-t border-cocoa/8 text-sm text-brown"><RefreshCw size={17} className="animate-spin motion-reduce:animate-none" aria-hidden="true" />Loading orders...</div> : listing?.orders.length ? view === "delete" ? <BulkOrderDeletion orders={listing.orders} onDeleted={(count) => { setPage(1); setNotice(`${count} orders deleted. Financial history is preserved.`); setRevision((value) => value + 1); }} /> : <>
          <div className="hidden overflow-x-auto md:block"><table className="w-full min-w-[640px] text-left text-sm"><thead className="border-y border-cocoa/8 bg-cocoa/2 text-xs text-brown"><tr>{["Order", "Customer", "Status", "Amount", ""].map((label, index) => <th key={index} scope="col" className="px-5 py-3 font-medium">{label || <span className="sr-only">Open order</span>}</th>)}</tr></thead><tbody className="divide-y divide-cocoa/6">{listing.orders.map((order) => <tr key={order.id} className="hover:bg-rose/10"><td className="px-5 py-4"><button type="button" onClick={() => { setNotice(""); setSelected(order.id); }} className="max-w-64 break-all text-left text-[13px] font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-olive">{order.order_number}</button><p className="mt-1.5 text-xs text-brown">{orderDate(order.created_at)} UTC</p></td><td className="px-5 py-4"><p className="font-medium">{order.contact?.name || "Guest"}</p><p className="mt-1.5 text-xs text-brown">{order.contact?.phone || "No phone"}</p></td><td className="px-5 py-4"><OrderStatusBadge status={order.status} /><p className="mt-2 text-xs capitalize text-brown">Payment {order.payment_status.replaceAll("_", " ")}</p></td><td className="px-5 py-4"><p className="whitespace-nowrap font-semibold tabular-nums">{orderMoney(order.total_amount, order.currency)}</p><p className="mt-1.5 text-xs text-brown">{order.item_count} {order.item_count === 1 ? "item" : "items"}</p></td><td className="px-4 py-4"><Button size="sm" variant="outline" aria-label={`View order ${order.order_number}`} endIcon={<ChevronRight size={15} />} onClick={() => { setNotice(""); setSelected(order.id); }}>Open</Button></td></tr>)}</tbody></table></div>
          <div className="divide-y divide-cocoa/8 border-t border-cocoa/8 md:hidden">{listing.orders.map((order) => <button type="button" key={order.id} onClick={() => { setNotice(""); setSelected(order.id); }} aria-label={`View order ${order.order_number}`} className="flex w-full flex-col gap-3 p-4 text-left hover:bg-rose/10 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-olive"><div className="flex w-full items-center justify-between gap-3"><span className="break-all text-xs font-semibold">{order.order_number}</span><ChevronRight size={17} aria-hidden="true" className="shrink-0 text-brown" /></div><div className="flex w-full flex-wrap items-center justify-between gap-2"><span className="text-sm">{order.contact?.name || "Guest"}</span><OrderStatusBadge status={order.status} /></div><div className="flex w-full items-end justify-between gap-3"><span className="text-xs leading-5 text-brown">{orderDate(order.created_at)} UTC<br />{order.item_count} items · Payment {order.payment_status.replaceAll("_", " ")}</span><span className="whitespace-nowrap text-sm font-semibold">{orderMoney(order.total_amount, order.currency)}</span></div></button>)}</div>
        </> : <div className="flex min-h-64 flex-col items-center justify-center gap-3 border-t border-cocoa/8 p-6 text-center"><span className="rounded-2xl bg-rose/25 p-4 text-olive"><Search size={25} strokeWidth={1.5} aria-hidden="true" /></span><h3 className="font-semibold">{error ? "Orders unavailable" : "No orders found"}</h3><p className="max-w-xs text-sm leading-6 text-brown">{error ? "Try refreshing the page to load your orders." : "Try another search, status, or date range."}</p>{!error && <Button variant="outline" size="sm" onClick={reset}>Clear search filters</Button>}</div>}
        <footer className="flex items-center justify-between gap-3 border-t border-cocoa/8 p-4"><p className="text-xs text-brown">{listing ? `Page ${page} of ${listing.pagination.last_page}` : "Order list"}</p><div className="flex gap-2"><Button variant="outline" size="sm" startIcon={<ChevronLeft size={14} />} disabled={loading || !listing || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><Button variant="outline" size="sm" endIcon={<ChevronRight size={14} />} disabled={loading || !listing || page >= listing.pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button></div></footer>
      </section>
    </>}

    {selected !== null && <OrderDrawer busy={saving} onClose={() => { setSelected(null); setDetail(null); }}>
      {detailError ? <div role="alert" className="flex flex-col gap-4 py-10 text-sm text-[#9b3528]"><p>{detailError}</p><Button variant="outline" onClick={() => setDetailRevision((value) => value + 1)}>Retry loading order</Button></div> : detail && view === "delete" ? <OrderDeletion order={detail} onBusyChange={setSaving} onDeleted={() => { setSelected(null); setDetail(null); setPage(1); setNotice("Order deleted from active lists. Financial history is preserved."); setRevision((value) => value + 1); }} /> : detail ? <OrderDetails key={detail.id} order={detail} onBusyChange={setSaving} onChanged={(updated, message) => { setDetail(updated); setNotice(message); setRevision((value) => value + 1); }} /> : <p role="status" className="py-16 text-center text-sm text-brown">Loading order...</p>}
    </OrderDrawer>}
  </div>;
}
