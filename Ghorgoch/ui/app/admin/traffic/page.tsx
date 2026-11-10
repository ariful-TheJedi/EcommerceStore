import { Activity, ChartNoAxesCombined, Eye, MousePointer2, Users, type LucideIcon } from "lucide-react";
import type { Metadata } from "next";
import content from "@/data/admin/traffic.json";

export const metadata: Metadata = { title: content.title };

const icons: Record<string, LucideIcon> = { Activity, Eye, MousePointer2, Users };

export default function TrafficPage() {
  return <section aria-labelledby="traffic-page-title" className="flex flex-col gap-6">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 id="traffic-page-title" className="font-heading text-3xl text-cocoa">{content.title}</h1>
        <p className="mt-2 text-sm text-brown">{content.description}</p>
      </div>
      <span className="inline-flex items-center gap-2 rounded-full border border-cocoa/15 bg-rose/20 px-3 py-2 text-xs font-medium text-cocoa">
        <span aria-hidden="true" className="size-2 rounded-full bg-brown" />{content.status}
      </span>
    </div>

    <p role="status" className="rounded-card border border-cocoa/15 bg-rose/20 p-4 text-sm leading-relaxed text-cocoa">{content.notice}</p>

    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {content.metrics.map((metric) => {
        const Icon = icons[metric.icon] ?? Activity;
        return <article key={metric.label} className="rounded-card border border-cocoa/10 bg-white/50 p-5">
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-sm font-medium text-brown">{metric.label}</h2>
            <Icon size={19} aria-hidden="true" className="text-olive" />
          </div>
          <p className="my-4 text-3xl font-semibold text-cocoa"><span aria-hidden="true">—</span><span className="sr-only">{content.unavailable}</span></p>
          <p className="text-xs leading-relaxed text-brown">{metric.description}</p>
        </article>;
      })}
    </div>

    <section aria-labelledby="traffic-overview-title" className="rounded-card border border-cocoa/10 bg-white/50 p-5 sm:p-6">
      <h2 id="traffic-overview-title" className="font-heading text-xl text-cocoa">{content.trend}</h2>
      <p className="mt-2 text-sm text-brown">{content.trendDescription}</p>
      <div className="mt-6 flex min-h-64 flex-col items-center justify-center gap-3 rounded-card border border-dashed border-cocoa/20 p-6 text-center">
        <ChartNoAxesCombined size={32} aria-hidden="true" className="text-olive" />
        <p className="font-medium text-cocoa">{content.emptyTitle}</p>
        <p className="max-w-md text-sm leading-relaxed text-brown">{content.emptyDescription}</p>
      </div>
    </section>

    <div className="grid gap-4 xl:grid-cols-3">
      {content.reports.map((report) => <section key={report.title} aria-label={report.title} className="min-w-0 rounded-card border border-cocoa/10 bg-white/50 p-5">
        <h2 className="font-heading text-xl text-cocoa">{report.title}</h2>
        <p className="mt-2 min-h-10 text-sm leading-relaxed text-brown">{report.description}</p>
        <table className="mt-5 w-full text-left text-sm">
          <caption className="sr-only">{report.title}</caption>
          <thead><tr className="border-y border-cocoa/10">{report.columns.map((column, index) => <th key={column} scope="col" className={`py-3 font-medium text-cocoa ${index === 1 ? "text-right" : ""}`}>{column}</th>)}</tr></thead>
          <tbody><tr><td colSpan={2} className="px-2 py-8 text-center text-sm leading-relaxed text-brown">{report.empty}</td></tr></tbody>
        </table>
      </section>)}
    </div>
  </section>;
}
