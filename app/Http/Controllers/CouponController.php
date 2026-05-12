<?php

namespace App\Http\Controllers;

use App\Actions\Coupon\CreateCouponAction;
use App\Actions\Coupon\DeleteCouponAction;
use App\Actions\Coupon\UpdateCouponAction;
use App\Http\Requests\Coupon\StoreCouponRequest;
use App\Http\Requests\Coupon\UpdateCouponRequest;
use App\Http\Resources\Coupon\CouponResource;
use App\Models\Branch;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Coupon::class);

        $isSuperAdmin = auth()->user()->roleName->isSuperAdmin();

        $items = Coupon::query()
            ->when(! $isSuperAdmin, fn ($q) => $q->where('branch_id', auth()->user()->branchManager->id))
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%' . $request->input('search') . '%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->latest()
            ->paginate(15);

        return Inertia::render('coupons/index', [
            'items'    => CouponResource::collection($items),
            'filters'  => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
            'branches' => $isSuperAdmin
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
        ]);
    }

    public function store(StoreCouponRequest $request, CreateCouponAction $action): RedirectResponse
    {
        Gate::authorize('create', Coupon::class);

        $action->handle($request->validated());

        return to_route('coupons.index')->with('success', 'تم إنشاء الكوبون بنجاح');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon, UpdateCouponAction $action): RedirectResponse
    {
        Gate::authorize('update', $coupon);

        $action->handle($coupon, $request->validated());

        return to_route('coupons.index')->with('success', 'تم تحديث الكوبون بنجاح');
    }

    public function destroy(Coupon $coupon, DeleteCouponAction $action): RedirectResponse
    {
        Gate::authorize('delete', $coupon);

        $action->handle($coupon);

        return to_route('coupons.index')->with('success', 'تم حذف الكوبون بنجاح');
    }

    public function toggleStatus(Coupon $coupon, UpdateCouponAction $action): RedirectResponse
    {
        Gate::authorize('update', $coupon);

        $action->handle($coupon, ['is_active' => ! $coupon->is_active]);

        return to_route('coupons.index')->with('success', 'تم تحديث حالة الكوبون بنجاح');
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $code   = strtolower($request->string('code'));
        $coupon = Coupon::query()
            ->where('branch_id', auth()->user()->branchManager->id)
            ->where('code', $code)
            ->first();

        if (! $coupon || ! $coupon->is_active) {
            return response()->json(['valid' => false]);
        }

        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            return response()->json(['valid' => false]);
        }

        if ($coupon->capacity !== null && $coupon->used_count >= $coupon->capacity) {
            return response()->json(['valid' => false]);
        }

        return response()->json([
            'valid'              => true,
            'type'               => $coupon->discount_type->value,
            'value'              => $coupon->discount_value,
            'remaining_capacity' => $coupon->capacity !== null
                ? $coupon->capacity - $coupon->used_count
                : null,
        ]);
    }
}
