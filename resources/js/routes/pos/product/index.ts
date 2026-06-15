import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:20
 * @route '/pos/product'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/pos/product',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:20
 * @route '/pos/product'
 */
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:20
 * @route '/pos/product'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:20
 * @route '/pos/product'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:71
 * @route '/pos/product'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/pos/product',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:71
 * @route '/pos/product'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:71
 * @route '/pos/product'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProductInvoiceController::print
 * @see app/Http/Controllers/ProductInvoiceController.php:86
 * @route '/pos/product/{invoice}/print'
 */
export const print = (args: { invoice: number | { id: number } } | [invoice: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: print.url(args, options),
    method: 'get',
})

print.definition = {
    methods: ["get","head"],
    url: '/pos/product/{invoice}/print',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ProductInvoiceController::print
 * @see app/Http/Controllers/ProductInvoiceController.php:86
 * @route '/pos/product/{invoice}/print'
 */
print.url = (args: { invoice: number | { id: number } } | [invoice: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { invoice: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { invoice: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    invoice: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        invoice: typeof args.invoice === 'object'
                ? args.invoice.id
                : args.invoice,
                }

    return print.definition.url
            .replace('{invoice}', parsedArgs.invoice.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductInvoiceController::print
 * @see app/Http/Controllers/ProductInvoiceController.php:86
 * @route '/pos/product/{invoice}/print'
 */
print.get = (args: { invoice: number | { id: number } } | [invoice: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: print.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ProductInvoiceController::print
 * @see app/Http/Controllers/ProductInvoiceController.php:86
 * @route '/pos/product/{invoice}/print'
 */
print.head = (args: { invoice: number | { id: number } } | [invoice: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: print.url(args, options),
    method: 'head',
})
const product = {
    create: Object.assign(create, create),
store: Object.assign(store, store),
print: Object.assign(print, print),
}

export default product