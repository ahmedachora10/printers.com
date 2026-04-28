<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->createPermissions();
        $this->createRoles();
    }

    private function createPermissions(): void
    {
        $permissions = [
            ['name' => 'manage-branches',          'display_name' => 'إدارة الفروع والمدن'],
            ['name' => 'manage-users',             'display_name' => 'إدارة المستخدمين'],
            ['name' => 'manage-service-templates', 'display_name' => 'إدارة قوالب الخدمات'],
            ['name' => 'manage-branch-services',   'display_name' => 'إدارة خدمات الفرع والشرائح'],
            ['name' => 'manage-product-categories','display_name' => 'إدارة فئات المنتجات والمصروفات'],
            ['name' => 'manage-coupons',           'display_name' => 'إدارة الكوبونات'],
            ['name' => 'create-service-invoice',   'display_name' => 'إنشاء فاتورة خدمات'],
            ['name' => 'create-product-invoice',   'display_name' => 'إنشاء فاتورة منتجات'],
            ['name' => 'process-refund',           'display_name' => 'معالجة المرتجعات'],
            ['name' => 'pay-commission',           'display_name' => 'صرف عمولات الموظفين'],
            ['name' => 'manage-incentive-plans',   'display_name' => 'إدارة خطط الحوافز'],
            ['name' => 'pay-bonuses',              'display_name' => 'صرف المكافآت'],
            ['name' => 'view-branch-report',       'display_name' => 'عرض تقرير الفرع'],
            ['name' => 'view-all-branches-report', 'display_name' => 'عرض تقارير جميع الفروع'],
            ['name' => 'view-own-commission',      'display_name' => 'عرض العمولة والحوافز الشخصية'],
            ['name' => 'manage-customers',         'display_name' => 'إدارة العملاء'],
            ['name' => 'manage-agents',            'display_name' => 'إدارة الوكلاء وصرف المكافآت'],
            ['name' => 'view-own-agent-data',      'display_name' => 'عرض بيانات الوكيل الشخصية'],
            ['name' => 'configure-loyalty',        'display_name' => 'إعداد برنامج الولاء'],
            ['name' => 'redeem-loyalty-points',    'display_name' => 'استبدال نقاط الولاء عند نقطة البيع'],
            ['name' => 'manage-inventory',         'display_name' => 'إدارة المخزون والموردين وطلبات الشراء'],
            ['name' => 'run-stock-reconciliation', 'display_name' => 'تسوية المخزون'],
            ['name' => 'manage-settings',          'display_name' => 'إدارة الإعدادات'],
        ];

        foreach ($permissions as $data) {
            Permission::firstOrCreate(['name' => $data['name']], ['display_name' => $data['display_name']]);
        }
    }

    private function createRoles(): void
    {
        $roles = [
            'super-admin' => [
                'display_name' => 'مدير عام',
                'description'  => 'صلاحيات كاملة على جميع الفروع',
                'permissions'  => [
                    'manage-branches',
                    'manage-users',
                    'manage-service-templates',
                    'manage-branch-services',
                    'manage-product-categories',
                    'manage-coupons',
                    'create-service-invoice',
                    'create-product-invoice',
                    'process-refund',
                    'pay-commission',
                    'manage-incentive-plans',
                    'pay-bonuses',
                    'view-branch-report',
                    'view-all-branches-report',
                    'view-own-commission',
                    'manage-customers',
                    'manage-agents',
                    'configure-loyalty',
                    'redeem-loyalty-points',
                    'manage-inventory',
                    'run-stock-reconciliation',
                    'manage-settings',
                ],
            ],

            'branch-admin' => [
                'display_name' => 'مدير فرع',
                'description'  => 'صلاحيات كاملة داخل الفرع',
                'permissions'  => [
                    'manage-users',
                    'manage-branch-services',
                    'manage-product-categories',
                    'manage-coupons',
                    'create-service-invoice',
                    'create-product-invoice',
                    'process-refund',
                    'pay-commission',
                    'manage-incentive-plans',
                    'pay-bonuses',
                    'view-branch-report',
                    'view-own-commission',
                    'manage-customers',
                    'manage-agents',
                    'configure-loyalty',
                    'redeem-loyalty-points',
                    'manage-inventory',
                    'run-stock-reconciliation',
                    'manage-settings',
                ],
            ],

            'accountant' => [
                'display_name' => 'محاسب',
                'description'  => 'فواتير المنتجات، المرتجعات، المصروفات، التقارير',
                'permissions'  => [
                    'manage-product-categories',
                    'create-product-invoice',
                    'process-refund',
                    'view-branch-report',
                    'manage-customers',
                    'redeem-loyalty-points',
                    'manage-inventory',
                ],
            ],

            'employee' => [
                'display_name' => 'موظف',
                'description'  => 'فواتير الخدمات، العمولة الشخصية، الحوافز',
                'permissions'  => [
                    'create-service-invoice',
                    'view-own-commission',
                    'manage-customers',
                    'redeem-loyalty-points',
                ],
            ],

            'agent' => [
                'display_name' => 'وكيل',
                'description'  => 'بوابة القراءة فقط للوكلاء',
                'permissions'  => [
                    'view-own-agent-data',
                ],
            ],
        ];

        foreach ($roles as $slug => $config) {
            $role = Role::firstOrCreate(
                ['name' => $slug],
                [
                    'display_name' => $config['display_name'],
                    'description'  => $config['description'],
                ]
            );

            $role->syncPermissions($config['permissions']);
        }
    }
}
