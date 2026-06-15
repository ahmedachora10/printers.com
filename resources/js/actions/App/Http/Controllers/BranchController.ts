import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\BranchController::index
 * @see app/Http/Controllers/BranchController.php:23
 * @route '/branches'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/branches',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BranchController::index
 * @see app/Http/Controllers/BranchController.php:23
 * @route '/branches'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchController::index
 * @see app/Http/Controllers/BranchController.php:23
 * @route '/branches'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\BranchController::index
 * @see app/Http/Controllers/BranchController.php:23
 * @route '/branches'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\BranchController::store
 * @see app/Http/Controllers/BranchController.php:62
 * @route '/branches'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/branches',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\BranchController::store
 * @see app/Http/Controllers/BranchController.php:62
 * @route '/branches'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchController::store
 * @see app/Http/Controllers/BranchController.php:62
 * @route '/branches'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\BranchController::show
 * @see app/Http/Controllers/BranchController.php:0
 * @route '/branches/{branch}'
 */
export const show = (args: { branch: string | number } | [branch: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/branches/{branch}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BranchController::show
 * @see app/Http/Controllers/BranchController.php:0
 * @route '/branches/{branch}'
 */
show.url = (args: { branch: string | number } | [branch: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    branch: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        branch: args.branch,
                }

    return show.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchController::show
 * @see app/Http/Controllers/BranchController.php:0
 * @route '/branches/{branch}'
 */
show.get = (args: { branch: string | number } | [branch: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\BranchController::show
 * @see app/Http/Controllers/BranchController.php:0
 * @route '/branches/{branch}'
 */
show.head = (args: { branch: string | number } | [branch: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\BranchController::update
 * @see app/Http/Controllers/BranchController.php:83
 * @route '/branches/{branch}'
 */
export const update = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/branches/{branch}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\BranchController::update
 * @see app/Http/Controllers/BranchController.php:83
 * @route '/branches/{branch}'
 */
update.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { branch: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    branch: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        branch: typeof args.branch === 'object'
                ? args.branch.id
                : args.branch,
                }

    return update.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchController::update
 * @see app/Http/Controllers/BranchController.php:83
 * @route '/branches/{branch}'
 */
update.put = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\BranchController::update
 * @see app/Http/Controllers/BranchController.php:83
 * @route '/branches/{branch}'
 */
update.patch = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\BranchController::destroy
 * @see app/Http/Controllers/BranchController.php:92
 * @route '/branches/{branch}'
 */
export const destroy = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/branches/{branch}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\BranchController::destroy
 * @see app/Http/Controllers/BranchController.php:92
 * @route '/branches/{branch}'
 */
destroy.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { branch: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    branch: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        branch: typeof args.branch === 'object'
                ? args.branch.id
                : args.branch,
                }

    return destroy.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchController::destroy
 * @see app/Http/Controllers/BranchController.php:92
 * @route '/branches/{branch}'
 */
destroy.delete = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\BranchController::toggleStatus
 * @see app/Http/Controllers/BranchController.php:101
 * @route '/branches/{branch}/toggle-status'
 */
export const toggleStatus = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/branches/{branch}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\BranchController::toggleStatus
 * @see app/Http/Controllers/BranchController.php:101
 * @route '/branches/{branch}/toggle-status'
 */
toggleStatus.url = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { branch: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { branch: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    branch: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        branch: typeof args.branch === 'object'
                ? args.branch.id
                : args.branch,
                }

    return toggleStatus.definition.url
            .replace('{branch}', parsedArgs.branch.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BranchController::toggleStatus
 * @see app/Http/Controllers/BranchController.php:101
 * @route '/branches/{branch}/toggle-status'
 */
toggleStatus.patch = (args: { branch: number | { id: number } } | [branch: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})
const BranchController = { index, store, show, update, destroy, toggleStatus }

export default BranchController