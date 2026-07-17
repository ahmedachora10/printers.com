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
                'impersonating' => $request->session()->has('impersonator_id')
                    ? ['active' => true, 'viewingName' => $request->user()?->name]
                    : null,
            ],
            'notifications' => fn () => $this->notifications($request),
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
        ]);
    }

    /**
     * Unread count + the most recent notifications for the header bell. Lazy so
     * `usePoll`/partial reloads can refresh only this key.
     *
     * @return array{unreadCount: int, items: array<int, array<string, mixed>>}
     */
    private function notifications(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return ['unreadCount' => 0, 'items' => []];
        }

        $items = $user->notifications()->latest()->limit(10)->get()->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->data['type'] ?? 'general',
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'url' => $n->data['url'] ?? null,
            'icon' => $n->data['icon'] ?? 'Bell',
            'isRead' => $n->read_at !== null,
            'timeAgo' => $n->created_at?->diffForHumans(),
        ])->all();

        return [
            'unreadCount' => $user->unreadNotifications()->count(),
            'items' => $items,
        ];
    }

    // sidebar items for each role
    /** @return array<int, array<string, mixed>> */
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
                'title' => 'بوابة الوكيل',
                'url' => route('agent-portal.index'),
                'icon' => 'Handshake',
                'role' => [Roles::AGENT],
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
                'title' => 'فئات المصروفات',
                'url' => route('expense-categories.index'),
                'icon' => 'FolderKanban',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'المصروفات',
                'url' => route('expenses.index'),
                'icon' => 'Receipt',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الكوبونات',
                'url' => route('coupons.index'),
                'icon' => 'Ticket',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'دليل الخدمات',
                'url' => route('admin.catalogue.categories.index'),
                'icon' => 'BookOpen',
                'role' => [Roles::SUPER_ADMIN],
            ],
            [
                'title' => 'العمولات',
                'url' => route('commissions.index'),
                'icon' => 'Wallet',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'تقرير العمولات',
                'url' => route('reports.commissions'),
                'icon' => 'FileText',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT, Roles::EMPLOYEE],
            ],
            [
                'title' => 'تقرير المبيعات',
                'url' => route('reports.sales'),
                'icon' => 'TrendingUp',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'التقرير اليومي',
                'url' => route('reports.daily'),
                'icon' => 'CalendarDays',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'التحليلات المتقدمة',
                'url' => route('analytics.index'),
                'icon' => 'ChartPie',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'برنامج الولاء',
                'url' => route('loyalty.index'),
                'icon' => 'Award',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'الحوافز والمكافآت',
                'url' => route('incentives.index'),
                'icon' => 'Trophy',
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
                'title' => 'مراجعة الفواتير الآجلة',
                'url' => route('invoices.service.review'),
                'icon' => 'ClipboardList',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'العملاء',
                'url' => route('customers.index'),
                'icon' => 'User',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الوكلاء',
                'url' => route('agents.index'),
                'icon' => 'Handshake',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'مدفوعات الوكلاء',
                'url' => route('agent-payments.index'),
                'icon' => 'Wallet',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'المرتجعات',
                'url' => route('refunds.index'),
                'icon' => 'Undo2',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'المنتجات',
                'url' => route('inventory.products.index'),
                'icon' => 'Package',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'تحركات المخزون',
                'url' => route('inventory.stock-movements.index'),
                'icon' => 'ArrowLeftRight',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الموردون',
                'url' => route('inventory.suppliers.index'),
                'icon' => 'Truck',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'أوامر الشراء',
                'url' => route('inventory.purchase-orders.index'),
                'icon' => 'ClipboardList',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'جرد المخزون',
                'url' => route('inventory.stock-reconciliations.index'),
                'icon' => 'ClipboardCheck',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الإشعارات',
                'url' => route('notifications.index'),
                'icon' => 'Bell',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT, Roles::EMPLOYEE, Roles::AGENT],
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
