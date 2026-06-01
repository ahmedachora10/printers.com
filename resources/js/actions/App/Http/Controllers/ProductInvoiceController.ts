import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:18
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
 * @see app/Http/Controllers/ProductInvoiceController.php:18
 * @route '/pos/product'
 */
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:18
 * @route '/pos/product'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:18
 * @route '/pos/product'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:18
 * @route '/pos/product'
 */
    const createForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: create.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:18
 * @route '/pos/product'
 */
        createForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: create.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\ProductInvoiceController::create
 * @see app/Http/Controllers/ProductInvoiceController.php:18
 * @route '/pos/product'
 */
        createForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: create.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    create.form = createForm
/**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:65
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
 * @see app/Http/Controllers/ProductInvoiceController.php:65
 * @route '/pos/product'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:65
 * @route '/pos/product'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:65
 * @route '/pos/product'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ProductInvoiceController::store
 * @see app/Http/Controllers/ProductInvoiceController.php:65
 * @route '/pos/product'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
const ProductInvoiceController = { create, store }

export default ProductInvoiceController