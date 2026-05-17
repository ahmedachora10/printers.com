import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
export const outstandingBalance = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: outstandingBalance.url(options),
    method: 'get',
})

outstandingBalance.definition = {
    methods: ["get","head"],
    url: '/customers/outstanding-balance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
outstandingBalance.url = (options?: RouteQueryOptions) => {
    return outstandingBalance.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
outstandingBalance.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: outstandingBalance.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
outstandingBalance.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: outstandingBalance.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
    const outstandingBalanceForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: outstandingBalance.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
        outstandingBalanceForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: outstandingBalance.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\CustomerController::outstandingBalance
 * @see app/Http/Controllers/CustomerController.php:171
 * @route '/customers/outstanding-balance'
 */
        outstandingBalanceForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: outstandingBalance.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    outstandingBalance.form = outstandingBalanceForm
/**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
export const exportMethod = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: exportMethod.url(options),
    method: 'get',
})

exportMethod.definition = {
    methods: ["get","head"],
    url: '/customers/export',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
exportMethod.url = (options?: RouteQueryOptions) => {
    return exportMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
exportMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: exportMethod.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
exportMethod.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: exportMethod.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
    const exportMethodForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: exportMethod.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
        exportMethodForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: exportMethod.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\CustomerController::exportMethod
 * @see app/Http/Controllers/CustomerController.php:184
 * @route '/customers/export'
 */
        exportMethodForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: exportMethod.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    exportMethod.form = exportMethodForm
/**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/customers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\CustomerController::index
 * @see app/Http/Controllers/CustomerController.php:32
 * @route '/customers'
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
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/customers/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
 */
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
 */
    const createForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: create.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
 */
        createForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: create.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\CustomerController::create
 * @see app/Http/Controllers/CustomerController.php:81
 * @route '/customers/create'
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
* @see \App\Http\Controllers\CustomerController::store
 * @see app/Http/Controllers/CustomerController.php:88
 * @route '/customers'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/customers',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\CustomerController::store
 * @see app/Http/Controllers/CustomerController.php:88
 * @route '/customers'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::store
 * @see app/Http/Controllers/CustomerController.php:88
 * @route '/customers'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\CustomerController::store
 * @see app/Http/Controllers/CustomerController.php:88
 * @route '/customers'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\CustomerController::store
 * @see app/Http/Controllers/CustomerController.php:88
 * @route '/customers'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
export const show = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/customers/{customer}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
show.url = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { customer: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { customer: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    customer: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        customer: typeof args.customer === 'object'
                ? args.customer.id
                : args.customer,
                }

    return show.definition.url
            .replace('{customer}', parsedArgs.customer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
show.get = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
show.head = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
    const showForm = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
        showForm.get = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\CustomerController::show
 * @see app/Http/Controllers/CustomerController.php:102
 * @route '/customers/{customer}'
 */
        showForm.head = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
export const edit = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/customers/{customer}/edit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
edit.url = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { customer: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { customer: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    customer: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        customer: typeof args.customer === 'object'
                ? args.customer.id
                : args.customer,
                }

    return edit.definition.url
            .replace('{customer}', parsedArgs.customer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
edit.get = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
edit.head = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
    const editForm = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: edit.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
        editForm.get = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\CustomerController::edit
 * @see app/Http/Controllers/CustomerController.php:125
 * @route '/customers/{customer}/edit'
 */
        editForm.head = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    edit.form = editForm
/**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
export const update = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put","patch"],
    url: '/customers/{customer}',
} satisfies RouteDefinition<["put","patch"]>

/**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
update.url = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { customer: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { customer: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    customer: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        customer: typeof args.customer === 'object'
                ? args.customer.id
                : args.customer,
                }

    return update.definition.url
            .replace('{customer}', parsedArgs.customer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
update.put = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})
/**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
update.patch = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
    const updateForm = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
        updateForm.put = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \App\Http\Controllers\CustomerController::update
 * @see app/Http/Controllers/CustomerController.php:134
 * @route '/customers/{customer}'
 */
        updateForm.patch = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\CustomerController::destroy
 * @see app/Http/Controllers/CustomerController.php:146
 * @route '/customers/{customer}'
 */
export const destroy = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/customers/{customer}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\CustomerController::destroy
 * @see app/Http/Controllers/CustomerController.php:146
 * @route '/customers/{customer}'
 */
destroy.url = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { customer: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { customer: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    customer: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        customer: typeof args.customer === 'object'
                ? args.customer.id
                : args.customer,
                }

    return destroy.definition.url
            .replace('{customer}', parsedArgs.customer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::destroy
 * @see app/Http/Controllers/CustomerController.php:146
 * @route '/customers/{customer}'
 */
destroy.delete = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\CustomerController::destroy
 * @see app/Http/Controllers/CustomerController.php:146
 * @route '/customers/{customer}'
 */
    const destroyForm = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\CustomerController::destroy
 * @see app/Http/Controllers/CustomerController.php:146
 * @route '/customers/{customer}'
 */
        destroyForm.delete = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\CustomerController::merge
 * @see app/Http/Controllers/CustomerController.php:155
 * @route '/customers/{customer}/merge'
 */
export const merge = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: merge.url(args, options),
    method: 'post',
})

merge.definition = {
    methods: ["post"],
    url: '/customers/{customer}/merge',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\CustomerController::merge
 * @see app/Http/Controllers/CustomerController.php:155
 * @route '/customers/{customer}/merge'
 */
merge.url = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { customer: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { customer: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    customer: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        customer: typeof args.customer === 'object'
                ? args.customer.id
                : args.customer,
                }

    return merge.definition.url
            .replace('{customer}', parsedArgs.customer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::merge
 * @see app/Http/Controllers/CustomerController.php:155
 * @route '/customers/{customer}/merge'
 */
merge.post = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: merge.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\CustomerController::merge
 * @see app/Http/Controllers/CustomerController.php:155
 * @route '/customers/{customer}/merge'
 */
    const mergeForm = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: merge.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\CustomerController::merge
 * @see app/Http/Controllers/CustomerController.php:155
 * @route '/customers/{customer}/merge'
 */
        mergeForm.post = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: merge.url(args, options),
            method: 'post',
        })
    
    merge.form = mergeForm
/**
* @see \App\Http\Controllers\CustomerController::toggleStatus
 * @see app/Http/Controllers/CustomerController.php:193
 * @route '/customers/{customer}/toggle-status'
 */
export const toggleStatus = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

toggleStatus.definition = {
    methods: ["patch"],
    url: '/customers/{customer}/toggle-status',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\CustomerController::toggleStatus
 * @see app/Http/Controllers/CustomerController.php:193
 * @route '/customers/{customer}/toggle-status'
 */
toggleStatus.url = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { customer: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { customer: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    customer: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        customer: typeof args.customer === 'object'
                ? args.customer.id
                : args.customer,
                }

    return toggleStatus.definition.url
            .replace('{customer}', parsedArgs.customer.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\CustomerController::toggleStatus
 * @see app/Http/Controllers/CustomerController.php:193
 * @route '/customers/{customer}/toggle-status'
 */
toggleStatus.patch = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: toggleStatus.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\CustomerController::toggleStatus
 * @see app/Http/Controllers/CustomerController.php:193
 * @route '/customers/{customer}/toggle-status'
 */
    const toggleStatusForm = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: toggleStatus.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\CustomerController::toggleStatus
 * @see app/Http/Controllers/CustomerController.php:193
 * @route '/customers/{customer}/toggle-status'
 */
        toggleStatusForm.patch = (args: { customer: number | { id: number } } | [customer: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: toggleStatus.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    toggleStatus.form = toggleStatusForm
const customers = {
    outstandingBalance: Object.assign(outstandingBalance, outstandingBalance),
export: Object.assign(exportMethod, exportMethod),
index: Object.assign(index, index),
create: Object.assign(create, create),
store: Object.assign(store, store),
show: Object.assign(show, show),
edit: Object.assign(edit, edit),
update: Object.assign(update, update),
destroy: Object.assign(destroy, destroy),
merge: Object.assign(merge, merge),
toggleStatus: Object.assign(toggleStatus, toggleStatus),
}

export default customers