"use client";

import { ChevronDown } from "lucide-react";

export type FaqItem = {
  id: string;
  question: string;
  answer: string;
};

type FaqAccordionItemProps = {
  item: FaqItem;
  sectionId: string;
  isOpen: boolean;
  onToggle: () => void;
};

export default function FaqAccordionItem({ item, sectionId, isOpen, onToggle }: FaqAccordionItemProps) {
  const questionId = `${sectionId}-${item.id}-question`;
  const answerId = `${sectionId}-${item.id}-answer`;

  return <div className="faq-item" data-open={isOpen}>
    <h3><button className="faq-question" type="button" id={questionId} aria-expanded={isOpen} aria-controls={answerId} onClick={onToggle}>
      <span>{item.question}</span><span className="faq-toggle" aria-hidden="true"><ChevronDown size={18} strokeWidth={1.5} /></span>
    </button></h3>
    <div className="faq-answer" id={answerId} role="region" aria-labelledby={questionId} aria-hidden={!isOpen} inert={!isOpen}>
      <div className="faq-answer-inner"><div className="faq-answer-content"><p>{item.answer}</p></div></div>
    </div>
  </div>;
}
