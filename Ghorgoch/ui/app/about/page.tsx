import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { ArrowRight, Heart, Leaf, Sparkles } from "lucide-react";

export const metadata: Metadata = {
  title: "আমাদের কথা | ঘরগোছ",
  description: "ঘরগোছের ভাবনা—আপনার ঘর, সাজ ও প্রতিদিনের জীবনের জন্য পছন্দের জিনিস খুঁজে নেওয়ার একটি ঠিকানা।",
};

const values = [
  { icon: Heart, title: "আপনার পছন্দ, আপনার গল্প", description: "আপনার রুচিই আপনার পরিচয়। নিজের মতো করে বেছে নেওয়ার আনন্দটুকু থাকুক প্রতিটি কেনাকাটায়।" },
  { icon: Leaf, title: "প্রয়োজনের সঙ্গে সৌন্দর্য", description: "শুধু দেখতে সুন্দর নয়, প্রতিদিনের জীবনেও কাজে আসে—এমন জিনিসেই আমাদের আগ্রহ।" },
  { icon: Sparkles, title: "ছোট জিনিসে নতুন আনন্দ", description: "একটি অনুষঙ্গ, একটি প্রিয় রং, একটু নতুনত্ব। সাধারণ দিনের মাঝেও থাকুক সুন্দর কিছু।" },
];

export default function AboutPage() {
  return <main id="main-content" tabIndex={-1} className="page-container pb-4">
    <nav aria-label="ব্রেডক্রাম্ব" className="flex items-center gap-3 py-6 text-sm text-brown"><Link href="/" className="underline-offset-4 hover:underline">হোম</Link><span aria-hidden="true">/</span><span aria-current="page">আমাদের কথা</span></nav>
    <section aria-labelledby="about-title" className="grid overflow-hidden rounded-3xl border border-cocoa/10 bg-rose/25 lg:grid-cols-2">
      <div className="flex flex-col items-start justify-center gap-6 p-7 sm:p-12 lg:p-16">
        <p className="text-sm font-semibold text-olive">আমাদের কথা</p>
        <h1 id="about-title" className="font-heading text-4xl leading-snug text-cocoa sm:text-5xl">নিজের মতো জীবন,<br />নিজের মতো ঘরগোছ।</h1>
        <p className="max-w-lg text-base leading-8 text-brown">সুন্দর মানেই অনেক কিছু নয়। কখনো তা পছন্দের একটি গয়না, কখনো ঘরের ছোট্ট কোনো পরিবর্তন। সেই ছোট ছোট ভালো লাগার জায়গা থেকেই ঘরগোছের ভাবনা।</p>
        <Link href="/products" className="inline-flex min-h-12 items-center gap-3 rounded-card bg-olive px-6 py-3 text-base text-ivory hover:bg-olive-hover focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive">আমাদের সংগ্রহ দেখুন<ArrowRight size={18} aria-hidden="true" /></Link>
      </div>
      <figure className="relative min-h-80 bg-ivory sm:min-h-96"><Image src="/images/sample-necklace.jpg" alt="নিজের সাজে ছোট্ট একটি অনুষঙ্গ—বাটারফ্লাই নেকলেস" fill sizes="(min-width: 1024px) 50vw, 100vw" className="object-contain p-10 sm:p-16" /></figure>
    </section>
    <section aria-labelledby="about-story" className="grid gap-6 border-b border-cocoa/10 py-12 sm:py-16 md:grid-cols-[1fr_1.5fr] md:gap-16">
      <div><p className="mb-3 text-sm font-semibold text-olive">আমাদের ভাবনা</p><h2 id="about-story" className="font-heading text-3xl leading-snug text-cocoa">প্রতিদিনের মাঝে<br />একটু ভালো লাগা।</h2></div>
      <div className="flex max-w-2xl flex-col gap-5 text-base leading-8 text-brown"><p>ঘরগোছ আপনার ঘর ও জীবনযাপনের পছন্দের জিনিস খুঁজে নেওয়ার একটি ঠিকানা। পোশাক, গয়না, অনুষঙ্গ কিংবা ঘরের প্রয়োজন—সবকিছুর মাঝে আমরা খুঁজি ব্যবহার আর সৌন্দর্যের সহজ মিল।</p><p>আমাদের ইচ্ছা, এখানে এসে আপনি নিজের জন্য মানানসই কিছু খুঁজে পান। তাড়াহুড়ো করে নয়, নিজের প্রয়োজন বুঝে, নিজের রুচিকে গুরুত্ব দিয়ে। কারণ সবচেয়ে সুন্দর পছন্দটি সেই, যা আপনার জীবনের সঙ্গে মিশে যায়।</p></div>
    </section>
    <section aria-label="আমাদের মূল্যবোধ" className="grid gap-8 py-12 sm:py-16 md:grid-cols-3 md:gap-10">{values.map(({ icon: Icon, title, description }) => <div key={title} className="flex flex-col items-start gap-4"><span className="flex size-12 items-center justify-center rounded-2xl bg-rose/40 text-olive"><Icon size={23} strokeWidth={1.5} aria-hidden="true" /></span><h2 className="text-xl text-cocoa">{title}</h2><p className="text-base leading-8 text-brown">{description}</p></div>)}</section>
    <aside className="flex flex-col gap-5 rounded-2xl border border-cocoa/10 bg-ivory p-7 sm:flex-row sm:items-center sm:justify-between sm:p-9"><div><h2 className="text-2xl text-cocoa">কিছু জানতে চান?</h2><p className="mt-2 text-base text-brown">পণ্য বা আপনার পছন্দ নিয়ে আমাদের সঙ্গে কথা বলুন।</p></div><Link href="#contact" className="inline-flex min-h-11 items-center gap-2 self-start text-base font-semibold text-olive underline underline-offset-4 sm:self-auto">যোগাযোগ করুন<ArrowRight size={18} aria-hidden="true" /></Link></aside>
  </main>;
}
