export function createProductForm(sector) {
  return {
    id: null,
    name: "",
    price: "",
    stock_qty: "",
    low_stock_threshold: 5,
    sector,
  };
}
