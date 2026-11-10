"use client";

import { useState, type FormEvent } from "react";
import { Trash2 } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { orderMoney, type OrderDetail } from "@/admin/services/orders";
import OrderStatusBadge from "./OrderStatusBadge";
import { Field, productInput } from "./ProductFields";

export default function OrderDeletion({ order, onDeleted, onBusyChange }: { order: OrderDetail; onDeleted: () => void; onBusyChange: (busy: boolean) => void }) {
  const { request } = useAdminAuth();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function remove(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); onBusyChange(true); setError("");
    try {
      await request(`/orders/${order.id}`, { method: "DELETE", body: { reason: String(form.get("reason")) } });
      onDeleted();
    } catch (failure) { setError(failure instanceof Error ? failure.message : "Unable to delete order."); }
    finally { setBusy(false); onBusyChange(false); }
  }
  return <div className="flex flex-col gap-5"><div><OrderStatusBadge status={order.status} /><h3 className="mt-4 break-all text-lg font-semibold">{order.order_number}</h3><p className="mt-2 text-sm text-brown">{order.contact?.name || "Guest"} · {order.contact?.phone}</p><p className="mt-3 text-xl font-semibold">{orderMoney(order.total_amount, order.currency)}</p></div><ul className="divide-y divide-cocoa/10">{order.items.map((item, index) => <li key={index} className="flex justify-between gap-3 py-3 text-sm"><span>{item.product_name}</span><span className="shrink-0 text-brown">Qty {item.quantity}</span></li>)}</ul>
    <p className="rounded-xl bg-ivory p-4 text-sm leading-6 text-brown">Deleting removes this order from active order lists. Financial records and history remain available for calculations. Unshipped orders are cancelled and their reserved stock is released. Shipped orders keep their existing delivery, stock deductions, and payment history.</p>
    {!order.can_process ? <p className="text-sm text-brown">You need order processing permission to delete orders.</p> : <form onSubmit={(event) => void remove(event)} className="flex flex-col gap-4">{error && <p role="alert" className="text-sm text-[#9b3528]">{error}</p>}<Field label="Reason for deletion" name="order-delete-reason"><textarea id="order-delete-reason" name="reason" required rows={3} maxLength={1000} disabled={busy} className={productInput} placeholder="Explain why this order should be deleted" /></Field><label className="flex items-start gap-3 text-sm leading-6"><input type="checkbox" required disabled={busy} className="mt-1 size-4 shrink-0" /><span>I have reviewed this order and want to remove it from the order list.</span></label><Button type="submit" loading={busy} startIcon={<Trash2 size={16} />} className="border-[#9b3528] bg-[#9b3528] text-white">Delete order</Button></form>}
  </div>;
}
