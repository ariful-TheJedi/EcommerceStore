import "server-only";
import { serverRequest } from "./request";
import type { FrontPageSettings } from "@/config/front-page";
import type { CatalogCategory } from "./categories";
import type { Product } from "@/components/catalog";

export type PublicFrontPage = { settings: FrontPageSettings; categories: CatalogCategory[]; featured: Product[]; new_arrivals: Product[] };

export async function getFrontPage(): Promise<PublicFrontPage> {
  return serverRequest<PublicFrontPage>("/catalog/front-page");
}
