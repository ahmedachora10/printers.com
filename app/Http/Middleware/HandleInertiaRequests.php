<?php

namespace App\Http\Middleware;

use App\Enums\Roles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                'role' => $request->user()?->roleName,
                'sidebarItems' => $this->getSidebarItems($request),
            ],
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
        ]);
    }

    // sidebar items for each role
    private function getSidebarItems(Request $request): array
    {

        $userRole = $request->user()?->roleName;

        if (! $userRole) {
            return [];
        }

        $items = [
            [
                'title' => 'لوحة التحكم',
                'url' => route('dashboard'),
                'icon' => 'LayoutGrid',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'المدن',
                'url' => route('cities.index'),
                'icon' => 'LayoutGrid',
                'role' => [Roles::SUPER_ADMIN],
            ],
            [
                'title' => 'الفروع',
                'url' => route('branches.index'),
                'icon' => 'GitBranch',
                'role' => [Roles::SUPER_ADMIN],
            ],
            [
                'title' => 'المستخدمون',
                'url' => route('users.index'),
                'icon' => 'Users',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'الخدمات',
                'url' => $userRole->isSuperAdmin() ? route('service-templates.index') : route('branch-services.index'),
                'icon' => 'ServerIcon',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'فئات المنتجات',
                'url' => route('product-categories.index'),
                'icon' => 'FolderKanban',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'الكوبونات',
                'url' => route('coupons.index'),
                'icon' => 'Ticket',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'العمولات',
                'url' => route('commissions.index'),
                'icon' => 'Wallet',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'نقطة البيع',
                'url' => route('pos.product.create'),
                'icon' => 'ShoppingCart',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'فاتورة خدمة',
                'url' => route('pos.service.create'),
                'icon' => 'ShoppingCart',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::EMPLOYEE],
            ],
            [
                'title' => 'الفواتير',
                'url' => route('invoices.index'),
                'icon' => 'FileText',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT, Roles::EMPLOYEE],
            ],
            [
                'title' => 'العملاء',
                'url' => route('customers.index'),
                'icon' => 'User',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'المنتجات',
                'url' => route('inventory.products.index'),
                'icon' => 'Package',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'تحركات المخزون',
                'url' => route('inventory.stock-movements.index'),
                'icon' => 'ArrowLeftRight',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الاعدادات',
                'url' => route('app-settings.index'),
                'icon' => 'Settings',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
        ];

        // return array_values(array_filter($items, fn($item) => in_array($request->user()?->roleName, $item['role'])));
        return array_values(array_map(
            fn ($item) => array_diff_key($item, ['role' => null]),
            array_filter($items, fn ($item) => in_array($userRole, $item['role']))
        ));
    }
}
