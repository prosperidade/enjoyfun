import { useCallback, useEffect, useState } from "react";
import api from "../../../lib/api";
import { getProductIcon } from "../utils/getProductIcon";

export function usePosCatalog({ currentSector }) {
  const [events, setEvents] = useState([]);
  const [eventId, setEventId] = useState("1");
  const [loading, setLoading] = useState(false);
  const [products, setProducts] = useState([]);

  useEffect(() => {
    api
      .get("/events")
      .then((response) => setEvents(response.data.data || []))
      .catch(() => {});
  }, []);

  const loadProducts = useCallback(async () => {
    setLoading(true);

    try {
      const res = await api.get(
        `/${currentSector}/products?event_id=${eventId}`,
      );

      if (res.data?.data) {
        setProducts(
          res.data.data.map((product) => ({
            ...product,
            price: parseFloat(product.price),
            icon: getProductIcon(product.name, product.sector),
          })),
        );
      }
    } catch (err) {
      console.error("Erro ao listar catálogo", err);
    } finally {
      setLoading(false);
    }
  }, [currentSector, eventId]);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);

  return {
    eventId,
    events,
    loading,
    loadProducts,
    products,
    setEventId,
  };
}
