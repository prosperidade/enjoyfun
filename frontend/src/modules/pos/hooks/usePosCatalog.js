import { useCallback, useEffect, useState } from "react";
import api from "../../../lib/api";
import { getProductIcon } from "../utils/getProductIcon";

export function usePosCatalog({ currentSector }) {
  const [events, setEvents] = useState([]);
  const [eventsError, setEventsError] = useState("");
  const [eventId, setEventId] = useState("");
  const [loading, setLoading] = useState(false);
  const [products, setProducts] = useState([]);
  const [catalogError, setCatalogError] = useState("");

  useEffect(() => {
    let active = true;

    api
      .get("/events")
      .then((response) => {
        if (!active) {
          return;
        }

        setEvents(response.data.data || []);
        setEventsError("");
      })
      .catch((err) => {
        if (!active) {
          return;
        }

        setEventsError(
          err.response?.data?.message ||
            "Nao foi possivel carregar a lista de eventos.",
        );
      });

    return () => {
      active = false;
    };
  }, []);

  const loadProducts = useCallback(async () => {
    const normalizedEventId = Number(eventId);
    if (normalizedEventId <= 0) {
      setProducts([]);
      setLoading(false);
      setCatalogError("");
      return;
    }

    setLoading(true);
    setCatalogError("");

    try {
      const res = await api.get(
        `/${currentSector}/products?event_id=${normalizedEventId}`,
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
      setCatalogError(
        err.response?.data?.message ||
          "Nao foi possivel atualizar o catalogo agora.",
      );
    } finally {
      setLoading(false);
    }
  }, [currentSector, eventId]);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);

  return {
    catalogError,
    eventId,
    events,
    eventsError,
    loading,
    loadProducts,
    products,
    setEventId,
  };
}
