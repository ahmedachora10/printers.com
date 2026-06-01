import stockMovements from './stock-movements'
import products from './products'
const inventory = {
    stockMovements: Object.assign(stockMovements, stockMovements),
products: Object.assign(products, products),
}

export default inventory