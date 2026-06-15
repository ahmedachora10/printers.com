import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\PaymentMethodController::store
 * @see app/Http/Controllers/PaymentMethodController.php:16
 * @route '/payment-methods'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/payment-methods',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PaymentMethodController::store
 * @see app/Http/Controllers/PaymentMethodController.php:16
 * @route '/payment-methods'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PaymentMethodController::store
 * @see app/Http/Controllers/PaymentMethodController.php:16
 * @route '/payment-methods'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\PaymentMethodController::update
 * @see app/Http/Controllers/PaymentMethodController.php:25
 * @route '/payment-methods/{payment_method}'
 */
export const update = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/payment-methods/{payment_method}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\PaymentMethodController::update
 * @see app/Http/Controllers/PaymentMethodController.php:25
 * @route '/payment-methods/{payment_method}'
 */
update.url = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { payment_method: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { payment_method: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    payment_method: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        payment_method: typeof args.payment_method === 'object'
                ? args.payment_method.id
                : args.payment_method,
                }

    return update.definition.url
            .replace('{payment_method}', parsedArgs.payment_method.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PaymentMethodController::update
 * @see app/Http/Controllers/PaymentMethodController.php:25
 * @route '/payment-methods/{payment_method}'
 */
update.put = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\PaymentMethodController::update
 * @see app/Http/Controllers/PaymentMethodController.php:25
 * @route '/payment-methods/{payment_method}'
 */
update.patch = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\PaymentMethodController::destroy
 * @see app/Http/Controllers/PaymentMethodController.php:34
 * @route '/payment-methods/{payment_method}'
 */
export const destroy = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/payment-methods/{payment_method}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\PaymentMethodController::destroy
 * @see app/Http/Controllers/PaymentMethodController.php:34
 * @route '/payment-methods/{payment_method}'
 */
destroy.url = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { payment_method: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { payment_method: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    payment_method: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        payment_method: typeof args.payment_method === 'object'
                ? args.payment_method.id
                : args.payment_method,
                }

    return destroy.definition.url
            .replace('{payment_method}', parsedArgs.payment_method.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PaymentMethodController::destroy
 * @see app/Http/Controllers/PaymentMethodController.php:34
 * @route '/payment-methods/{payment_method}'
 */
destroy.delete = (args: { payment_method: number | { id: number } } | [payment_method: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\PaymentMethodController::toggleStatus
 * @see app/Http/Controllers/PaymentMethodController.php:43
 * @route '/payment-methods/{paymentMethod}/toggle-status'
 */
export const toggleStatus = (args: { paymentMethod: number | { id: number } } | [paymentMethod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/payment-methods/{paymentMethod}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\PaymentMethodController::toggleStatus
 * @see app/Http/Controllers/PaymentMethodController.php:43
 * @route '/payment-methods/{paymentMethod}/toggle-status'
 */
toggleStatus.url = (args: { paymentMethod: number | { id: number } } | [paymentMethod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { paymentMethod: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { paymentMethod: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    paymentMethod: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        paymentMethod: typeof args.paymentMethod === 'object'
                ? args.paymentMethod.id
                : args.paymentMethod,
                }

    return toggleStatus.definition.url
            .replace('{paymentMethod}', parsedArgs.paymentMethod.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PaymentMethodController::toggleStatus
 * @see app/Http/Controllers/PaymentMethodController.php:43
 * @route '/payment-methods/{paymentMethod}/toggle-status'
 */
toggleStatus.patch = (args: { paymentMethod: number | { id: number } } | [paymentMethod: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})
const paymentMethods = {
    store: Object.assign(store, store),
update: Object.assign(update, update),
destroy: Object.assign(destroy, destroy),
toggleStatus: Object.assign(toggleStatus, toggleStatus),
}

export default paymentMethods