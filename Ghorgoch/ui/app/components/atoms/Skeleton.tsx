export default function Skeleton({ className = "" }: { className?: string }) {
  return <div aria-hidden="true" className={`animate-pulse rounded-card bg-rose ${className}`.trim()} />;
}
