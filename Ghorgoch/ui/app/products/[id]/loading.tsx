import Skeleton from "@/components/atoms/Skeleton";

export default function ProductLoading() {
  return <main className="page-container grid gap-6 py-10 md:grid-cols-2"><Skeleton className="aspect-square" /><div className="space-y-4"><Skeleton className="h-10" /><Skeleton className="h-28" /><Skeleton className="h-11" /></div></main>;
}
