<?php

namespace App\Http\Middleware;

use App\Enums\Roles;
use App\Models\Branch;
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
                'branch' => $this->branch($request),
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
     * The branch this user works out of, so the app can wear its identity
     * instead of the generic one. Null for a super-admin, who belongs to no
     * single branch, and for a branch that never uploaded a logo.
     *
     * @return array{name: string, logoUrl: string|null}|null
     */
    private function branch(Request $request): ?array
    {
        // branchId resolves a branch-admin through branches.owner_id, so this
        // covers managers as well as ordinary branch staff.
        $branchId = $request->user()?->branchId;

        if ($branchId === null) {
            return null;
        }

        $branch = Branch::with('media')->find($branchId);

        if ($branch === null) {
            return null;
        }

        return [
            'name' => $branch->name,
            'logoUrl' => $branch->getFirstMediaUrl('logo') ?: null,
        ];
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
    /** @return array<int, array{label: string|null, icon: string|null, items: array<int, array<string, mixed>>}> */
    private function getSidebarItems(Request $request): array
    {

        $userRole = $request->user()?->roleName;

        if (! $userRole) {
            return [];
        }

        // Ordered group definitions. Key => [label, parent icon]. 'main' has a
        // null label and renders flat at the top (no dropdown); every other
        // group renders as a collapsible dropdown whose header carries the icon.
        // Items are bucketed into these in this order; any group with no visible
        // items (after role filtering) is dropped.
        $groups = [
            'main' => ['label' => null, 'icon' => null],
            'sales' => ['label' => 'المبيعات', 'icon' => 'ShoppingCart'],
            'crm' => ['label' => 'العملاء والوكلاء', 'icon' => 'Users'],
            'inventory' => ['label' => 'المخزون', 'icon' => 'Package'],
            'finance' => ['label' => 'المالية', 'icon' => 'Wallet'],
            'reports' => ['label' => 'التقارير', 'icon' => 'ChartPie'],
            'admin' => ['label' => 'الإدارة', 'icon' => 'Settings'],
        ];

        $items = [
            [
                'title' => 'لوحة التحكم',
                'url' => route('dashboard'),
                'icon' => 'LayoutGrid',
                'group' => 'main',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'بوابة الوكيل',
                'url' => route('agent-portal.index'),
                'icon' => 'Handshake',
                'group' => 'main',
                'role' => [Roles::AGENT],
            ],
            // ---- Sales & Invoices ----
            [
                'title' => 'نقطة البيع',
                'url' => route('pos.product.create'),
                'icon' => 'ShoppingCart',
                'group' => 'sales',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'فاتورة خدمة',
                'url' => route('pos.service.create'),
                'icon' => 'ShoppingCart',
                'group' => 'sales',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::EMPLOYEE],
            ],
            [
                'title' => 'الفواتير',
                'url' => route('invoices.index'),
                'icon' => 'FileText',
                'group' => 'sales',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT, Roles::EMPLOYEE],
            ],
            [
                'title' => 'عروض الاسعار',
                'url' => route('invoices.service.review'),
                'icon' => 'ClipboardList',
                'group' => 'sales',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'المرتجعات',
                'url' => route('refunds.index'),
                'icon' => 'Undo2',
                'group' => 'sales',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            // ---- Customers & Agents ----
            [
                'title' => 'العملاء',
                'url' => route('customers.index'),
                'icon' => 'User',
                'group' => 'crm',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الوكلاء',
                'url' => route('agents.index'),
                'icon' => 'Handshake',
                'group' => 'crm',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'مدفوعات الوكلاء',
                'url' => route('agent-payments.index'),
                'icon' => 'Wallet',
                'group' => 'crm',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'برنامج الولاء',
                'url' => route('loyalty.index'),
                'icon' => 'Award',
                'group' => 'crm',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            // ---- Inventory & Procurement ----
            [
                'title' => 'المنتجات',
                'url' => route('inventory.products.index'),
                'icon' => 'Package',
                'group' => 'inventory',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'فئات المنتجات',
                'url' => route('product-categories.index'),
                'icon' => 'FolderKanban',
                'group' => 'inventory',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'تحركات المخزون',
                'url' => route('inventory.stock-movements.index'),
                'icon' => 'ArrowLeftRight',
                'group' => 'inventory',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'الموردون',
                'url' => route('inventory.suppliers.index'),
                'icon' => 'Truck',
                'group' => 'inventory',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'أوامر الشراء',
                'url' => route('inventory.purchase-orders.index'),
                'icon' => 'ClipboardList',
                'group' => 'inventory',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'جرد المخزون',
                'url' => route('inventory.stock-reconciliations.index'),
                'icon' => 'ClipboardCheck',
                'group' => 'inventory',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            // ---- Finance & Commissions ----
            [
                'title' => 'العمولات',
                'url' => route('commissions.index'),
                'icon' => 'Wallet',
                'group' => 'finance',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'المصروفات',
                'url' => route('expenses.index'),
                'icon' => 'Receipt',
                'group' => 'finance',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'فئات المصروفات',
                'url' => route('expense-categories.index'),
                'icon' => 'FolderKanban',
                'group' => 'finance',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'الكوبونات',
                'url' => route('coupons.index'),
                'icon' => 'Ticket',
                'group' => 'finance',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'الحوافز والمكافآت',
                'url' => route('incentives.index'),
                'icon' => 'Trophy',
                'group' => 'finance',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            // ---- Reports & Analytics ----
            [
                'title' => 'تقرير المبيعات',
                'url' => route('reports.sales'),
                'icon' => 'TrendingUp',
                'group' => 'reports',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'تقرير العمولات',
                'url' => route('reports.commissions'),
                'icon' => 'FileText',
                'group' => 'reports',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT, Roles::EMPLOYEE],
            ],
            [
                'title' => 'التقرير اليومي',
                'url' => route('reports.daily'),
                'icon' => 'CalendarDays',
                'group' => 'reports',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            [
                'title' => 'التحليلات المتقدمة',
                'url' => route('analytics.index'),
                'icon' => 'ChartPie',
                'group' => 'reports',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT],
            ],
            // ---- Admin & Setup ----
            [
                'title' => 'المدن',
                'url' => route('cities.index'),
                'icon' => 'LayoutGrid',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN],
            ],
            [
                'title' => 'الفروع',
                'url' => route('branches.index'),
                'icon' => 'GitBranch',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN],
            ],
            [
                'title' => 'المستخدمون',
                'url' => route('users.index'),
                'icon' => 'Users',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'الخدمات',
                'url' => $userRole->isSuperAdmin() ? route('service-templates.index') : route('branch-services.index'),
                'icon' => 'ServerIcon',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
            [
                'title' => 'دليل الخدمات',
                'url' => route('admin.catalogue.categories.index'),
                'icon' => 'BookOpen',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN],
            ],
            [
                'title' => 'الإشعارات',
                'url' => route('notifications.index'),
                'icon' => 'Bell',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN, Roles::ACCOUNTANT, Roles::EMPLOYEE, Roles::AGENT],
            ],
            [
                'title' => 'الاعدادات',
                'url' => route('app-settings.index'),
                'icon' => 'Settings',
                'group' => 'admin',
                'role' => [Roles::SUPER_ADMIN, Roles::BRANCH_ADMIN],
            ],
        ];

        // Keep only items visible to this role, stripped of the internal keys.
        $visible = array_map(
            fn ($item) => array_diff_key($item, ['role' => null, 'group' => null]) + ['group' => $item['group']],
            array_filter($items, fn ($item) => in_array($userRole, $item['role']))
        );

        // Bucket into the defined group order, dropping empty groups.
        $result = [];
        foreach ($groups as $key => $group) {
            $groupItems = array_values(array_map(
                fn ($item) => array_diff_key($item, ['group' => null]),
                array_filter($visible, fn ($item) => $item['group'] === $key)
            ));

            if ($groupItems !== []) {
                $result[] = [
                    'label' => $group['label'],
                    'icon' => $group['icon'],
                    'items' => $groupItems,
                ];
            }
        }

        return $result;
    }
}
