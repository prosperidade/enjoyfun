import { useCallback, useEffect, useMemo, useState } from "react";

const CART_STORAGE_KEY = "enjoyfun_pos_cart";
const CART_TTL_MS = 4 * 60 * 60 * 1000; // 4 hours

function loadPersistedCart() {
  try {
    const raw = localStorage.getItem(CART_STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (
      parsed &&
      Array.isArray(parsed.items) &&
      typeof parsed.savedAt === "number" &&
      Date.now() - parsed.savedAt < CART_TTL_MS
    ) {
      return parsed.items;
    }
  } catch {
    // corrupted data — ignore
  }
  localStorage.removeItem(CART_STORAGE_KEY);
  return [];
}

export function usePosCart() {
  const [cart, setCart] = useState(() => loadPersistedCart());

  useEffect(() => {
    if (cart.length > 0) {
      localStorage.setItem(
        CART_STORAGE_KEY,
        JSON.stringify({ items: cart, savedAt: Date.now() }),
      );
    } else {
      localStorage.removeItem(CART_STORAGE_KEY);
    }
  }, [cart]);

  const total = useMemo(
    () => cart.reduce((acc, item) => acc + item.price * item.quantity, 0),
    [cart],
  );

  const addToCart = useCallback((product) => {
    setCart((prev) => {
      const existing = prev.find((item) => item.id === product.id);

      if (existing) {
        return prev.map((item) =>
          item.id === product.id
            ? { ...item, quantity: item.quantity + 1 }
            : item,
        );
      }

      return [...prev, { ...product, quantity: 1 }];
    });
  }, []);

  const updateQuantity = useCallback((id, delta) => {
    setCart((prev) =>
      prev.flatMap((item) => {
        if (item.id !== id) {
          return [item];
        }

        const nextQuantity = item.quantity + delta;
        return nextQuantity > 0 ? [{ ...item, quantity: nextQuantity }] : [];
      }),
    );
  }, []);

  const removeFromCart = useCallback((id) => {
    setCart((prev) => prev.filter((item) => item.id !== id));
  }, []);

  const clearCart = useCallback(() => {
    setCart([]);
    localStorage.removeItem(CART_STORAGE_KEY);
  }, []);

  return {
    addToCart,
    cart,
    clearCart,
    removeFromCart,
    total,
    updateQuantity,
  };
}
