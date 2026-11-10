"use client";

import Link from "next/link";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { ArrowLeft, Check, Clock3, MapPin, Package, Search, ShieldCheck, Truck } from "lucide-react";
import Button from "@/components/atoms/Button";
import { ApiError, money } from "@/components/catalog";
import { rememberOrder, savedOrders, trackOrder, type SavedOrder, type TrackedOrder } from "@/services/tracking";

const steps = [
  { status: "pending", label: "অর্ডার গ্রহণ", hint: "আপনার অর্ডার আমাদের কাছে পৌঁছেছে।" },
  { status: "confirmed", label: "নিশ্চিত করা হয়েছে", hint: "অর্ডারের তথ্য যাচাই করা হয়েছে।" },
  { status: "processing", label: "প্রস্তুত হচ্ছে", hint: "আপনার পণ্য প্যাক করা হচ্ছে।" },
  { status: "shipped", label: "ডেলিভারির পথে", hint: "অর্ডারটি ডেলিভারির জন্য পাঠানো হয়েছে।" },
  { status: "delivered", label: "ডেলিভারি সম্পন্ন", hint: "অর্ডারটি আপনার কাছে পৌঁছেছে।" },
];
const statuses: Record<string, string> = { ...Object.fromEntries(steps.map((step) => [step.status, step.label])), cancelled: "অর্ডার বাতিল", returned: "পণ্য ফেরত এসেছে" };
const payments: Record<string, string> = { pending: "বকেয়া", paid: "পরিশোধিত", refunded: "ফেরত দেওয়া হয়েছে", partially_refunded: "আংশিক ফেরত", failed: "ব্যর্থ" };
const deliveries: Record<string, string> = { pending: "অপেক্ষমাণ", in_transit: "পথে রয়েছে", delivered: "পৌঁছে গেছে", failed: "ডেলিভারি চেষ্টা ব্যর্থ", returned: "ফেরত এসেছে" };
const inputClass = "min-h-12 w-full rounded-xl border border-cocoa/15 bg-ivory px-4 py-3 text-sm focus-visible:outline-2 focus-visible:outline-olive disabled:opacity-60";
const date = (value: string) => new Intl.DateTimeFormat("bn-BD", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Dhaka" }).format(new Date(value));

export default function OrderTracking({ initialNumber }: { initialNumber: string }) {
  const [number, setNumber] = useState(initialNumber);
  const [token, setToken] = useState("");
  const [recent, setRecent] = useState<SavedOrder[]>([]);
  const [order, setOrder] = useState<TrackedOrder | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const pending = useRef<AbortController | null>(null);
  const resultHeading = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    const saved = savedOrders(); setRecent(saved);
    const matching = saved.find((entry) => entry.number === initialNumber);
    if (matching?.token) { setToken(matching.token); }
    return () => pending.current?.abort();
  }, [initialNumber]);
  useEffect(() => { if (order) { resultHeading.current?.focus(); } }, [order]);

  async function lookup(orderNumber: string, accessCode: string) {
    const requestedNumber = orderNumber.trim();
    const requestedToken = accessCode.trim();
    const known = recent.find((entry) => entry.number === requestedNumber);
    setError(""); setOrder(null);
    if (!requestedNumber) { setError("আপনার অর্ডার নম্বর লিখুন।"); return; }
    if (requestedToken && !/^[a-f0-9]{64}$/i.test(requestedToken)) { setError("অর্ডার নিশ্চিতকরণ থেকে সম্পূর্ণ ট্র্যাকিং কোডটি কপি করুন।"); return; }
    if (!requestedToken && (!known || known.token)) { setError("অর্ডার নিশ্চিতকরণের ট্র্যাকিং কোডটি দিন। এই ব্রাউজারে করা অর্ডার হলে নিচের সংরক্ষিত অর্ডারটি বেছে নিন।"); return; }
    pending.current?.abort();
    const controller = new AbortController(); pending.current = controller; setBusy(true);
    try {
      const found = await trackOrder(requestedNumber, requestedToken, known?.id, AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]));
      if (controller.signal.aborted) { return; }
      setOrder(found); rememberOrder({ id: found.id, number: found.order_number, ...(requestedToken ? { token: requestedToken } : {}) }); setRecent(savedOrders());
    } catch (failure) {
      if (controller.signal.aborted) { return; }
      setError(failure instanceof ApiError && [401, 403, 404].includes(failure.status) ? "অর্ডারটি পাওয়া যায়নি বা প্রবেশাধিকার নেই। অর্ডার নম্বর ও ট্র্যাকিং কোড যাচাই করুন। অ্যাকাউন্টের অর্ডার হলে একই অ্যাকাউন্টে লগ ইন থাকতে হবে।" : "এই মুহূর্তে অর্ডারের তথ্য আনা যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।");
    } finally { if (pending.current === controller) { setBusy(false); } }
  }
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); void lookup(number, token); }
  const current = steps.findIndex((step) => step.status === order?.status);
  const stopped = order?.status === "cancelled" || order?.status === "returned";

  return <div className="mx-auto max-w-5xl">
    <Link href="/" className="mb-7 inline-flex min-h-11 items-center gap-2 text-sm text-brown hover:text-olive"><ArrowLeft size={16} aria-hidden="true" />প্রথম পাতায় ফিরুন</Link>
    <header className="mb-9 flex flex-col items-center gap-4 text-center"><span className="flex size-16 items-center justify-center rounded-2xl bg-olive/10 text-olive"><Truck size={30} strokeWidth={1.5} aria-hidden="true" /></span><p className="text-xs font-medium tracking-wide text-olive">আপনার অর্ডারের খবর</p><h1 className="font-heading text-3xl sm:text-4xl">অর্ডারটি এখন কোথায়?</h1><p className="max-w-lg text-sm leading-7 text-brown">অর্ডার নম্বর ও ট্র্যাকিং কোড দিয়ে প্রস্তুতি থেকে ডেলিভারি পর্যন্ত সর্বশেষ অবস্থা দেখুন।</p></header>
    <form onSubmit={submit} className="rounded-2xl border border-cocoa/10 bg-white/50 p-5 sm:p-7">
      <div className="grid gap-5 sm:grid-cols-2"><label className="flex flex-col gap-2 text-sm font-medium" htmlFor="tracking-number">অর্ডার নম্বর<input id="tracking-number" value={number} onChange={(event) => { setNumber(event.target.value); setOrder(null); setError(""); }} required maxLength={100} autoComplete="off" spellCheck={false} placeholder="অর্ডার নিশ্চিতকরণে দেওয়া নম্বর" disabled={busy} className={inputClass} /></label><label className="flex flex-col gap-2 text-sm font-medium" htmlFor="tracking-code">ট্র্যাকিং কোড<input id="tracking-code" type="password" value={token} onChange={(event) => { setToken(event.target.value); setOrder(null); setError(""); }} maxLength={64} autoComplete="off" spellCheck={false} aria-describedby="tracking-help" disabled={busy} className={inputClass} placeholder="আপনার ব্যক্তিগত ট্র্যাকিং কোড" /></label></div>
      <div className="mt-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><p id="tracking-help" className="flex max-w-lg items-start gap-2 text-xs leading-6 text-brown"><ShieldCheck size={17} className="mt-1 shrink-0 text-olive" aria-hidden="true" />কোডটি অর্ডার নিশ্চিতকরণে পাবেন। একই ব্রাউজারে করা অর্ডারের তথ্য নিচে সংরক্ষিত থাকে।</p><Button type="submit" loading={busy} loadingLabel="খোঁজা হচ্ছে..." startIcon={<Search size={17} />} className="shrink-0">অর্ডার ট্র্যাক করুন</Button></div>
      {error && <p role="alert" className="mt-5 rounded-xl border border-[#9b3528]/15 bg-[#9b3528]/5 p-4 text-sm leading-7 text-[#9b3528]">{error}</p>}
    </form>
    {recent.length > 0 && <section aria-label="এই ব্রাউজারের অর্ডার" className="mt-5"><p className="mb-3 text-xs text-brown">এই ব্রাউজারের সাম্প্রতিক অর্ডার</p><div className="flex flex-wrap gap-2">{recent.map((saved) => <button key={saved.number} type="button" disabled={busy} onClick={() => { setNumber(saved.number); setToken(saved.token || ""); void lookup(saved.number, saved.token || ""); }} className="min-h-11 rounded-full border border-cocoa/15 px-4 py-2 text-xs text-cocoa hover:border-olive hover:text-olive focus-visible:outline-2 focus-visible:outline-olive disabled:opacity-50">{saved.number}</button>)}</div></section>}
    {order ? <section aria-labelledby="tracking-result-title" aria-busy={busy} className="mt-9 flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-olive/15 bg-olive/5 p-6"><div><h2 ref={resultHeading} tabIndex={-1} id="tracking-result-title" className="break-all font-heading text-xl sm:text-2xl">{order.order_number}</h2><p className="mt-2 flex items-center gap-2 text-xs text-brown"><Clock3 size={14} aria-hidden="true" /><time dateTime={order.created_at}>{date(order.created_at)}</time></p></div><span role="status" className={`rounded-full px-4 py-2 text-sm font-medium ${stopped ? "bg-cocoa/10 text-cocoa" : "bg-olive/10 text-olive"}`}>{statuses[order.status] || order.status}</span></header>
      {stopped && <p className="rounded-xl border border-cocoa/10 p-4 text-sm leading-7 text-brown">{order.status === "cancelled" ? "এই অর্ডারটি বাতিল করা হয়েছে। ডেলিভারির পরবর্তী ধাপ আর প্রযোজ্য নয়।" : "এই অর্ডারের পণ্য ফেরত এসেছে। বিস্তারিত জানতে আমাদের সঙ্গে যোগাযোগ করুন।"}</p>}
      <ol aria-label="অর্ডারের অগ্রগতি" className="grid gap-5 rounded-2xl border border-cocoa/10 bg-white/40 p-6 sm:grid-cols-5 sm:gap-3">{steps.map((step, index) => {
        const reached = current >= index || stopped && order.history.some((event) => event.new_status === step.status);
        const event = order.history.find((entry) => entry.new_status === step.status);
        return <li key={step.status} aria-current={current === index ? "step" : undefined} className="flex items-start gap-3 sm:flex-col"><span className={`flex size-10 shrink-0 items-center justify-center rounded-full border ${reached ? "border-olive bg-olive text-ivory" : "border-cocoa/15 text-brown/60"}`}>{reached ? <Check size={18} aria-hidden="true" /> : <span>{(index + 1).toLocaleString("bn-BD")}</span>}</span><div><h3 className={`text-sm font-medium ${reached ? "text-olive" : "text-brown"}`}>{step.label}</h3><p className="mt-1 text-xs leading-6 text-brown">{step.hint}</p>{event && <time dateTime={event.created_at} className="mt-2 block text-[11px] leading-5 text-brown">{date(event.created_at)}</time>}</div></li>;
      })}</ol>
      <div className="grid gap-6 md:grid-cols-2"><section className="rounded-2xl border border-cocoa/10 p-6"><h3 className="flex items-center gap-2 font-heading text-lg"><Truck size={20} className="text-olive" aria-hidden="true" />ডেলিভারির তথ্য</h3><dl className="mt-5 grid gap-4 text-sm">{[["ডেলিভারি", deliveries[order.delivery_status] || order.delivery_status], ["কুরিয়ার", order.courier_name], ["কুরিয়ার রেফারেন্স", order.tracking_reference]].filter(([, value]) => value).map(([label, value]) => <div key={label} className="flex flex-wrap justify-between gap-3"><dt className="text-brown">{label}</dt><dd className="break-all font-medium">{value}</dd></div>)}</dl>{order.delivery && <p className="mt-5 flex items-start gap-2 border-t border-cocoa/10 pt-4 text-sm leading-7 text-brown"><MapPin size={17} className="mt-1 shrink-0 text-olive" aria-hidden="true" />{[order.delivery.address_line1, order.delivery.area, order.delivery.city, order.delivery.district].filter(Boolean).join(", ") || "ঠিকানা নিশ্চিতকরণের সময় জানানো হবে।"}</p>}</section>
      <section className="rounded-2xl border border-cocoa/10 p-6"><h3 className="flex items-center gap-2 font-heading text-lg"><Package size={20} className="text-olive" aria-hidden="true" />অর্ডারের পণ্য</h3><ul className="mt-4 divide-y divide-cocoa/10">{order.items.map((item, index) => <li key={index} className="flex items-start justify-between gap-4 py-3 text-sm"><div className="min-w-0"><p className="break-words font-medium">{item.product_name}</p><p className="mt-1 text-xs text-brown">পরিমাণ: {item.quantity.toLocaleString("bn-BD")}</p></div><p className="shrink-0">{money(item.line_total, order.currency)}</p></li>)}</ul><div className="mt-3 flex justify-between gap-4 border-t border-cocoa/10 pt-4 font-medium"><span>সর্বমোট</span><span>{money(order.total_amount, order.currency)}</span></div><p className="mt-3 text-xs text-brown">পেমেন্ট: {payments[order.payment_status] || order.payment_status}</p></section></div>
      <p className="text-center text-xs leading-7 text-brown">অবস্থা পরিবর্তন হলে সর্বশেষ তথ্য দেখতে আবার অর্ডার ট্র্যাক করুন।</p>
    </section> : !busy && <div className="mt-10 flex flex-col items-center gap-3 py-8 text-center text-brown"><Package size={36} strokeWidth={1.2} className="text-olive/60" aria-hidden="true" /><p className="text-sm">আপনার অর্ডারের সর্বশেষ খবর এখানেই দেখানো হবে।</p></div>}
  </div>;
}
