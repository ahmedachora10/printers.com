import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/app-settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\AppSettingController::index
 * @see app/Http/Controllers/AppSettingController.php:22
 * @route '/app-settings'
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
* @see \App\Http\Controllers\AppSettingController::updateGeneral
 * @see app/Http/Controllers/AppSettingController.php:51
 * @route '/app-settings/general'
 */
export const updateGeneral = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateGeneral.url(options),
    method: 'put',
})

updateGeneral.definition = {
    methods: ["put"],
    url: '/app-settings/general',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\AppSettingController::updateGeneral
 * @see app/Http/Controllers/AppSettingController.php:51
 * @route '/app-settings/general'
 */
updateGeneral.url = (options?: RouteQueryOptions) => {
    return updateGeneral.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppSettingController::updateGeneral
 * @see app/Http/Controllers/AppSettingController.php:51
 * @route '/app-settings/general'
 */
updateGeneral.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateGeneral.url(options),
    method: 'put',
})

    /**
* @see \App\Http\Controllers\AppSettingController::updateGeneral
 * @see app/Http/Controllers/AppSettingController.php:51
 * @route '/app-settings/general'
 */
    const updateGeneralForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: updateGeneral.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\AppSettingController::updateGeneral
 * @see app/Http/Controllers/AppSettingController.php:51
 * @route '/app-settings/general'
 */
        updateGeneralForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: updateGeneral.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    updateGeneral.form = updateGeneralForm
/**
* @see \App\Http\Controllers\AppSettingController::updateInventoryAlerts
 * @see app/Http/Controllers/AppSettingController.php:60
 * @route '/app-settings/inventory-alerts'
 */
export const updateInventoryAlerts = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateInventoryAlerts.url(options),
    method: 'put',
})

updateInventoryAlerts.definition = {
    methods: ["put"],
    url: '/app-settings/inventory-alerts',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\AppSettingController::updateInventoryAlerts
 * @see app/Http/Controllers/AppSettingController.php:60
 * @route '/app-settings/inventory-alerts'
 */
updateInventoryAlerts.url = (options?: RouteQueryOptions) => {
    return updateInventoryAlerts.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppSettingController::updateInventoryAlerts
 * @see app/Http/Controllers/AppSettingController.php:60
 * @route '/app-settings/inventory-alerts'
 */
updateInventoryAlerts.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateInventoryAlerts.url(options),
    method: 'put',
})

    /**
* @see \App\Http\Controllers\AppSettingController::updateInventoryAlerts
 * @see app/Http/Controllers/AppSettingController.php:60
 * @route '/app-settings/inventory-alerts'
 */
    const updateInventoryAlertsForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: updateInventoryAlerts.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\AppSettingController::updateInventoryAlerts
 * @see app/Http/Controllers/AppSettingController.php:60
 * @route '/app-settings/inventory-alerts'
 */
        updateInventoryAlertsForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: updateInventoryAlerts.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    updateInventoryAlerts.form = updateInventoryAlertsForm
/**
* @see \App\Http\Controllers\AppSettingController::updatePaymentMethods
 * @see app/Http/Controllers/AppSettingController.php:69
 * @route '/app-settings/payment-methods'
 */
export const updatePaymentMethods = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updatePaymentMethods.url(options),
    method: 'put',
})

updatePaymentMethods.definition = {
    methods: ["put"],
    url: '/app-settings/payment-methods',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\AppSettingController::updatePaymentMethods
 * @see app/Http/Controllers/AppSettingController.php:69
 * @route '/app-settings/payment-methods'
 */
updatePaymentMethods.url = (options?: RouteQueryOptions) => {
    return updatePaymentMethods.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppSettingController::updatePaymentMethods
 * @see app/Http/Controllers/AppSettingController.php:69
 * @route '/app-settings/payment-methods'
 */
updatePaymentMethods.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updatePaymentMethods.url(options),
    method: 'put',
})

    /**
* @see \App\Http\Controllers\AppSettingController::updatePaymentMethods
 * @see app/Http/Controllers/AppSettingController.php:69
 * @route '/app-settings/payment-methods'
 */
    const updatePaymentMethodsForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: updatePaymentMethods.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\AppSettingController::updatePaymentMethods
 * @see app/Http/Controllers/AppSettingController.php:69
 * @route '/app-settings/payment-methods'
 */
        updatePaymentMethodsForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: updatePaymentMethods.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    updatePaymentMethods.form = updatePaymentMethodsForm
const appSettings = {
    index: Object.assign(index, index),
updateGeneral: Object.assign(updateGeneral, updateGeneral),
updateInventoryAlerts: Object.assign(updateInventoryAlerts, updateInventoryAlerts),
updatePaymentMethods: Object.assign(updatePaymentMethods, updatePaymentMethods),
}

export default appSettings