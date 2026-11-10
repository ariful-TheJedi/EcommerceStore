import footerData from "@/data/footer.json";
import pageData from "@/data/header.json";

import type { Metadata, Viewport } from "next";
import type { ReactNode } from "react";
import Footer from "@/components/sections/Footer";
import Navbar from "@/components/sections/Navbar";
import StorefrontFrame from "@/components/StorefrontFrame";
import { CartStoreProvider } from "@/components/CartProvider";
import "./globals.css";

const content = pageData.metadata;

export const metadata: Metadata = {
  title: content.title,
  description: content.description,
};

export const viewport: Viewport = {
  themeColor: "#FCF8F6",
  width: "device-width",
  initialScale: 1,
};

const footerLinks = footerData.links;

// Next.js requires one root layout containing the document elements.
export default function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
  return (
    <html lang="bn">
      <body>
        <CartStoreProvider>
          <StorefrontFrame navigation={<Navbar />} footer={<Footer links={footerLinks} copyrightYear={new Date().getUTCFullYear()} />}>
            {children}
          </StorefrontFrame>
        </CartStoreProvider>
      </body>
    </html>
  );
}
