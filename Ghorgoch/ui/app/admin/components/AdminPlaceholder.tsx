import pageData from "@/data/admin/messages.json";

const content = pageData.workspace;

export default function AdminPlaceholder({ title }: { title: string }) {
  return <section aria-labelledby="admin-page-title">
    <h1 id="admin-page-title" className="font-heading text-3xl text-cocoa">{title}</h1>
    <div className="mt-6 flex min-h-72 items-center justify-center rounded-card border border-dashed border-cocoa/20 p-6"><p className="text-sm text-brown">{content.thisWorkspaceIsReadyForTheNext}</p></div>
  </section>;
}
