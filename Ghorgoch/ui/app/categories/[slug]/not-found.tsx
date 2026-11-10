import pageData from "@/data/categories.json";
import NotFoundPage from "@/components/sections/NotFoundPage";

export default function CategoryNotFound() {
  return <NotFoundPage title={pageData.products.h1Text} message={pageData.catalog.label} />;
}
