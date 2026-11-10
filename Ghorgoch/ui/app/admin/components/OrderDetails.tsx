"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Check, CheckCheck, ChevronRight, Clock3, MapPin, Package, Receipt, Truck, UserRound } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { orderStatuses, orderMoney, orderDate, type OrderDetail } from "@/admin/services/orders";
import { Field, productInput } from "./ProductFields";
import OrderStatusBadge from "./OrderStatusBadge";

const steps = ["pending", "confirmed", "processing", "shipped", "delivered"];
const actionLabels: Record<string, string> = {
  confirmed: "Confirm order", processing: "Mark as packed", shipped: "Send for delivery",
  delivered: "Mark as delivered", returned: "Record return", cancelled: "Cancel order",
};
const historyLabels: Record<string, string> = {
  placed: "Order placed", finances: "Costs updated", staff_note: "Staff note added",
  delivery: "Delivery updated", address_corrected: "Address corrected",
};

export default function OrderDetails({ order, onChanged, onBusyChange }: {
  order: OrderDetail;
  onChanged: (order: OrderDetail, message: string) => void;
  onBusyChange: (busy: boolean) => void;
}) {
  const { request } = useAdminAuth();
  const [section, setSection] = useState<"overview" | "costs" | "history">("overview");
  const [status, setStatus] = useState(order.allowed_transitions[0] || "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, []);
  useEffect(() => { setStatus(order.allowed_transitions[0] || ""); }, [order.status, order.allowed_transitions]);
  const currentStep = steps.indexOf(order.status);
  const needsReason = status === "returned" || status === "cancelled";

  async function save(event: FormEvent<HTMLFormElement>, action: "status" | "finances") {
    event.preventDefault();
    const element = event.currentTarget;
    const form = new FormData(element);
    const body = action === "status" ? {
      status, ...(needsReason ? { reason: String(form.get("reason")) } : { note: String(form.get("reason") || "") }),
      ...(status === "returned" ? { restock: form.has("restock") } : {}),
    } : { product_cost: String(form.get("product_cost") || "") || null, expense_amount: String(form.get("expense_amount")), note: String(form.get("note")) };
    setBusy(true); onBusyChange(true); setError(""); setSuccess("");
    try {
      const data = await request<{ order: OrderDetail }>(`/orders/${order.id}/${action}`, { method: "PATCH", body });
      const message = action === "status" ? `Order updated to ${orderStatuses[data.order.status].toLowerCase()}.` : "Order costs saved. Reports are up to date.";
      if (action === "status") { element.reset(); }
      setSuccess(message); onChanged(data.order, message);
    } catch (failure) { setError(failure instanceof Error ? failure.message : "Unable to save order."); }
    finally { setBusy(false); onBusyChange(false); }
  }

  return <div className="flex flex-col gap-6">
    <header className="flex flex-col gap-3"><div className="flex flex-wrap items-center gap-2"><OrderStatusBadge status={order.status} /><span className="text-xs capitalize text-brown">Payment {order.payment_status.replaceAll("_", " ")}</span></div><h3 ref={heading} tabIndex={-1} className="break-all text-lg font-semibold tracking-tight focus:outline-none sm:text-xl">{order.order_number}</h3><div className="flex flex-wrap items-center justify-between gap-3"><p className="text-xs text-brown">Placed {orderDate(order.created_at)} UTC</p><p className="text-xl font-semibold tabular-nums">{orderMoney(order.total_amount, order.currency)}</p></div></header>

    {currentStep >= 0 && <ol aria-label="Order progress" className="grid grid-cols-5 gap-1 rounded-xl bg-ivory px-2 py-4">{steps.map((step, index) => <li key={step} aria-current={index === currentStep ? "step" : undefined} className="flex min-w-0 flex-col items-center gap-2 text-center"><span className={`flex size-6 items-center justify-center rounded-full text-[10px] font-semibold ${index <= currentStep ? "bg-olive text-ivory" : "bg-cocoa/7 text-brown"}`}>{index < currentStep ? <Check size={13} aria-hidden="true" /> : index + 1}</span><span className={`text-[10px] leading-4 ${index === currentStep ? "font-semibold text-olive" : "text-brown"}`}>{["New", "Confirmed", "Packed", "In delivery", "Delivered"][index]}</span></li>)}</ol>}

    <div role="group" aria-label="Order detail sections" className="grid grid-cols-3 gap-1 rounded-xl bg-cocoa/4 p-1">{[
      { key: "overview" as const, label: "Details", icon: Package },
      { key: "costs" as const, label: "Costs", icon: Receipt },
      { key: "history" as const, label: "History", icon: Clock3 },
    ].map((item) => <button key={item.key} type="button" disabled={busy} aria-pressed={section === item.key} onClick={() => { setSection(item.key); setError(""); setSuccess(""); }} className={`flex min-h-11 items-center justify-center gap-2 rounded-lg text-xs font-semibold focus-visible:outline-2 focus-visible:outline-olive disabled:opacity-50 ${section === item.key ? "bg-[#fffdfb] text-cocoa shadow-sm" : "text-brown hover:text-cocoa"}`}><item.icon size={15} aria-hidden="true" />{item.label}</button>)}</div>

    {success && <p role="status" className="flex items-start gap-2 rounded-xl bg-olive/8 p-3 text-sm leading-6 text-olive"><CheckCheck size={18} className="mt-1 shrink-0" aria-hidden="true" />{success}</p>}
    {error && <p role="alert" className="rounded-xl bg-[#9b3528]/5 p-4 text-sm leading-6 text-[#9b3528]">{error}</p>}

    <div hidden={section !== "overview"} className="flex flex-col gap-6">
      {order.can_process && order.allowed_transitions.length > 0 && <form onSubmit={(event) => void save(event, "status")} className="flex flex-col gap-4 rounded-2xl border border-olive/20 bg-olive/4 p-4 sm:p-5">
        <div className="flex items-center gap-2"><Truck size={17} className="text-olive" aria-hidden="true" /><h4 className="text-sm font-semibold">Update fulfillment</h4></div>
        <Field label="Update status to" name="next-status"><select id="next-status" className={productInput} value={status} disabled={busy} onChange={(event) => setStatus(event.target.value)}>{order.allowed_transitions.map((value) => <option key={value} value={value}>{orderStatuses[value]}</option>)}</select></Field>
        {needsReason ? <Field label={status === "returned" ? "Why is this order being returned?" : "Why is this order being cancelled?"} name="status-reason"><textarea id="status-reason" name="reason" className={productInput} maxLength={1000} required disabled={busy} rows={2} placeholder="Enter a reason..." /></Field> : <details><summary className="min-h-7 cursor-pointer text-xs font-medium text-brown focus-visible:outline-2 focus-visible:outline-olive">Add a staff note (optional)</summary><label htmlFor="status-note" className="sr-only">Staff note</label><textarea id="status-note" name="reason" className={`${productInput} mt-2`} maxLength={1000} disabled={busy} rows={2} placeholder="Add a note for your team..." /></details>}
        {status === "returned" && <label className="flex items-start gap-3 rounded-xl bg-[#fffdfb] p-3 text-sm leading-6"><input type="checkbox" name="restock" defaultChecked disabled={busy} className="mt-1 size-4 shrink-0 accent-olive" /><span><strong className="block text-xs">Restock all returned items</strong><span className="text-xs text-brown">Uncheck if items are damaged or lost. Their purchase cost will count as a loss.</span></span></label>}
        <Button type="submit" loading={busy} fullWidth endIcon={<ChevronRight size={16} />}>{actionLabels[status] || "Save status"}</Button>
      </form>}
      {order.status === "returned" && <p className="rounded-xl bg-[#fbebe5] p-4 text-sm leading-6 text-[#a15135]">This order was returned. Items were {order.return_restocked ? "added back to stock" : "recorded as a product loss"}. Reconcile any customer refund separately.</p>}
      {!order.can_process && <p className="rounded-xl bg-ivory p-3 text-xs leading-5 text-brown">View access only. Processing permission is needed to update this order.</p>}

      <section><h4 className="mb-4 flex items-center gap-2 text-sm font-semibold"><Package size={16} aria-hidden="true" />Products <span className="font-normal text-brown">({order.items.reduce((total, item) => total + item.quantity, 0)} items)</span></h4><div className="divide-y divide-cocoa/8">{order.items.map((item, index) => <div key={index} className="flex items-start justify-between gap-4 py-3"><div className="min-w-0"><p className="text-sm font-medium">{item.product_name}</p><p className="mt-1 break-all text-[11px] text-brown">{item.sku}</p><p className="mt-2 text-xs text-brown">{item.quantity} × {orderMoney(item.unit_price, order.currency)}</p></div><p className="shrink-0 pt-0.5 text-sm font-semibold tabular-nums">{orderMoney(item.line_total, order.currency)}</p></div>)}</div>
        <dl className="mt-3 grid grid-cols-[1fr_auto] gap-2.5 rounded-xl bg-ivory p-4 text-xs"><dt className="text-brown">Subtotal</dt><dd className="text-right tabular-nums">{orderMoney(order.subtotal, order.currency)}</dd>{Number(order.discount_amount) > 0 && <><dt className="text-brown">Discount</dt><dd className="text-right tabular-nums">-{orderMoney(order.discount_amount, order.currency)}</dd></>}<dt className="text-brown">Shipping</dt><dd className="text-right tabular-nums">{orderMoney(order.shipping_fee, order.currency)}</dd>{Number(order.tax_amount) > 0 && <><dt className="text-brown">Tax</dt><dd className="text-right tabular-nums">{orderMoney(order.tax_amount, order.currency)}</dd></>}<dt className="border-t border-cocoa/10 pt-3 text-sm font-semibold">Order total</dt><dd className="border-t border-cocoa/10 pt-3 text-right text-sm font-semibold tabular-nums">{orderMoney(order.total_amount, order.currency)}</dd></dl>
      </section>
      <section className="grid gap-5 border-t border-cocoa/10 pt-5 sm:grid-cols-2"><div><h4 className="mb-3 flex items-center gap-2 text-xs font-semibold"><UserRound size={15} aria-hidden="true" />Customer</h4><p className="text-sm font-medium">{order.contact?.name || "Guest"}</p>{order.contact?.phone && <a href={`tel:${order.contact.phone.replace(/[^\d+]/g, "")}`} className="mt-2 inline-block text-xs text-olive underline underline-offset-4">{order.contact.phone}</a>}{order.contact?.email && <p className="mt-2 break-all text-xs text-brown">{order.contact.email}</p>}</div><div><h4 className="mb-3 flex items-center gap-2 text-xs font-semibold"><MapPin size={15} aria-hidden="true" />Delivery address</h4><p className="text-sm">{order.delivery?.recipient_name}</p><p className="mt-2 text-xs leading-6 text-brown">{order.delivery && [order.delivery.address_line1, order.delivery.address_line2, order.delivery.area, order.delivery.upazila, order.delivery.city, order.delivery.district, order.delivery.division, order.delivery.postal_code].filter(Boolean).join(", ")}</p>{order.delivery?.phone && <p className="mt-2 text-xs text-brown">{order.delivery.phone}</p>}</div></section>
      {(order.customer_note || order.delivery?.delivery_instructions) && <section className="rounded-xl bg-ivory p-4"><h4 className="mb-2 text-xs font-semibold">Customer notes</h4>{order.customer_note && <p className="whitespace-pre-wrap text-sm leading-6 text-brown">{order.customer_note}</p>}{order.delivery?.delivery_instructions && <p className="mt-2 whitespace-pre-wrap text-xs leading-6 text-brown">Delivery: {order.delivery.delivery_instructions}</p>}</section>}
    </div>

    <section hidden={section !== "costs"}>
      <h4 className="text-base font-semibold">Order costs</h4><p className="mt-2 text-sm leading-6 text-brown">Keep purchase and operating costs accurate for reliable profit reports.</p>
      <dl className="mt-5 grid grid-cols-2 gap-4 rounded-xl bg-ivory p-4"><div><dt className="text-xs text-brown">Product purchase cost</dt><dd className="mt-2 text-lg font-semibold tabular-nums">{order.product_cost === null ? "Not recorded" : orderMoney(order.product_cost, order.currency)}</dd></div><div><dt className="text-xs text-brown">Operating expenses</dt><dd className="mt-2 text-lg font-semibold tabular-nums">{orderMoney(order.expense_amount, order.currency)}</dd></div></dl>
      {order.can_process ? <form key={`${order.product_cost}:${order.expense_amount}`} onSubmit={(event) => void save(event, "finances")} className="mt-6 flex flex-col gap-5">
        <Field label={`Total product purchase cost (${order.currency})`} name="product-cost"><input id="product-cost" name="product_cost" type="number" min="0" max="9999999999.99" step="0.01" defaultValue={order.product_cost ?? ""} placeholder="Enter total purchase cost" disabled={busy} className={productInput} /></Field>
        <Field label={`Total operating expenses (${order.currency})`} name="order-expenses"><input id="order-expenses" name="expense_amount" type="number" min="0" max="9999999999.99" step="0.01" required defaultValue={order.expense_amount} disabled={busy} className={productInput} /></Field><p className="-mt-3 text-xs leading-5 text-brown">Include courier, packaging, return charges, and other costs for this order.</p>
        <Field label="Reason for this update" name="finance-note"><textarea id="finance-note" name="note" required maxLength={1000} rows={2} disabled={busy} className={productInput} placeholder="For example: courier invoice received" /></Field>
        <Button type="submit" loading={busy}>Save costs</Button>
      </form> : <p className="mt-5 text-xs text-brown">Processing permission is required to edit costs.</p>}
      <details className="mt-6 border-t border-cocoa/10 pt-4"><summary className="cursor-pointer text-xs font-semibold text-brown focus-visible:outline-2 focus-visible:outline-olive">How are these costs used?</summary><p className="mt-3 text-xs leading-6 text-brown">These values replace the order's total costs; they do not add a new charge. New orders capture product purchase costs at checkout. Older orders need manual costs. Leave purchase cost blank only if unknown. Operating expenses start at zero until recorded.</p></details>
    </section>

    <section hidden={section !== "history"}><h4 className="text-base font-semibold">Order activity</h4><p className="mt-2 text-sm text-brown">Status updates and team changes, most recent first.</p>{order.history.length ? <ol className="mt-6 flex flex-col">{[...order.history].reverse().map((entry, index) => <li key={entry.id} className="relative flex gap-4 pb-6">{index < order.history.length - 1 && <span className="absolute top-6 bottom-0 left-3 border-l border-cocoa/10" aria-hidden="true" />}<span className="relative flex size-6 shrink-0 items-center justify-center rounded-full bg-olive/10 text-olive"><Clock3 size={12} aria-hidden="true" /></span><div className="min-w-0 pt-0.5"><p className="text-xs font-semibold">{entry.action === "status" ? `${orderStatuses[entry.previous_status || ""] || entry.previous_status || "Order"} → ${orderStatuses[entry.new_status] || entry.new_status}` : historyLabels[entry.action] || entry.action.replaceAll("_", " ")}</p><p className="mt-1.5 text-[11px] text-brown">{orderDate(entry.created_at)} UTC</p>{entry.note && <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-brown">{entry.note}</p>}</div></li>)}</ol> : <p className="mt-6 rounded-xl bg-ivory p-5 text-sm text-brown">No activity recorded yet.</p>}</section>
  </div>;
}
