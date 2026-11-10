"use client";

import Link from "next/link";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { Check, ChevronLeft, LockKeyhole, ShoppingBag, Trash2, Truck } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useCartStore } from "@/components/CartProvider";
import { api, money } from "@/components/catalog";
import { checkoutItems, shopRequest, type CheckoutQuote, type DeliveryCharges, type PlacedOrder } from "@/services/checkout";
import { rememberOrder } from "@/services/tracking";

const input = "min-h-12 w-full rounded-xl border border-cocoa/15 bg-ivory px-4 py-3 text-sm focus-visible:outline-2 focus-visible:outline-olive disabled:opacity-60";
const initialDetails = { name: "", phone: "", email: "", address: "" };

export default function CheckoutForm() {
  const { items, hydrated, updateQuantity, removeItem, clearCart } = useCartStore();
  const [details, setDetails] = useState(initialDetails);
  const [zone, setZone] = useState<"inside_dhaka" | "outside_dhaka">("inside_dhaka");
  const [charges, setCharges] = useState<DeliveryCharges | null>(null);
  const [ratesError, setRatesError] = useState("");
  const [rateRevision, setRateRevision] = useState(0);
  const [quote, setQuote] = useState<CheckoutQuote | null>(null);
  const [order, setOrder] = useState<PlacedOrder | null>(null);
  const [trackingCode, setTrackingCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const key = useRef("");
  const locked = useRef(false);
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    const controller = new AbortController();
    setRatesError("");
    api<DeliveryCharges>("/checkout/delivery-charges", { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) })
      .then((data) => { if (!controller.signal.aborted) { setCharges(data); } })
      .catch((failure) => { if (!controller.signal.aborted) { setRatesError(failure instanceof Error ? failure.message : "ডেলিভারি চার্জ লোড করা যায়নি।"); } });
    return () => controller.abort();
  }, [rateRevision]);
  useEffect(() => { setQuote(null); key.current = ""; }, [items, details, zone]);
  useEffect(() => { if (order) { heading.current?.focus(); } }, [order]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (locked.current || !items.length || !charges) { return; }
    locked.current = true; setBusy(true); setError("");
    let phone = details.phone.replace(/[\s()-]/g, "");
    if (/^01[3-9][0-9]{8}$/.test(phone)) { phone = "+88" + phone; }
    const payload = {
      payment_method: "cod", delivery_zone: zone,
      contact: { name: details.name.trim(), phone, ...(details.email.trim() ? { email: details.email.trim() } : {}) },
      delivery: { address_line1: details.address.trim() || null },
    };
    try {
      if (!quote) {
        const lines = await checkoutItems(items);
        await shopRequest("/cart/sync", { items: lines });
        const reviewed = await shopRequest<CheckoutQuote>("/checkout/review", payload);
        if (!key.current) { key.current = crypto.randomUUID(); } setQuote(reviewed);
        setCharges((current) => current ? { ...current, [zone]: reviewed.shipping_fee } : current);
      } else {
        const result = await shopRequest<{ order: PlacedOrder; guest_access_token?: string }>("/checkout", { ...payload, review_token: quote.review_token, idempotency_key: key.current });
        rememberOrder({ id: result.order.id, number: result.order.order_number, ...(result.guest_access_token ? { token: result.guest_access_token } : {}) });
        setTrackingCode(result.guest_access_token || "");
        setOrder(result.order); clearCart();
      }
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "অর্ডার সম্পন্ন করা যায়নি। আবার চেষ্টা করুন।");
    } finally { locked.current = false; setBusy(false); }
  }

  if (!hydrated) { return <p role="status" className="py-20 text-center text-brown">ব্যাগ লোড হচ্ছে...</p>; }
  if (order) {
    return <section className="mx-auto flex max-w-xl flex-col items-center gap-5 rounded-2xl border border-olive/20 bg-olive/5 p-7 text-center sm:p-12"><span className="rounded-full bg-olive/10 p-5 text-olive"><Check size={32} aria-hidden="true" /></span><h1 ref={heading} tabIndex={-1} lang="en" className="font-heading text-3xl">Your order has been placed successfully!</h1><p lang="en" role="status" className="text-sm leading-7 text-brown">We will contact you soon for confirmation. Please save your order number below.</p><p className="break-all rounded-xl bg-ivory px-4 py-3 text-sm font-semibold" lang="en">{order.order_number}</p><p className="text-xl font-semibold text-olive">মোট: {money(order.total_amount, order.currency)}</p><p className="text-xs text-brown">ক্যাশ অন ডেলিভারি</p><Link href={`/tracking?order=${encodeURIComponent(order.order_number)}`} className="inline-flex min-h-12 items-center justify-center rounded-card bg-olive px-6 py-3 text-sm text-ivory">?????? ??????? ????</Link>{trackingCode && <details className="w-full rounded-xl border border-cocoa/15 bg-ivory p-4 text-left"><summary className="cursor-pointer text-sm font-medium">???? ??????? ??????? ???? ???</summary><p className="mt-3 text-xs leading-6 text-brown">?????? ??????? ????? ?? ????????? ????? ??????? ?????</p><input aria-label="????????? ???" readOnly value={trackingCode} onFocus={(event) => event.currentTarget.select()} className="mt-3 min-h-11 w-full rounded-lg border border-cocoa/15 p-3 font-mono text-xs" /></details>}<Link href="/products" className="inline-flex min-h-12 items-center justify-center rounded-card bg-cocoa px-6 py-3 text-sm text-ivory">আরও কেনাকাটা করুন</Link></section>;
  }
  if (!items.length) {
    return <section className="flex min-h-96 flex-col items-center justify-center gap-5 rounded-2xl bg-rose/20 p-8 text-center"><ShoppingBag size={36} strokeWidth={1.4} className="text-olive" aria-hidden="true" /><h1 className="font-heading text-2xl">আপনার ব্যাগ খালি</h1><p className="text-sm text-brown">চেকআউট করতে প্রথমে পণ্য যোগ করুন।</p><Link href="/products" className="rounded-card bg-cocoa px-6 py-3 text-sm text-ivory">পণ্য দেখুন</Link></section>;
  }
  const currency = quote?.currency || charges?.currency || "BDT";
  const subtotal = items.reduce((sum, item) => sum + Math.round(item.price * 100) * item.quantity, 0) / 100;
  const delivery = charges ? Number(charges[zone]) : null;

  return <>
    <header className="mb-8"><Link href="/cart" className="mb-5 inline-flex min-h-11 items-center gap-2 text-sm text-brown hover:text-olive"><ChevronLeft size={16} aria-hidden="true" />ব্যাগে ফিরে যান</Link><h1 className="font-heading text-3xl sm:text-4xl">চেকআউট</h1><p className="mt-3 text-sm leading-7 text-brown">আপনার তথ্য ও ডেলিভারি এলাকা দিয়ে অর্ডার সম্পন্ন করুন।</p></header>
    <form onSubmit={(event) => void submit(event)} className="grid items-start gap-6 lg:grid-cols-[1fr_0.85fr]">
      <div className="flex flex-col gap-6">
        <fieldset disabled={busy} className="rounded-2xl border border-cocoa/10 p-5 sm:p-7"><legend className="px-2 font-heading text-xl">আপনার তথ্য</legend><div className="grid gap-5 sm:grid-cols-2">{[
          { name: "name" as const, label: "নাম", required: true, autoComplete: "name", maxLength: 150 },
          { name: "phone" as const, label: "ফোন নম্বর", required: true, type: "tel", autoComplete: "tel", placeholder: "01XXXXXXXXX", maxLength: 20 },
          { name: "email" as const, label: "ইমেইল (ঐচ্ছিক)", type: "email", autoComplete: "email", maxLength: 255 },
        ].map(({ name, label, ...props }) => <div key={name} className="flex flex-col gap-2"><label htmlFor={`checkout-${name}`} className="text-sm font-medium">{label}{props.required && <span aria-hidden="true" className="text-[#9b3528]"> *</span>}</label><input id={`checkout-${name}`} className={input} value={details[name]} onChange={(event) => setDetails((current) => ({ ...current, [name]: event.target.value }))} {...props} /></div>)}</div><div className="mt-5 flex flex-col gap-2"><label htmlFor="checkout-address" className="text-sm font-medium">ডেলিভারি ঠিকানা (ঐচ্ছিক)</label><textarea id="checkout-address" rows={3} maxLength={255} autoComplete="street-address" value={details.address} onChange={(event) => setDetails((current) => ({ ...current, address: event.target.value }))} className={input} placeholder="বাড়ি, রোড, এলাকা" /></div></fieldset>
        <fieldset disabled={busy} className="rounded-2xl border border-cocoa/10 p-5 sm:p-7"><legend className="px-2 font-heading text-xl">ডেলিভারি এলাকা</legend><div className="grid gap-3 sm:grid-cols-2">{[
          { value: "inside_dhaka" as const, label: "ঢাকার ভিতরে" }, { value: "outside_dhaka" as const, label: "ঢাকার বাইরে" },
        ].map((option) => <label key={option.value} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-4 ${zone === option.value ? "border-olive bg-olive/5" : "border-cocoa/15"}`}><input type="radio" name="delivery_zone" checked={zone === option.value} onChange={() => setZone(option.value)} className="size-4 accent-olive" /><span className="flex flex-col gap-2 text-sm"><span className="font-medium">{option.label}</span><span className="text-xs text-brown">{charges ? money(charges[option.value], charges.currency) : "চার্জ লোড হচ্ছে..."}</span></span></label>)}</div>{ratesError && <div role="alert" className="mt-4 text-sm text-[#9b3528]">{ratesError}<Button variant="ghost" onClick={() => setRateRevision((value) => value + 1)}>আবার চেষ্টা করুন</Button></div>}</fieldset>
      </div>
      <aside className="rounded-2xl border border-cocoa/10 bg-rose/10 p-5 sm:p-7 lg:sticky lg:top-5"><h2 className="flex items-center gap-2 font-heading text-xl"><ShoppingBag size={20} aria-hidden="true" />আপনার অর্ডার</h2><ul className="mt-5 divide-y divide-cocoa/10">{items.map((item) => <li key={item.id} className="flex items-start gap-3 py-4">{item.image && <img src={item.image} alt="" width={64} height={64} className="size-16 shrink-0 rounded-lg bg-ivory object-contain" />}<div className="min-w-0 flex-1"><Link href={item.href} className="text-sm font-medium hover:text-olive">{item.name}</Link>{item.attributes && <p className="mt-1 text-xs text-brown">{item.attributes}</p>}<div className="mt-2 flex items-center gap-3"><label className="flex items-center gap-2 text-xs text-brown">পরিমাণ<input type="number" min={1} max={item.maxQuantity || 99} value={item.quantity} disabled={busy} onChange={(event) => updateQuantity(item.id, Number(event.target.value))} className="min-h-10 w-16 rounded-lg border border-cocoa/15 bg-ivory px-2 text-sm" /></label><button type="button" disabled={busy} aria-label={`${item.name} সরান`} onClick={() => removeItem(item.id)} className="flex size-10 items-center justify-center rounded-lg text-brown hover:bg-rose/30 focus-visible:outline-2 focus-visible:outline-olive"><Trash2 size={15} aria-hidden="true" /></button></div><p className="mt-2 text-sm font-medium">{money(quote?.items.find((line) => line.variant_id === item.variantId)?.line_total || item.price * item.quantity, item.currency)}</p></div></li>)}</ul>
        <dl className="mt-4 flex flex-col gap-3 border-t border-cocoa/15 pt-5 text-sm"><div className="flex justify-between gap-4"><dt className="text-brown">পণ্যের মূল্য</dt><dd>{money(quote?.subtotal || subtotal, currency)}</dd></div><div className="flex justify-between gap-4"><dt className="flex items-center gap-2 text-brown"><Truck size={15} aria-hidden="true" />ডেলিভারি চার্জ</dt><dd>{quote ? money(quote.shipping_fee, currency) : delivery === null ? "—" : money(delivery, currency)}</dd></div><div className="flex justify-between gap-4 border-t border-cocoa/10 pt-4 text-lg font-semibold"><dt>সর্বমোট</dt><dd className="text-olive" aria-live="polite">{quote ? money(quote.total_amount, currency) : delivery === null ? "—" : money(subtotal + delivery, currency)}</dd></div></dl>
        {error && <div role="alert" className="mt-5 rounded-xl bg-[#9b3528]/5 p-3 text-sm leading-6 text-[#9b3528]">{error}{quote && <Button variant="ghost" size="sm" disabled={busy} onClick={() => setQuote(null)}>অর্ডার আবার যাচাই করুন</Button>}</div>}
        {quote && <p role="status" className="mt-5 rounded-xl bg-olive/8 p-3 text-sm leading-6 text-olive">বর্তমান মূল্য ও ডেলিভারি চার্জ যাচাই করা হয়েছে। মোট মূল্য দেখে অর্ডার নিশ্চিত করুন।</p>}
        <Button type="submit" fullWidth loading={busy} disabled={!charges || busy} className="mt-5">{quote ? "অর্ডার নিশ্চিত করুন" : "অর্ডার যাচাই করুন"}</Button><p className="mt-4 flex items-center justify-center gap-2 text-xs text-brown"><LockKeyhole size={14} aria-hidden="true" />ক্যাশ অন ডেলিভারি</p>
      </aside>
    </form>
  </>;
}
