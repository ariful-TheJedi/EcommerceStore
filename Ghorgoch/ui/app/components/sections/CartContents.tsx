"use client";

import pageData from "@/data/cart.json";

import Link from "next/link";
import { useState } from "react";
import { ArrowLeft, ArrowRight, Minus, Plus, ShoppingBag, Trash2, Truck } from "lucide-react";
import { useCartStore } from "@/components/CartProvider";
import { money } from "@/components/catalog";

const content = pageData.contents;

export default function CartContents() {
  const { items, hydrated, cartCount, updateQuantity, removeItem } = useCartStore();
  const [notice, setNotice] = useState("");
  const subtotal = items.reduce((total, item) => total + Math.round(item.price * 100) * item.quantity, 0) / 100;

  const focus = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive";

  if (!hydrated) { return <p role="status" className="py-16 text-center text-brown">ব্যাগ লোড হচ্ছে...</p>; }
  return <>
    <header className="flex flex-wrap items-end justify-between gap-4 border-b border-cocoa/10 pb-6">
      <div><p className="mb-2 text-xs text-olive">{content.pText}</p><h1 className="font-heading text-3xl sm:text-4xl">{content.h1Text}</h1><p className="mt-2 text-sm text-brown">{content.pText2}</p></div>
      {items.length > 0 && <span className="rounded-full bg-rose/40 px-4 py-2 text-xs text-cocoa">{cartCount.toLocaleString("bn-BD")}{content.spanText}</span>}
    </header>
    <p role="status" className="sr-only">{notice}</p>
    {items.length === 0 ? <section aria-label={content.arialabel} className="my-8 flex min-h-96 flex-col items-center justify-center rounded-card bg-rose/20 px-6 py-12 text-center">
      <span className="mb-6 flex size-20 items-center justify-center rounded-full bg-ivory text-olive"><ShoppingBag size={34} strokeWidth={1.3} aria-hidden="true" /></span>
      <h2 className="text-2xl">{content.h2Text}</h2><p className="mt-3 max-w-sm text-sm leading-7 text-brown">{content.pText3}</p>
      <Link href="/products" className={`mt-7 inline-flex min-h-12 items-center justify-center gap-2 rounded-card bg-cocoa px-6 py-3 text-sm text-ivory ${focus}`}>{content.LinkText}<ArrowRight size={17} aria-hidden="true" /></Link>
    </section> : <div className="mt-8 grid items-start gap-8 lg:grid-cols-[minmax(0,1.6fr)_minmax(18rem,1fr)] lg:gap-12">
      <section aria-label={content.arialabel2} className="min-w-0">
        <ul className="divide-y divide-cocoa/10">
          {items.map((item) => <li key={item.id} className="flex gap-4 py-6 first:pt-0 sm:gap-6">
            <Link href={item.href} className={`flex h-32 w-24 shrink-0 items-center justify-center overflow-hidden rounded-card bg-rose/25 sm:h-36 sm:w-32 ${focus}`}>
              {item.image ? <img src={item.image} alt={item.name} width={144} height={160} className="h-full w-full object-contain p-2 mix-blend-multiply" /> : <ShoppingBag size={28} className="text-brown/40" aria-hidden="true" />}
            </Link>
            <div className="flex min-w-0 flex-1 flex-col">
              <div className="flex items-start justify-between gap-3"><h2 className="text-base leading-7"><Link href={item.href}>{item.name}</Link></h2><button type="button" onClick={() => { removeItem(item.id); setNotice(`${item.name} ব্যাগ থেকে সরানো হয়েছে।`); }} aria-label={`${item.name} ব্যাগ থেকে সরান`} className={`flex size-11 shrink-0 items-center justify-center rounded-card text-brown hover:bg-rose/30 hover:text-cocoa ${focus}`}><Trash2 size={17} strokeWidth={1.5} aria-hidden="true" /></button></div>
              {item.attributes && <p className="mt-1 text-xs leading-6 text-brown">{item.attributes}</p>}
              <p className="mt-1 text-sm text-olive">{money(item.price, item.currency)}</p>
              <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                <div className="inline-flex items-center rounded-card border border-cocoa/20" role="group" aria-label={`${item.name} এর পরিমাণ`}>
                  <button type="button" disabled={item.quantity <= 1} onClick={() => updateQuantity(item.id, item.quantity - 1)} aria-label={`${item.name} এর পরিমাণ কমান`} className={`flex size-11 items-center justify-center rounded-l-card text-cocoa ${focus}`}><Minus size={15} aria-hidden="true" /></button>
                  <span className="min-w-9 text-center text-sm" aria-live="polite">{item.quantity.toLocaleString("bn-BD")}</span>
                  <button type="button" disabled={item.quantity >= (item.maxQuantity ?? 99)} onClick={() => updateQuantity(item.id, item.quantity + 1)} aria-label={`${item.name} এর পরিমাণ বাড়ান`} className={`flex size-11 items-center justify-center rounded-r-card text-cocoa ${focus}`}><Plus size={15} aria-hidden="true" /></button>
                </div>
                <p className="text-base font-semibold text-cocoa">{money(item.price * item.quantity, item.currency)}</p>
              </div>
            </div>
          </li>)}
        </ul>
        <Link href="/products" className={`mt-6 inline-flex min-h-touch items-center gap-2 text-sm text-olive ${focus}`}><ArrowLeft size={16} aria-hidden="true" />{content.LinkText2}</Link>
      </section>
      <aside aria-labelledby="cart-summary-title" className="rounded-card border border-cocoa/10 bg-rose/20 p-6 sm:p-7 lg:sticky lg:top-6">
        <h2 id="cart-summary-title" className="text-xl">{content.h2Text2}</h2>
        <dl className="mt-6 flex flex-col gap-4 text-sm"><div className="flex justify-between gap-3"><dt className="text-brown">{content.dtText}</dt><dd>{money(subtotal, "BDT")}</dd></div><div className="flex justify-between gap-3"><dt className="text-brown">{content.dtText2}</dt><dd>চেকআউটে নির্ধারিত হবে</dd></div><div className="mt-2 flex justify-between gap-3 border-t border-cocoa/15 pt-5 text-lg font-semibold"><dt>{content.dtText3}</dt><dd className="text-olive" aria-live="polite">{money(subtotal, "BDT")}</dd></div></dl>
        <Link href="/checkout" className={`mt-7 flex min-h-12 items-center justify-center gap-2 rounded-card bg-cocoa px-5 py-3 text-sm text-ivory ${focus}`}>{content.LinkText3}<ArrowRight size={17} aria-hidden="true" /></Link>
        <p className="mt-4 flex items-start gap-2 text-xs leading-6 text-brown"><Truck size={17} className="mt-1 shrink-0" aria-hidden="true" />ডেলিভারি এলাকা অনুযায়ী চেকআউটে চার্জ যোগ হবে।</p>
      </aside>
    </div>}
  </>;
}
