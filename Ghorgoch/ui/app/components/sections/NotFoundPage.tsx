import pageData from "@/data/messages.json";

import Link from "next/link";
import { ArrowRight, Home, MapPinOff, ShoppingBag } from "lucide-react";

const content = pageData.notFound;

type NotFoundPageProps = {
  title?: string;
  message?: string;
};

export default function NotFoundPage({ title = content.label, message = content.label2 }: NotFoundPageProps) {
  const focus = "focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive";

  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container flex min-h-[70vh] items-center justify-center py-12 sm:py-20">
    <section aria-labelledby="not-found-title" className="w-full max-w-3xl rounded-card border border-cocoa/10 bg-rose/20 px-6 py-12 text-center sm:px-12 sm:py-16">
      <div aria-hidden="true" className="relative mx-auto mb-7 w-fit">
        <span className="font-heading text-[7rem] leading-none text-olive/20 sm:text-[10rem]">{content.spanText}</span>
        <span className="absolute right-0 -bottom-2 flex size-14 items-center justify-center rounded-full border border-cocoa/10 bg-ivory text-olive sm:size-16"><MapPinOff size={28} strokeWidth={1.4} /></span>
      </div>
      <p className="mb-3 text-xs text-olive">{content.pText}</p>
      <h1 id="not-found-title" className="font-heading text-2xl text-cocoa sm:text-3xl">{title}</h1>
      <p className="mx-auto mt-4 max-w-md text-sm leading-7 text-brown">{message}</p>
      <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
        <Link href="/" className={`inline-flex min-h-12 items-center justify-center gap-2 rounded-card bg-cocoa px-6 py-3 text-sm text-ivory transition-colors hover:bg-olive motion-reduce:transition-none ${focus}`}><Home size={17} strokeWidth={1.6} aria-hidden="true" />{content.LinkText}</Link>
        <Link href="/products" className={`inline-flex min-h-12 items-center justify-center gap-2 rounded-card border border-cocoa/20 bg-ivory px-6 py-3 text-sm text-cocoa transition-colors hover:border-olive hover:text-olive motion-reduce:transition-none ${focus}`}><ShoppingBag size={17} strokeWidth={1.6} aria-hidden="true" />{content.LinkText2}</Link>
      </div>
      <Link href="/categories" className={`mt-6 inline-flex min-h-touch items-center gap-2 text-xs text-olive ${focus}`}>{content.LinkText3}<ArrowRight size={15} aria-hidden="true" /></Link>
    </section>
  </main>;
}
