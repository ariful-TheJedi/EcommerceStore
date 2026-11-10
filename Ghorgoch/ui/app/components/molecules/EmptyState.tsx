export default function EmptyState({ title, message }: { title: string; message: string }) {
  return <div className="promo-panel text-center"><h2>{title}</h2><p className="mt-2">{message}</p></div>;
}
