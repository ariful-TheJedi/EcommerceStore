import pageData from "@/data/categories.json";
import Skeleton from "@/components/atoms/Skeleton";

export default function CategoryLoading() {
  return <main id="main-content" className="page-container py-8" role="status"><span className="sr-only">{pageData.catalog.pText}</span><Skeleton className="mb-6 h-5 w-48 motion-reduce:animate-none" /><Skeleton className="h-44 w-full motion-reduce:animate-none" /><div className="product-grid mt-10">{Array.from({ length: 4 }, (_, index) => <Skeleton key={index} className="aspect-[4/5] motion-reduce:animate-none" />)}</div></main>;
}
