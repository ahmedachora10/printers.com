import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\CouponController::toggleStatus
 * @see app/Http/Controllers/CouponController.php:74
 * @route '/coupons/{coupon}/toggle-status'
 */
export const toggleStatus = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/coupons/{coupon}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\CouponController::toggleStatus
 * @see app/Http/Controllers/CouponController.php:74
 * @route '/coupons/{coupon}/toggle-status'
 */
toggleStatus.url = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { coupon: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { coupon: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    coupon: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        coupon: typeof args.coupon === 'object'
                ? args.coupon.id
                : args.coupon,
                }

    return toggleStatus.definition.url
            .replace('{coupon}', parsedArgs.coupon.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CouponController::toggleStatus
 * @see app/Http/Controllers/CouponController.php:74
 * @route '/coupons/{coupon}/toggle-status'
 */
toggleStatus.patch = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\CouponController::index
 * @see app/Http/Controllers/CouponController.php:22
 * @route '/coupons'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/coupons',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CouponController::index
 * @see app/Http/Controllers/CouponController.php:22
 * @route '/coupons'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CouponController::index
 * @see app/Http/Controllers/CouponController.php:22
 * @route '/coupons'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CouponController::index
 * @see app/Http/Controllers/CouponController.php:22
 * @route '/coupons'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\CouponController::store
 * @see app/Http/Controllers/CouponController.php:47
 * @route '/coupons'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/coupons',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\CouponController::store
 * @see app/Http/Controllers/CouponController.php:47
 * @route '/coupons'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CouponController::store
 * @see app/Http/Controllers/CouponController.php:47
 * @route '/coupons'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\CouponController::update
 * @see app/Http/Controllers/CouponController.php:56
 * @route '/coupons/{coupon}'
 */
export const update = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/coupons/{coupon}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\CouponController::update
 * @see app/Http/Controllers/CouponController.php:56
 * @route '/coupons/{coupon}'
 */
update.url = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { coupon: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { coupon: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    coupon: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        coupon: typeof args.coupon === 'object'
                ? args.coupon.id
                : args.coupon,
                }

    return update.definition.url
            .replace('{coupon}', parsedArgs.coupon.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CouponController::update
 * @see app/Http/Controllers/CouponController.php:56
 * @route '/coupons/{coupon}'
 */
update.put = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\CouponController::update
 * @see app/Http/Controllers/CouponController.php:56
 * @route '/coupons/{coupon}'
 */
update.patch = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\CouponController::destroy
 * @see app/Http/Controllers/CouponController.php:65
 * @route '/coupons/{coupon}'
 */
export const destroy = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/coupons/{coupon}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\CouponController::destroy
 * @see app/Http/Controllers/CouponController.php:65
 * @route '/coupons/{coupon}'
 */
destroy.url = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { coupon: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { coupon: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    coupon: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        coupon: typeof args.coupon === 'object'
                ? args.coupon.id
                : args.coupon,
                }

    return destroy.definition.url
            .replace('{coupon}', parsedArgs.coupon.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CouponController::destroy
 * @see app/Http/Controllers/CouponController.php:65
 * @route '/coupons/{coupon}'
 */
destroy.delete = (args: { coupon: number | { id: number } } | [coupon: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\CouponController::validate
 * @see app/Http/Controllers/CouponController.php:83
 * @route '/coupons/validate'
 */
export const validate = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: validate.url(options),
    method: 'get',
})

validate.definition = {
    methods: ["get","head"],
    url: '/coupons/validate',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CouponController::validate
 * @see app/Http/Controllers/CouponController.php:83
 * @route '/coupons/validate'
 */
validate.url = (options?: RouteQueryOptions) => {
    return validate.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CouponController::validate
 * @see app/Http/Controllers/CouponController.php:83
 * @route '/coupons/validate'
 */
validate.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: validate.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CouponController::validate
 * @see app/Http/Controllers/CouponController.php:83
 * @route '/coupons/validate'
 */
validate.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: validate.url(options),
    method: 'head',
})
const coupons = {
    toggleStatus: Object.assign(toggleStatus, toggleStatus),
index: Object.assign(index, index),
store: Object.assign(store, store),
update: Object.assign(update, update),
destroy: Object.assign(destroy, destroy),
validate: Object.assign(validate, validate),
}

export default coupons