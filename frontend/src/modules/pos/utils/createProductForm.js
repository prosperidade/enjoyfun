export function createProductForm(sector) {
  return {
    id: null,
    name: "",
    price: "",
    cost_price: "",
    stock_qty: "",
    low_stock_threshold: 5,
    sector,
    pdv_point_id: "",
  };
}
