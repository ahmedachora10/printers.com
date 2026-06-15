import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\StockMovementController::index
 * @see app/Http/Controllers/StockMovementController.php:19
 * @route '/inventory/stock-movements'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/inventory/stock-movements',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\StockMovementController::index
 * @see app/Http/Controllers/StockMovementController.php:19
 * @route '/inventory/stock-movements'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\StockMovementController::index
 * @see app/Http/Controllers/StockMovementController.php:19
 * @route '/inventory/stock-movements'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\StockMovementController::index
 * @see app/Http/Controllers/StockMovementController.php:19
 * @route '/inventory/stock-movements'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\StockMovementController::store
 * @see app/Http/Controllers/StockMovementController.php:59
 * @route '/inventory/stock-movements'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/inventory/stock-movements',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\StockMovementController::store
 * @see app/Http/Controllers/StockMovementController.php:59
 * @route '/inventory/stock-movements'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\StockMovementController::store
 * @see app/Http/Controllers/StockMovementController.php:59
 * @route '/inventory/stock-movements'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})
const StockMovementController = { index, store }

export default StockMovementController