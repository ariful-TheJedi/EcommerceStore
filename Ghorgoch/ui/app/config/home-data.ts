import pageData from "@/data/front-page.json";

const content = pageData.collections;

export type HomeProduct = {
  id: string;
  name: string;
  image: string;
  price: number;
  originalPrice?: number;
  isNew?: boolean;
};

export const homeCategories = content.homeCategories;
export const featuredHomeProducts = content.featuredHomeProducts as HomeProduct[];
export const newHomeProducts = content.newHomeProducts as HomeProduct[];
export const homeFaqItems = content.homeFaqItems;
