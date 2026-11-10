"use client";

import { useId, useRef, useState } from "react";
import pageData from "@/data/products.json";
import type { Product, SpecificationValue } from "@/components/catalog";

const content = pageData.information;
const tabs = [content.description, content.additional];

function SpecificationContent({ value }: { value: SpecificationValue }) {
  if (value === null) { return <span>—</span>; }
  if (typeof value === "boolean") { return <span>{value ? content.yes : content.no}</span>; }
  if (Array.isArray(value)) {
    return <ul className="flex flex-col gap-2">{value.map((item, index) => <li key={index} className="border-l-2 border-olive/20 pl-3"><SpecificationContent value={item} /></li>)}</ul>;
  }
  if (typeof value === "object") {
    return <dl className="flex flex-col gap-3">{Object.entries(value).map(([name, item]) => <div key={name} className="grid gap-1"><dt className="font-medium text-cocoa">{name}</dt><dd className="pl-3"><SpecificationContent value={item} /></dd></div>)}</dl>;
  }
  return <span className="whitespace-pre-line">{String(value)}</span>;
}

export default function ProductInformation({ product }: { product: Product }) {
  const [active, setActive] = useState(0);
  const id = useId();
  const buttons = useRef<(HTMLButtonElement | null)[]>([]);
  const variants = product.variants.filter((variant) => variant.is_active);
  const attributes = new Map<string, Set<string>>();
  for (const variant of variants) {
    for (const [name, value] of Object.entries(variant.attributes)) {
      if (!attributes.has(name)) { attributes.set(name, new Set()); }
      attributes.get(name)!.add(value);
    }
  }
  const customRows = Object.entries(product.specifications ?? {}).map(([label, value]) => ({ label, value }));
  const rows = customRows.length ? customRows : [
    { label: content.productCode, value: String(product.id) },
    ...(variants.length ? [{ label: content.sku, value: variants.map((variant) => variant.sku).join(", ") }] : []),
    ...(product.categories.length ? [{ label: content.categories, value: product.categories.map((category) => category.name).join(", ") }] : []),
    ...Array.from(attributes, ([name, values]) => ({ label: name, value: Array.from(values).join(", ") })),
  ];
  const description = product.description?.trim();

  return <section aria-label={content.label} className="mt-12 min-w-0 sm:mt-16">
    <div role="tablist" aria-label={content.label} lang="en" className="grid grid-cols-2 gap-2 border-b border-cocoa/10 sm:flex sm:justify-center sm:gap-10">
      {tabs.map((label, index) => <button key={label} ref={(button) => { buttons.current[index] = button; }} type="button" role="tab" id={`${id}-tab-${index}`} aria-controls={`${id}-panel-${index}`} aria-selected={active === index} tabIndex={active === index ? 0 : -1}
        onClick={() => setActive(index)} onKeyDown={(event) => {
          let next = index;
          if (event.key === "ArrowRight") { next = (index + 1) % tabs.length; }
          else if (event.key === "ArrowLeft") { next = (index + tabs.length - 1) % tabs.length; }
          else if (event.key === "Home") { next = 0; }
          else if (event.key === "End") { next = tabs.length - 1; }
          else { return; }
          event.preventDefault(); setActive(next); buttons.current[next]?.focus();
        }} className={`min-h-14 shrink-0 border-b-[3px] px-1 py-4 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-olive motion-reduce:transition-none sm:text-lg ${active === index ? "border-olive text-olive" : "border-transparent text-brown hover:text-olive"}`}>{label}</button>)}
    </div>
    <div id={`${id}-panel-0`} role="tabpanel" aria-labelledby={`${id}-tab-0`} hidden={active !== 0} tabIndex={0} className="py-7 text-sm leading-8 text-brown outline-offset-4 sm:py-9 sm:text-base">
      {description ? <div className="flex flex-col gap-4">{description.split(/\r?\n\s*\r?\n/).map((block, index) => {
        const lines = block.split(/\r?\n/).filter((line) => line.trim());
        return lines.every((line) => /^\s*[-*•]\s+/.test(line)) ? <ul key={index} className="flex flex-col gap-2">{lines.map((line, item) => <li key={item} className="flex items-start gap-3"><span aria-hidden="true" className="mt-2.5 size-3 shrink-0 rounded-full border-l-4 border-[#c4ad74] bg-olive" /><span className="min-w-0 break-words">{line.replace(/^\s*[-*•]\s+/, "")}</span></li>)}</ul> : <p key={index} className="whitespace-pre-line break-words">{block}</p>;
      })}</div> : <p>{content.noDescription}</p>}
    </div>
    <div id={`${id}-panel-1`} role="tabpanel" aria-labelledby={`${id}-tab-1`} hidden={active !== 1} tabIndex={0} className="py-7 outline-offset-4 sm:py-9">
      <dl className="divide-y divide-cocoa/10 text-sm sm:text-base">{rows.map((row) => <div key={row.label} className="grid gap-2 py-4 first:pt-0 sm:grid-cols-[minmax(0,12rem)_1fr] sm:gap-8"><dt className="min-w-0 break-words font-medium text-cocoa">{row.label}</dt><dd className="min-w-0 break-words text-brown"><SpecificationContent value={row.value} /></dd></div>)}</dl>
    </div>
  </section>;
}
