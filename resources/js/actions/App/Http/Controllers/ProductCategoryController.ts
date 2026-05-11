import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/product-categories',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:22
 * @route '/product-categories'
 */
        indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    index.form = indexForm
/**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:44
 * @route '/product-categories'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/product-categories',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:44
 * @route '/product-categories'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:44
 * @route '/product-categories'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:44
 * @route '/product-categories'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:44
 * @route '/product-categories'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
export const update = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/product-categories/{product_category}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
update.url = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { product_category: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { product_category: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    product_category: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        product_category: typeof args.product_category === 'object'
                ? args.product_category.id
                : args.product_category,
                }

    return update.definition.url
            .replace('{product_category}', parsedArgs.product_category.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
update.put = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
update.patch = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
    const updateForm = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
        updateForm.put = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:53
 * @route '/product-categories/{product_category}'
 */
        updateForm.patch = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    update.form = updateForm
/**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:62
 * @route '/product-categories/{product_category}'
 */
export const destroy = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/product-categories/{product_category}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:62
 * @route '/product-categories/{product_category}'
 */
destroy.url = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { product_category: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { product_category: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    product_category: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        product_category: typeof args.product_category === 'object'
                ? args.product_category.id
                : args.product_category,
                }

    return destroy.definition.url
            .replace('{product_category}', parsedArgs.product_category.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:62
 * @route '/product-categories/{product_category}'
 */
destroy.delete = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:62
 * @route '/product-categories/{product_category}'
 */
    const destroyForm = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:62
 * @route '/product-categories/{product_category}'
 */
        destroyForm.delete = (args: { product_category: string | number | { id: string | number } } | [product_category: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
/**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:71
 * @route '/product-categories/{productCategory}/toggle-status'
 */
export const toggleStatus = (args: { productCategory: string | number | { id: string | number } } | [productCategory: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/product-categories/{productCategory}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:71
 * @route '/product-categories/{productCategory}/toggle-status'
 */
toggleStatus.url = (args: { productCategory: string | number | { id: string | number } } | [productCategory: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { productCategory: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { productCategory: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    productCategory: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        productCategory: typeof args.productCategory === 'object'
                ? args.productCategory.id
                : args.productCategory,
                }

    return toggleStatus.definition.url
            .replace('{productCategory}', parsedArgs.productCategory.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:71
 * @route '/product-categories/{productCategory}/toggle-status'
 */
toggleStatus.patch = (args: { productCategory: string | number | { id: string | number } } | [productCategory: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:71
 * @route '/product-categories/{productCategory}/toggle-status'
 */
    const toggleStatusForm = (args: { productCategory: string | number | { id: string | number } } | [productCategory: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: toggleStatus.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:71
 * @route '/product-categories/{productCategory}/toggle-status'
 */
        toggleStatusForm.patch = (args: { productCategory: string | number | { id: string | number } } | [productCategory: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: toggleStatus.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    toggleStatus.form = toggleStatusForm
const ProductCategoryController = { index, store, update, destroy, toggleStatus }

export default ProductCategoryController