"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

export type CartProduct = {
  id: string;
  variantId?: number;
  name: string;
  image?: string;
  href: string;
  price: number;
  currency: string;
  attributes?: string;
  maxQuantity?: number;
};
export type CartItem = CartProduct & { quantity: number };
type CartState = {
  items: CartItem[];
  cartCount: number;
  hydrated: boolean;
  clearCart: () => void;
  addToCart: (product: CartProduct) => void;
  updateQuantity: (id: string, quantity: number) => void;
  removeItem: (id: string) => void;
};
const CartContext = createContext<CartState | null>(null);

export function CartStoreProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<CartItem[]>([]);
  const [hydrated, setHydrated] = useState(false);
  useEffect(() => {
    try {
      const saved: unknown = JSON.parse(localStorage.getItem("ghorgoch.cart.v1") || "[]");
      if (Array.isArray(saved)) {
        setItems(saved.filter((item): item is CartItem => item && typeof item.id === "string" && typeof item.name === "string" && typeof item.href === "string" && typeof item.currency === "string" && Number.isFinite(item.price) && item.price >= 0 && Number.isInteger(item.quantity) && item.quantity > 0 && item.quantity <= 100).slice(0, 50));
      }
    } catch { /* An unavailable browser store must not prevent shopping. */ }
    setHydrated(true);
  }, []);
  useEffect(() => {
    if (hydrated) {
      try { localStorage.setItem("ghorgoch.cart.v1", JSON.stringify(items)); } catch { /* The cart remains available in memory. */ }
    }
  }, [items, hydrated]);

  function addToCart(product: CartProduct) {
    if (!Number.isFinite(product.price) || product.price < 0) { return; }
    setItems((current) => {
      const existing = current.find((item) => item.id === product.id);
      const maxQuantity = product.maxQuantity ?? 99;
      if (maxQuantity < 1) { return current; }
      return existing ? current.map((item) => item.id === product.id ? { ...item, quantity: Math.min(item.quantity + 1, maxQuantity) } : item) : [...current, { ...product, quantity: 1 }];
    });
  }

  function updateQuantity(id: string, quantity: number) {
    if (!Number.isInteger(quantity) || quantity < 1) { return; }
    setItems((current) => current.map((item) => item.id === id ? { ...item, quantity: Math.min(quantity, item.maxQuantity ?? 99) } : item));
  }

  function removeItem(id: string) { setItems((current) => current.filter((item) => item.id !== id)); }

  return <CartContext.Provider value={{ items, hydrated, clearCart: () => setItems([]), cartCount: items.reduce((total, item) => total + item.quantity, 0), addToCart, updateQuantity, removeItem }}>{children}</CartContext.Provider>;
}

export function useCartStore(): CartState {
  const cart = useContext(CartContext);
  if (!cart) { throw new Error("useCartStore must be used inside CartStoreProvider"); }
  return cart;
}
