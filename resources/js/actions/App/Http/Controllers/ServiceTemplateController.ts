import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/service-templates',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\ServiceTemplateController::index
 * @see app/Http/Controllers/ServiceTemplateController.php:21
 * @route '/service-templates'
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
* @see \App\Http\Controllers\ServiceTemplateController::store
 * @see app/Http/Controllers/ServiceTemplateController.php:51
 * @route '/service-templates'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/service-templates',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ServiceTemplateController::store
 * @see app/Http/Controllers/ServiceTemplateController.php:51
 * @route '/service-templates'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ServiceTemplateController::store
 * @see app/Http/Controllers/ServiceTemplateController.php:51
 * @route '/service-templates'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\ServiceTemplateController::store
 * @see app/Http/Controllers/ServiceTemplateController.php:51
 * @route '/service-templates'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ServiceTemplateController::store
 * @see app/Http/Controllers/ServiceTemplateController.php:51
 * @route '/service-templates'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
export const show = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/service-templates/{service_template}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
show.url = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { service_template: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    service_template: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        service_template: args.service_template,
                }

    return show.definition.url
            .replace('{service_template}', parsedArgs.service_template.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
show.get = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
show.head = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
    const showForm = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
        showForm.get = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\ServiceTemplateController::show
 * @see app/Http/Controllers/ServiceTemplateController.php:0
 * @route '/service-templates/{service_template}'
 */
        showForm.head = (args: { service_template: string | number } | [service_template: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
/**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
export const update = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/service-templates/{service_template}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
update.url = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { service_template: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { service_template: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    service_template: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        service_template: typeof args.service_template === 'object'
                ? args.service_template.id
                : args.service_template,
                }

    return update.definition.url
            .replace('{service_template}', parsedArgs.service_template.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
update.put = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
update.patch = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
    const updateForm = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
        updateForm.put = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \App\Http\Controllers\ServiceTemplateController::update
 * @see app/Http/Controllers/ServiceTemplateController.php:60
 * @route '/service-templates/{service_template}'
 */
        updateForm.patch = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\ServiceTemplateController::destroy
 * @see app/Http/Controllers/ServiceTemplateController.php:69
 * @route '/service-templates/{service_template}'
 */
export const destroy = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/service-templates/{service_template}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\ServiceTemplateController::destroy
 * @see app/Http/Controllers/ServiceTemplateController.php:69
 * @route '/service-templates/{service_template}'
 */
destroy.url = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { service_template: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { service_template: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    service_template: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        service_template: typeof args.service_template === 'object'
                ? args.service_template.id
                : args.service_template,
                }

    return destroy.definition.url
            .replace('{service_template}', parsedArgs.service_template.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ServiceTemplateController::destroy
 * @see app/Http/Controllers/ServiceTemplateController.php:69
 * @route '/service-templates/{service_template}'
 */
destroy.delete = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\ServiceTemplateController::destroy
 * @see app/Http/Controllers/ServiceTemplateController.php:69
 * @route '/service-templates/{service_template}'
 */
    const destroyForm = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ServiceTemplateController::destroy
 * @see app/Http/Controllers/ServiceTemplateController.php:69
 * @route '/service-templates/{service_template}'
 */
        destroyForm.delete = (args: { service_template: number | { id: number } } | [service_template: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
const ServiceTemplateController = { index, store, show, update, destroy }

export default ServiceTemplateController