import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { ArrowRight, BookOpen } from "lucide-react";

export const metadata: Metadata = {
  title: "ঘরগোছের খাতা | ব্লগ",
  description: "কম জিনিসে নিজের মতো সুন্দর জীবন—প্রতিদিনের পছন্দ আর ঘর সাজানো নিয়ে ঘরগোছের প্রথম লেখা।",
};

const sections = [
  { title: "প্রথমে ভাবুন, কী কাজে লাগবে", body: "নতুন কিছু পছন্দ হলে নিজেকে জিজ্ঞেস করুন—এটি কোথায় ব্যবহার করব? ঘরের জন্য হলে কোথায় রাখবেন, পরার জিনিস হলে কোন পোশাকের সঙ্গে মানাবে, তা একটু ভেবে নিন। আপনার প্রতিদিনের অভ্যাসের সঙ্গে মিলে যায় এমন জিনিসই বারবার ব্যবহার করতে ভালো লাগবে।" },
  { title: "রঙে থাকুক একটু মিল", body: "সবকিছু একই রঙের হতে হবে না। দু-তিনটি প্রিয় রংকে ভিত্তি করে পছন্দ করলে ঘর কিংবা সাজে একটি ছন্দ তৈরি হয়। মাটির রং, কোমল গোলাপি বা অলিভের পাশে একটি আলাদা রঙের ছোট অনুষঙ্গও সুন্দরভাবে নজর কাড়তে পারে। নিজের ভালো লাগাকেই এখানে গুরুত্ব দিন।" },
  { title: "ছোট একটি পরিবর্তন দিয়েই শুরু করুন", body: "একবারে সব বদলে ফেলার দরকার নেই। প্রতিদিন ব্যবহার করা কোণটি একটু গুছিয়ে নিন, কিংবা পরিচিত পোশাকের সঙ্গে যোগ করুন পছন্দের একটি গয়না। ছোট পরিবর্তনটি আপনার কেমন লাগছে, সেটি দেখে পরের সিদ্ধান্ত নিন।" },
  { title: "পছন্দের জিনিসের যত্ন নিন", body: "নতুন কিছু আনার আগে সেটির যত্নের নির্দেশনা পড়ে নিন। ব্যবহার শেষে নির্দিষ্ট জায়গায় রাখা, উপকরণ অনুযায়ী পরিষ্কার করা আর যত্নের জন্য একটু সময় রাখা—এই সাধারণ অভ্যাসগুলোই আপনার পছন্দের জিনিসকে ভালো রাখতে সাহায্য করে।" },
];

export default function BlogPage() {
  return <main id="main-content" tabIndex={-1} className="page-container pb-4">
    <nav aria-label="ব্রেডক্রাম্ব" className="flex items-center gap-3 py-6 text-sm text-brown"><Link href="/" className="underline-offset-4 hover:underline">হোম</Link><span aria-hidden="true">/</span><span aria-current="page">ব্লগ</span></nav>
    <header className="flex flex-col items-center gap-4 pb-10 pt-4 text-center sm:pb-14"><span className="flex size-12 items-center justify-center rounded-full bg-rose/40 text-olive"><BookOpen size={23} strokeWidth={1.5} aria-hidden="true" /></span><p className="text-sm font-semibold text-olive">ঘর, সাজ ও প্রতিদিনের গল্প</p><h1 className="font-heading text-4xl text-cocoa sm:text-5xl">ঘরগোছের খাতা</h1><p className="max-w-lg text-base leading-8 text-brown">ছোট ছোট ভাবনা, সহজ কিছু অভ্যাস আর নিজের মতো সুন্দর থাকার অনুপ্রেরণা।</p></header>
    <article aria-labelledby="blog-title" className="overflow-hidden rounded-3xl border border-cocoa/10">
      <header className="grid bg-rose/25 md:grid-cols-[1.15fr_0.85fr]">
        <div className="flex flex-col justify-center gap-5 p-7 sm:p-12"><div className="flex flex-wrap items-center gap-3 text-sm text-olive"><span className="rounded-full border border-olive/20 px-3 py-1">জীবনযাপন</span><span>প্রথম লেখা</span></div><h2 id="blog-title" className="font-heading text-3xl leading-snug text-cocoa sm:text-4xl">অল্পতেই সুন্দর:<br />নিজের মতো বেছে নেওয়ার গল্প</h2><p className="max-w-lg text-base leading-8 text-brown">সুন্দর করে থাকতে অনেক কিছু লাগে না। লাগে নিজের প্রয়োজন বোঝা আর ছোট ছোট পছন্দের প্রতি একটু মনোযোগ।</p><p className="border-t border-cocoa/10 pt-4 text-sm text-brown">লেখা: ঘরগোছ</p></div>
        <figure className="relative min-h-72 bg-ivory sm:min-h-96"><Image src="/images/sample-pendant.jpg" alt="ছোট্ট একটি পেনডেন্ট, প্রতিদিনের সাজে ব্যক্তিগত পছন্দের ছোঁয়া" fill sizes="(min-width: 768px) 45vw, 100vw" className="object-contain p-10 sm:p-14" /></figure>
      </header>
      <div className="mx-auto flex max-w-3xl flex-col gap-9 px-6 py-10 sm:px-10 sm:py-14">
        <p className="text-lg leading-9 text-cocoa">আমাদের চারপাশে এত পছন্দের ভিড়, কখনো কখনো কী নেব আর কী নেব না সেটাই কঠিন হয়ে পড়ে। অথচ নিজের জন্য সুন্দর একটি পরিসর তৈরি করার শুরুটা হতে পারে খুব সহজ—যা আছে, তা দেখা; যা দরকার, তা বোঝা; আর যা ভালো লাগে, তা যত্ন করে বেছে নেওয়া।</p>
        {sections.map((section, index) => <section key={section.title} aria-labelledby={`blog-section-${index}`}><h3 id={`blog-section-${index}`} className="mb-3 text-2xl text-cocoa">{section.title}</h3><p className="text-base leading-8 text-brown">{section.body}</p></section>)}
        <blockquote className="border-l-2 border-olive bg-rose/20 px-6 py-5 text-xl leading-9 text-cocoa">সুন্দর থাকার কোনো একটাই নিয়ম নেই। আপনার জীবন, আপনার স্বাচ্ছন্দ্য আর আপনার পছন্দই হোক শুরু করার জায়গা।</blockquote>
        <p className="text-base leading-8 text-brown">তাই পরেরবার কিছু বেছে নেওয়ার সময় একটু থামুন। ভাবুন, জিনিসটি আপনার জীবনে কী যোগ করবে। অল্প কয়েকটি ভেবেচিন্তে নেওয়া পছন্দই প্রতিদিনকে আরও নিজের মতো করে তুলতে পারে।</p>
        <div className="flex flex-col items-start gap-4 border-t border-cocoa/10 pt-7"><p className="text-base text-brown">নিজের পছন্দের কিছু খুঁজছেন?</p><Link href="/products" className="inline-flex min-h-12 items-center gap-3 rounded-card bg-olive px-6 py-3 text-base text-ivory hover:bg-olive-hover focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive">সংগ্রহ ঘুরে দেখুন<ArrowRight size={18} aria-hidden="true" /></Link></div>
      </div>
    </article>
  </main>;
}
