<?php

namespace App\Http\Controllers;

use App\Actions\Customer\BuildCustomerAnalyticsAction;
use App\Actions\Customer\BuildCustomerTimelineAction;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Customer;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM & customer analytics (M23): unified activity timeline plus purchase
 * analytics for a single customer. Same audience/scoping as the customer
 * profile — anyone who can view the customer can view their activity.
 *
 * Timeline and analytics are deferred props in separate groups: the page
 * shell renders immediately and Inertia fetches both in parallel follow-up
 * requests, so neither block the initial navigation.
 */
class CustomerActivityController extends Controller
{
    public function show(
        Customer $customer,
        BuildCustomerTimelineAction $timeline,
        BuildCustomerAnalyticsAction $analytics,
    ): Response {
        Gate::authorize('view', $customer);

        return Inertia::render('customers/activity', [
            'customer' => new CustomerResource($customer),
            'timeline' => Inertia::defer(function () use ($customer, $timeline) {
                $limit = BuildCustomerTimelineAction::PER_SOURCE_LIMIT;

                $customer->load([
                    'productInvoices' => fn ($query) => $query->latest()->limit($limit),
                    'serviceInvoices' => fn ($query) => $query->latest()->limit($limit),
                    'activities' => fn ($query) => $query->with('causer')->latest()->limit($limit),
                    'loyaltyTransactions' => fn ($query) => $query->latest()->limit($limit),
                ]);

                return $timeline->handle($customer);
            }, 'timeline'),
            'analytics' => Inertia::defer(fn () => $analytics->handle($customer), 'analytics'),
        ]);
    }
}
