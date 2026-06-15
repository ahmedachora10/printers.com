import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/branch-services',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\BranchServiceController::index
 * @see app/Http/Controllers/BranchServiceController.php:22
 * @route '/branch-services'
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
* @see \App\Http\Controllers\BranchServiceController::store
 * @see app/Http/Controllers/BranchServiceController.php:59
 * @route '/branch-services'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/branch-services',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\BranchServiceController::store
 * @see app/Http/Controllers/BranchServiceController.php:59
 * @route '/branch-services'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchServiceController::store
 * @see app/Http/Controllers/BranchServiceController.php:59
 * @route '/branch-services'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\BranchServiceController::store
 * @see app/Http/Controllers/BranchServiceController.php:59
 * @route '/branch-services'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\BranchServiceController::store
 * @see app/Http/Controllers/BranchServiceController.php:59
 * @route '/branch-services'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
export const update = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/branch-services/{branch_service}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
update.url = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch_service: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { branch_service: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    branch_service: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        branch_service: typeof args.branch_service === 'object'
                ? args.branch_service.id
                : args.branch_service,
                }

    return update.definition.url
            .replace('{branch_service}', parsedArgs.branch_service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
update.put = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
update.patch = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
    const updateForm = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
        updateForm.put = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \App\Http\Controllers\BranchServiceController::update
 * @see app/Http/Controllers/BranchServiceController.php:68
 * @route '/branch-services/{branch_service}'
 */
        updateForm.patch = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\BranchServiceController::destroy
 * @see app/Http/Controllers/BranchServiceController.php:77
 * @route '/branch-services/{branch_service}'
 */
export const destroy = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/branch-services/{branch_service}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\BranchServiceController::destroy
 * @see app/Http/Controllers/BranchServiceController.php:77
 * @route '/branch-services/{branch_service}'
 */
destroy.url = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch_service: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { branch_service: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    branch_service: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        branch_service: typeof args.branch_service === 'object'
                ? args.branch_service.id
                : args.branch_service,
                }

    return destroy.definition.url
            .replace('{branch_service}', parsedArgs.branch_service.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchServiceController::destroy
 * @see app/Http/Controllers/BranchServiceController.php:77
 * @route '/branch-services/{branch_service}'
 */
destroy.delete = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\BranchServiceController::destroy
 * @see app/Http/Controllers/BranchServiceController.php:77
 * @route '/branch-services/{branch_service}'
 */
    const destroyForm = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\BranchServiceController::destroy
 * @see app/Http/Controllers/BranchServiceController.php:77
 * @route '/branch-services/{branch_service}'
 */
        destroyForm.delete = (args: { branch_service: number | { id: number } } | [branch_service: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
const BranchServiceController = { index, store, update, destroy }

export default BranchServiceController