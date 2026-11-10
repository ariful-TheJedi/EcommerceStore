import pageData from "@/data/cart.json";

import Link from "next/link";
import CartContents from "@/components/sections/CartContents";

const content = pageData.page;

export const metadata = { title: content.title };

export default function CartPage() {
  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container pb-16">
    <nav aria-label={content.arialabel} className="flex items-center gap-3 py-6 text-xs text-brown"><Link href="/">{content.LinkText}</Link><span aria-hidden="true">{content.spanText}</span><span aria-current="page">{content.spanText2}</span></nav>
    <CartContents />
  </main>;
}
