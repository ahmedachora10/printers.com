import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:67
 * @route '/product-categories/{productCategory}/toggle-status'
 */
export const toggleStatus = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/product-categories/{productCategory}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:67
 * @route '/product-categories/{productCategory}/toggle-status'
 */
toggleStatus.url = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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
 * @see app/Http/Controllers/ProductCategoryController.php:67
 * @route '/product-categories/{productCategory}/toggle-status'
 */
toggleStatus.patch = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::toggleStatus
 * @see app/Http/Controllers/ProductCategoryController.php:67
 * @route '/product-categories/{productCategory}/toggle-status'
 */
    const toggleStatusForm = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
 * @see app/Http/Controllers/ProductCategoryController.php:67
 * @route '/product-categories/{productCategory}/toggle-status'
 */
        toggleStatusForm.patch = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: toggleStatus.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    toggleStatus.form = toggleStatusForm
/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:21
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
 * @see app/Http/Controllers/ProductCategoryController.php:21
 * @route '/product-categories'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:21
 * @route '/product-categories'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:21
 * @route '/product-categories'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:21
 * @route '/product-categories'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:21
 * @route '/product-categories'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\ProductCategoryController::index
 * @see app/Http/Controllers/ProductCategoryController.php:21
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
 * @see app/Http/Controllers/ProductCategoryController.php:40
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
 * @see app/Http/Controllers/ProductCategoryController.php:40
 * @route '/product-categories'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:40
 * @route '/product-categories'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:40
 * @route '/product-categories'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ProductCategoryController::store
 * @see app/Http/Controllers/ProductCategoryController.php:40
 * @route '/product-categories'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
export const update = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/product-categories/{productCategory}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
update.url = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{productCategory}', parsedArgs.productCategory.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
update.put = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
update.patch = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::update
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
    const updateForm = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
        updateForm.put = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
 * @see app/Http/Controllers/ProductCategoryController.php:49
 * @route '/product-categories/{productCategory}'
 */
        updateForm.patch = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
 * @see app/Http/Controllers/ProductCategoryController.php:58
 * @route '/product-categories/{productCategory}'
 */
export const destroy = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/product-categories/{productCategory}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:58
 * @route '/product-categories/{productCategory}'
 */
destroy.url = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{productCategory}', parsedArgs.productCategory.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:58
 * @route '/product-categories/{productCategory}'
 */
destroy.delete = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\ProductCategoryController::destroy
 * @see app/Http/Controllers/ProductCategoryController.php:58
 * @route '/product-categories/{productCategory}'
 */
    const destroyForm = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
 * @see app/Http/Controllers/ProductCategoryController.php:58
 * @route '/product-categories/{productCategory}'
 */
        destroyForm.delete = (args: { productCategory: number | { id: number } } | [productCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
const productCategories = {
    toggleStatus: Object.assign(toggleStatus, toggleStatus),
index: Object.assign(index, index),
store: Object.assign(store, store),
update: Object.assign(update, update),
destroy: Object.assign(destroy, destroy),
}

export default productCategories