"use client";

import { useEffect, useRef, useState } from "react";
import { Trash2 } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { orderMoney, orderDate, type OrderSummary } from "@/admin/services/orders";
import OrderStatusBadge from "./OrderStatusBadge";

export default function BulkOrderDeletion({ orders, onDeleted }: { orders: OrderSummary[]; onDeleted: (count: number) => void }) {
  const { request } = useAdminAuth();
  const [selected, setSelected] = useState<number[]>([]);
  const [deleting, setDeleting] = useState<number[]>([]);
  const [error, setError] = useState("");
  const allCheckbox = useRef<HTMLInputElement>(null);
  const eligible = orders.filter((order) => order.can_process);
  const allSelected = eligible.length > 0 && eligible.every((order) => selected.includes(order.id));
  const busy = deleting.length > 0;
  useEffect(() => { setSelected([]); setError(""); }, [orders]);
  useEffect(() => { if (allCheckbox.current) { allCheckbox.current.indeterminate = selected.length > 0 && !allSelected; } }, [selected, allSelected]);

  function toggle(id: number) { setSelected((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]); }
  async function remove(ids: number[]) {
    if (busy || !ids.length) { return; }
    setDeleting(ids); setError("");
    try {
      const data = await request<{ deleted_count: number }>("/orders/bulk-delete", { method: "POST", body: { order_ids: ids, reason: "Deleted from the admin Delete Orders page." } });
      setSelected([]); onDeleted(data.deleted_count);
    } catch (failure) { setError(failure instanceof Error ? failure.message : "Unable to delete orders. Refresh the list and try again."); }
    finally { setDeleting([]); }
  }

  return <>
    <div className="flex flex-wrap items-center justify-between gap-3 border-y border-cocoa/10 bg-ivory px-5 py-3">
      <label className="flex min-h-11 items-center gap-3 text-sm"><input ref={allCheckbox} type="checkbox" className="size-4 accent-olive" checked={allSelected} disabled={busy || !eligible.length} onChange={() => setSelected(allSelected ? [] : eligible.map((order) => order.id))} /><span>Select this page</span></label>
      <div className="flex items-center gap-3"><span role="status" className="text-xs text-brown">{selected.length} selected</span><Button size="sm" variant="outline" startIcon={<Trash2 size={15} />} disabled={busy || !selected.length} onClick={() => void remove(selected)}>Delete selected</Button></div>
    </div>
    <p className="px-5 py-3 text-xs leading-5 text-brown">Delete an order below, or select several to delete together. Financial history is preserved.</p>
    {error && <p role="alert" className="mx-5 mb-4 rounded-xl bg-[#9b3528]/5 p-3 text-sm leading-6 text-[#9b3528]">{error}</p>}
    <ul className="divide-y divide-cocoa/10">{orders.map((order) => <li key={order.id} className={`flex items-start gap-3 px-5 py-5 ${selected.includes(order.id) ? "bg-olive/5" : ""}`}>
      <input type="checkbox" aria-label={`Select order ${order.order_number}`} className="mt-1 size-4 shrink-0 accent-olive" checked={selected.includes(order.id)} disabled={busy || !eligible.some((item) => item.id === order.id)} onChange={() => toggle(order.id)} />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-start justify-between gap-4"><div><h3 className="break-all text-sm font-semibold">{order.order_number}</h3><p className="mt-2 text-xs text-brown">{orderDate(order.created_at)} UTC</p></div><OrderStatusBadge status={order.status} /></div>
        <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><p className="font-medium">{order.contact?.name || "Guest"}</p><p className="mt-1 text-xs text-brown">{order.contact?.phone}{order.contact?.email ? " · " + order.contact.email : ""}</p></div>{order.delivery && <p className="text-xs leading-6 text-brown">{[order.delivery.address_line1, order.delivery.area, order.delivery.city, order.delivery.district].filter(Boolean).join(", ")}</p>}</div>
        <ul className="mt-4 divide-y divide-cocoa/8 rounded-xl border border-cocoa/8 px-3">{order.items?.map((item, index) => <li key={index} className="flex items-start justify-between gap-4 py-3"><div className="min-w-0"><p className="text-sm font-medium">{item.product_name}</p><p className="mt-1 break-all text-xs text-brown">{item.sku} · Qty {item.quantity} × {orderMoney(item.unit_price, order.currency)}</p></div><span className="shrink-0 text-sm font-medium tabular-nums">{orderMoney(item.line_total, order.currency)}</span></li>)}</ul>
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3"><div><p className="text-xs text-brown">Order total · Payment {order.payment_status.replaceAll("_", " ")}</p><p className="mt-1 text-lg font-semibold tabular-nums">{orderMoney(order.total_amount, order.currency)}</p></div>{order.can_process ? <Button size="sm" variant="outline" startIcon={<Trash2 size={15} />} disabled={busy} loading={deleting.includes(order.id)} onClick={() => void remove([order.id])}>Delete order</Button> : <span className="text-xs text-brown">View access only</span>}</div>
      </div>
    </li>)}</ul>
  </>;
}
