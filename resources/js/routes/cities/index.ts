import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\CityController::index
 * @see app/Http/Controllers/CityController.php:20
 * @route '/cities'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/cities',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CityController::index
 * @see app/Http/Controllers/CityController.php:20
 * @route '/cities'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::index
 * @see app/Http/Controllers/CityController.php:20
 * @route '/cities'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CityController::index
 * @see app/Http/Controllers/CityController.php:20
 * @route '/cities'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\CityController::create
 * @see app/Http/Controllers/CityController.php:43
 * @route '/cities/create'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/cities/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CityController::create
 * @see app/Http/Controllers/CityController.php:43
 * @route '/cities/create'
 */
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::create
 * @see app/Http/Controllers/CityController.php:43
 * @route '/cities/create'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CityController::create
 * @see app/Http/Controllers/CityController.php:43
 * @route '/cities/create'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\CityController::store
 * @see app/Http/Controllers/CityController.php:50
 * @route '/cities'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/cities',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\CityController::store
 * @see app/Http/Controllers/CityController.php:50
 * @route '/cities'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::store
 * @see app/Http/Controllers/CityController.php:50
 * @route '/cities'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\CityController::show
 * @see app/Http/Controllers/CityController.php:0
 * @route '/cities/{city}'
 */
export const show = (args: { city: string | number } | [city: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/cities/{city}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CityController::show
 * @see app/Http/Controllers/CityController.php:0
 * @route '/cities/{city}'
 */
show.url = (args: { city: string | number } | [city: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { city: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    city: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        city: args.city,
                }

    return show.definition.url
            .replace('{city}', parsedArgs.city.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::show
 * @see app/Http/Controllers/CityController.php:0
 * @route '/cities/{city}'
 */
show.get = (args: { city: string | number } | [city: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CityController::show
 * @see app/Http/Controllers/CityController.php:0
 * @route '/cities/{city}'
 */
show.head = (args: { city: string | number } | [city: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\CityController::edit
 * @see app/Http/Controllers/CityController.php:59
 * @route '/cities/{city}/edit'
 */
export const edit = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/cities/{city}/edit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CityController::edit
 * @see app/Http/Controllers/CityController.php:59
 * @route '/cities/{city}/edit'
 */
edit.url = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { city: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { city: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    city: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        city: typeof args.city === 'object'
                ? args.city.id
                : args.city,
                }

    return edit.definition.url
            .replace('{city}', parsedArgs.city.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::edit
 * @see app/Http/Controllers/CityController.php:59
 * @route '/cities/{city}/edit'
 */
edit.get = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CityController::edit
 * @see app/Http/Controllers/CityController.php:59
 * @route '/cities/{city}/edit'
 */
edit.head = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\CityController::update
 * @see app/Http/Controllers/CityController.php:68
 * @route '/cities/{city}'
 */
export const update = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/cities/{city}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\CityController::update
 * @see app/Http/Controllers/CityController.php:68
 * @route '/cities/{city}'
 */
update.url = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { city: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { city: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    city: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        city: typeof args.city === 'object'
                ? args.city.id
                : args.city,
                }

    return update.definition.url
            .replace('{city}', parsedArgs.city.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::update
 * @see app/Http/Controllers/CityController.php:68
 * @route '/cities/{city}'
 */
update.put = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\CityController::update
 * @see app/Http/Controllers/CityController.php:68
 * @route '/cities/{city}'
 */
update.patch = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\CityController::destroy
 * @see app/Http/Controllers/CityController.php:77
 * @route '/cities/{city}'
 */
export const destroy = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/cities/{city}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\CityController::destroy
 * @see app/Http/Controllers/CityController.php:77
 * @route '/cities/{city}'
 */
destroy.url = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { city: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { city: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    city: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        city: typeof args.city === 'object'
                ? args.city.id
                : args.city,
                }

    return destroy.definition.url
            .replace('{city}', parsedArgs.city.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::destroy
 * @see app/Http/Controllers/CityController.php:77
 * @route '/cities/{city}'
 */
destroy.delete = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\CityController::toggleStatus
 * @see app/Http/Controllers/CityController.php:86
 * @route '/cities/{city}/toggle-status'
 */
export const toggleStatus = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/cities/{city}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\CityController::toggleStatus
 * @see app/Http/Controllers/CityController.php:86
 * @route '/cities/{city}/toggle-status'
 */
toggleStatus.url = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { city: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { city: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    city: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        city: typeof args.city === 'object'
                ? args.city.id
                : args.city,
                }

    return toggleStatus.definition.url
            .replace('{city}', parsedArgs.city.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CityController::toggleStatus
 * @see app/Http/Controllers/CityController.php:86
 * @route '/cities/{city}/toggle-status'
 */
toggleStatus.patch = (args: { city: number | { id: number } } | [city: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})
const cities = {
    index: Object.assign(index, index),
create: Object.assign(create, create),
store: Object.assign(store, store),
show: Object.assign(show, show),
edit: Object.assign(edit, edit),
update: Object.assign(update, update),
destroy: Object.assign(destroy, destroy),
toggleStatus: Object.assign(toggleStatus, toggleStatus),
}

export default cities