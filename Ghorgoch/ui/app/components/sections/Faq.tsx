"use client";

import pageData from "@/data/front-page.json";

import { useState } from "react";
import FaqAccordionItem, { type FaqItem } from "@/components/molecules/FaqAccordionItem";

const content = pageData.faq;

type FaqProps = {
  items: readonly FaqItem[];
  title?: string;
  description?: string;
  sectionId?: string;
};

export default function Faq({
  items,
  title = content.label,
  description = content.label2,
  sectionId = "faq",
}: FaqProps) {
  const [openItem, setOpenItem] = useState<string | null>(null);
  if (!items.length) { return null; }

  return <section id={sectionId} className="faq-section" aria-labelledby={`${sectionId}-title`}>
    <div className="faq-intro">
      <p className="store-eyebrow">{content.pText}</p>
      <h2 id={`${sectionId}-title`}>{title}</h2>
      <p>{description}</p>
    </div>
    <div className="faq-list">
      {items.map((item) => <FaqAccordionItem key={item.id} item={item} sectionId={sectionId} isOpen={openItem === item.id} onToggle={() => setOpenItem(openItem === item.id ? null : item.id)} />)}
    </div>
  </section>;
}
