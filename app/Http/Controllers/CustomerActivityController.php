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
 */
class CustomerActivityController extends Controller
{
    public function show(
        Customer $customer,
        BuildCustomerTimelineAction $timeline,
        BuildCustomerAnalyticsAction $analytics,
    ): Response {
        Gate::authorize('view', $customer);

        $customer->load(['branch', 'agent']);

        return Inertia::render('customers/activity', [
            'customer' => new CustomerResource($customer),
            'timeline' => $timeline->handle($customer),
            'analytics' => $analytics->handle($customer),
        ]);
    }
}
