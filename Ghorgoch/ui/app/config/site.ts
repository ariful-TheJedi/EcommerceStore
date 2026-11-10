import pageData from "@/data/header.json";

const content = pageData.site;

export const siteConfig = { ...content.siteConfig, url: process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000" };
