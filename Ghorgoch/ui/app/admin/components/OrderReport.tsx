import { CircleHelp, Wallet } from "lucide-react";
import { orderMoney, type OrderReport as Report } from "@/admin/services/orders";

export default function OrderReport({ report, loading, service }: { report: Report | null; loading: boolean; service: "income" | "expenses" | "profit" }) {
  if (loading) {
    return <div role="status" className="rounded-2xl border border-cocoa/10 bg-[#fffdfb] px-6 py-16 text-center text-sm text-brown">Loading report...</div>;
  }
  if (!report) { return null; }
  if (!report.currencies.length) {
    return <div className="flex flex-col items-center gap-3 rounded-2xl border border-cocoa/10 bg-[#fffdfb] px-6 py-16 text-center"><Wallet size={28} strokeWidth={1.4} className="text-olive" aria-hidden="true" /><h2 className="font-semibold">No activity in this period</h2><p className="text-sm text-brown">Choose another date range to see your report.</p></div>;
  }

  return <section aria-label="Financial report" className="flex flex-col gap-5">
    {report.currencies.map((row) => {
      const loss = row.profit !== null && Number(row.profit) < 0;
      const title = service === "income" ? "Delivered income" : service === "expenses" ? "Total expenses" : loss ? "Net loss" : "Net profit";
      const amount = service === "income" ? row.income : service === "expenses" ? row.expenses : row.profit;
      return <article key={row.currency} className="overflow-hidden rounded-2xl border border-cocoa/10 bg-[#fffdfb]">
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-cocoa/8 px-5 py-4 sm:px-6"><h2 className="text-sm font-semibold">{title}</h2><span className="rounded-md bg-cocoa/5 px-2 py-1 text-xs font-medium text-brown">{row.currency}</span></header>
        <div className="flex flex-col gap-5 p-5 sm:p-6">
          <div><p className={`break-words text-4xl font-semibold tracking-tight tabular-nums ${service === "profit" ? loss ? "text-[#9b3528]" : "text-olive" : "text-cocoa"}`}>{amount === null ? "Costs needed" : orderMoney(amount, row.currency)}</p><p className="mt-3 text-xs leading-5 text-brown">{service === "income" ? "Delivered sales and shipping charged, excluding tax." : service === "expenses" ? "Product purchase costs and recorded operating expenses." : "Delivered income minus product costs and operating expenses."}</p></div>
          {service !== "income" && row.missing_cost_orders > 0 && <p role="status" className="flex items-start gap-2 rounded-xl bg-[#fff6e5] p-3 text-xs leading-6 text-[#815a17]"><CircleHelp size={16} className="mt-1 shrink-0" aria-hidden="true" /><span><strong>{row.missing_cost_orders} order(s) need purchase costs.</strong> Enter them in the order&apos;s Costs section to complete this report.</span></p>}
          {(service === "income" || service === "profit") && <dl className="grid grid-cols-2 gap-4 rounded-xl bg-ivory p-4"><div><dt className="text-xs text-brown">Items delivered</dt><dd className="mt-2 text-xl font-semibold tabular-nums">{row.delivered_items}</dd></div><div><dt className="text-xs text-brown">Items returned</dt><dd className="mt-2 text-xl font-semibold tabular-nums">{row.returned_items}</dd></div></dl>}{service !== "income" && <dl className="flex flex-col gap-4 border-t border-cocoa/8 pt-5 text-sm">
            {service === "profit" && <div className="flex items-start justify-between gap-5"><dt className="text-brown">Delivered income</dt><dd className="text-right font-medium tabular-nums">{orderMoney(row.income, row.currency)}</dd></div>}
            <div className="flex items-start justify-between gap-5"><dt className="text-brown">Product costs & damaged returns</dt><dd className="text-right font-medium tabular-nums">{orderMoney(row.product_expenses, row.currency)}</dd></div>
            <div className="flex items-start justify-between gap-5"><dt className="text-brown">Courier, packaging & other costs</dt><dd className="text-right font-medium tabular-nums">{orderMoney(row.operating_expenses, row.currency)}</dd></div>
            <div className="flex items-start justify-between gap-5 border-t border-cocoa/8 pt-4"><dt className="font-semibold">Total expenses{row.missing_cost_orders > 0 ? " (partial)" : ""}</dt><dd className="text-right font-semibold tabular-nums">{orderMoney(row.expenses, row.currency)}</dd></div>
          </dl>}
        </div>
        <details className="border-t border-cocoa/8 px-5 py-4 sm:px-6"><summary className="cursor-pointer text-xs font-medium text-brown focus-visible:outline-2 focus-visible:outline-olive">How this report is calculated</summary><p className="mt-3 max-w-3xl text-xs leading-6 text-brown">Reports use the order placement date in UTC and each order&apos;s current status. Deleted orders remain included to preserve financial history. Returned orders contribute no sales income. Restocked products have no product loss; damaged or lost returns include their purchase cost. Record actual operating expenses in each order&apos;s Costs section. Payment collections and refunds are reconciled separately.</p></details>
      </article>;
    })}
  </section>;
}
