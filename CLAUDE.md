# Alnaasik Print Center — Agent Context

مركز الناسخ للطباعة — Internal Management System for a multi-branch Saudi print center.

---

## Project Identity

| Key | Value |
|---|---|
| **Product** | مركز الناسخ للطباعة — Internal Management System |
| **Business** | Multi-branch print center, Saudi Arabia |
| **Primary language** | Arabic (RTL). `dir="rtl"` on `<html>`. All UI text in `lang/ar/*.php` |
| **Currency** | SAR — formatted `1,234.50 ر.س`. Always `DECIMAL(12,2)`, never float |
| **VAT** | 15% default, configurable per branch |
| **Date display** | `DD/MM/YYYY`. Store as `YYYY-MM-DD` |
| **Scope** | 30 modules: auth, POS, commissions, inventory, loyalty, B2B agents, incentives |

---

## Tech Stack

### Backend
| Package | Version | Notes |
|---|---|---|
| Laravel | ^12.0 | PHP 8.3, PSR-12 |
| Inertia Laravel | ^2.0 | Server-side Inertia v2 |
| `santigarcor/laratrust` | `*` | **Roles & permissions** (NOT Spatie Permission). Use `hasRole()`, `hasPermission()`, `HasRolesAndPermissions` trait |
| `spatie/laravel-medialibrary` | `*` | File/image uploads. `HasMedia` + `InteractsWithMedia` |
| `spatie/laravel-activitylog` | `4.12.3` | Activity logging (v5 requires PHP 8.4). Use `LogsActivity` trait + `getActivitylogOptions()` |
| `maatwebsite/excel` | `3.1.68` | Excel exports/imports. `Excel::download()`, implement `FromCollection` + `WithHeadings` |
| `tightenco/ziggy` | ^2.4 | Named routes in JS via `route()` |
| Pest | ^3.8 | Testing. Feature tests in `tests/Feature/{Module}/` |

### Frontend
| Package | Version | Notes |
|---|---|---|
| React | ^19.0 | TypeScript. Pages in `resources/js/pages/` |
| Inertia React | ^2.0 | `useForm`, `router`, `<Link>` |
| Tailwind CSS | ^4.0 | RTL configured |
| shadcn/ui | — | Radix UI primitives. Components in `resources/js/components/ui/` |
| Lucide React | ^0.475 | Icons |
| Recharts | — | Charts (not yet installed — run `npm install recharts`) |

### Infrastructure
| Item | Detail |
|---|---|
| Database | MySQL 8.0+ (target prod). SQLite for local dev |
| Queue | Laravel Queue (database driver) |
| Storage | Laravel Storage — all files in **private storage**, never `public/` |

---

## Common Commands

```bash
# Start full dev server (Laravel + queue + Vite)
composer dev

# Run migrations
php artisan migrate

# Run tests
php artisan test
php artisan test --filter=ModuleName

# Code style
./vendor/bin/pint

# Install Recharts (still needed)
npm install recharts

# Publish activity log config + migration
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"

# Publish Excel config
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

---

## Architecture Patterns

### Request Lifecycle
```
Route → Controller → Action → Model → Response (Inertia / JSON)
```

- **Controllers** — thin: authorize, call Action, return Inertia response or redirect. No business logic.
- **Actions** (`app/Actions/{Resource}/`) — single-responsibility `handle()`. Wrap DB writes in `DB::transaction()`.
- **Form Requests** (`app/Http/Requests/{Resource}/`) — all validation here, never in controllers.
- **API Resources** (`app/Http/Resources/{Resource}/`) — shape props passed to Inertia pages. Use camelCase JSON keys.
- **Policies** (`app/Policies/`) — authorization. Registered in `AppServiceProvider::boot()` via `Gate::policy()`.

### Controller Example
```php
public function store(StoreProductCategoryRequest $request, CreateProductCategoryAction $action): RedirectResponse
{
    Gate::authorize('create', ProductCategory::class);
    $action->handle($request->validated());
    return to_route('product-categories.index');
}
```

### Action Example
```php
class CreateProductCategoryAction
{
    public function handle(array $data): ProductCategory
    {
        return DB::transaction(fn () => ProductCategory::create([
            ...$data,
            'user_id' => auth()->id(),
        ]));
    }
}
```

### Policy Registration (AppServiceProvider — no separate AuthServiceProvider)
```php
public function boot(): void
{
    Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
}
```

### Laratrust Authorization
```php
// Check in controller
Gate::authorize('create', ProductCategory::class); // via Policy

// Role checks (Laratrust)
$user->hasRole('super-admin');
$user->hasPermission('manage-branches');

// Middleware on routes
Route::middleware(['auth', 'role:super-admin'])->group(...);
Route::middleware(['auth', 'permission:create-invoice'])->group(...);
```

### Inertia Pages
- Path: `resources/js/pages/{module}/index.tsx`, `create.tsx`, `edit.tsx`
- Props typed with interfaces from `resources/js/types/{resource}.ts`
- Always use `usePage<SharedData>()` for auth user

### Excel Export Pattern
```php
// Export class
class ProductsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function collection(): Collection { return Product::all(); }
    public function headings(): array { return ['SKU', 'Name', 'Stock']; }
}

// Controller
return Excel::download(new ProductsExport(), 'products.xlsx');
```

### Activity Logging Pattern
```php
class Product extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('inventory');
    }
}
```

### Spatie Media Library Pattern
```php
class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->singleFile();
    }
}

// Upload
$product->addMediaFromRequest('image')->toMediaCollection('images');

// In API Resource
'imageUrl' => $this->getFirstMediaUrl('images'),
```

---

## Roles & Permissions (Laratrust)

| Role | Scope | Key permissions |
|---|---|---|
| `super-admin` | All branches | Full access everywhere |
| `branch-admin` | Own branch | Full access within branch |
| `accountant` | Own branch | Product invoices, refunds, expenses, reports |
| `employee` | Own branch | Service invoices, own commission, own incentive |
| `agent` | Own data only | Read-only agent portal only |

Permissions are named kebab-case: `manage-branches`, `create-service-invoice`, `create-product-invoice`, `process-refund`, `pay-commission`, `manage-inventory`, etc.

---

## Critical Business Rules

### Money & Transactions
- **ALL** money-affecting operations must be wrapped in `DB::transaction()`: invoice save, commission ledger write, points earn/redeem, coupon counter, stock movements, payment records.
- Failure at any step → full rollback.
- All monetary/percentage columns: `DECIMAL(12,2)` or `DECIMAL(5,2)`. **No PHP floats for money.**

### Immutable Tables
These three tables must **never be UPDATE'd or DELETE'd** after insert:
- `stock_movements` — only inserts, never corrected; use adjustment entries
- `commission_ledger` — immutable; reversals = new negative row with reason
- `loyalty_transactions` — immutable

### Soft Deletes
All primary entities use `SoftDeletes`. Cannot hard-delete records with associated invoices.

### Invoice Numbers
- Product invoices: `INV-{BRANCH_CODE}-{SEQ}`
- Service invoices: `SINV-{BRANCH_CODE}-{SEQ}`
- Purchase orders: `PO-{BRANCH_CODE}-{SEQ}`

### Stock Logic
`current_stock` on `products` is **computed** (`SUM(stock_movements.qty)`) — never manually updated. Mark the column as read-only in code.

### Loyalty Points
- Earned only on **Paid** invoices for **individual** customers (not corporate, not agent-linked).
- Due invoices: points credited only when status changes to Paid.
- `earned_points = FLOOR(total_amount × earning_rate)` — use `FLOOR()`.
- Tier computed from cumulative all-time spend; **never downgrades** (BranchAdmin manual override only).

### Commission Ledger
One row per service invoice line: `{user_id, branch_id, invoice_line_id, amount, is_tahazir, tier_applied, earned_at, paid_at}`.

### Agent Modes
- `discount` mode: invoice-level discount pre-filled with agent rate %; stored on invoice.
- `rebate` mode: no auto-discount; `rebate = total_amount × rate/100` stored on invoice.

---

## Module Map (M01–M30)

| # | Module | Key tables | Route prefix |
|---|---|---|---|
| M01 | Authentication | `users` | `/login`, `/logout` |
| M02 | Dashboard | aggregates | `/dashboard` |
| M03 | Branch Management | `branches`, `cities` | `/branches` |
| M04 | City Management | `cities` | `/cities` |
| M05 | User Management | `users`, `user_services` | `/users` |
| M06 | Settings | `settings`, `payment_methods` | `/settings` |
| M07 | Service Templates | `service_templates`, `branch_services`, `commission_tiers` | `/services` |
| M08 | Product Categories | `product_categories` | `/product-categories` |
| M09 | Expense Categories | `expense_categories` | `/expense-categories` |
| M10 | Coupon Management | `coupons` | `/coupons` |
| M11 | Accountant POS | `product_invoices`, `product_invoice_lines`, `stock_movements` | `/pos/product` |
| M12 | Employee POS | `service_invoices`, `service_invoice_lines`, `commission_ledger` | `/pos/service` |
| M13 | Invoice View/Print | both invoice tables | `/invoices/{type}/{id}` |
| M14 | Refunds | `refunds`, `stock_movements` | `/refunds` |
| M15 | Commission System | `commission_ledger`, `commission_payments`, `commission_tiers` | `/commissions` |
| M16 | Expense Purchases | `expenses` | `/expenses` |
| M17 | Sales Report | aggregates | `/reports/sales` |
| M18 | Employee Commission Report | `commission_ledger` | `/reports/commissions` |
| M19 | Public Service Catalogue | `catalog_categories`, `catalog_subcategories`, `catalog_prices` | `/catalogue` (public) |
| M20 | Catalogue CRUD | same as M19 | `/admin/catalogue` |
| M21 | Notifications | `notifications` | `/notifications` |
| M22 | Customer System | `customers`, `loyalty_transactions` | `/customers` |
| M23 | CRM & Customer Analytics | `customers`, activity log | `/customers/{id}/activity` |
| M24 | Commission Payment Ledger | `commission_payments` | `/commissions/payments` |
| M25 | Advanced Analytics | aggregates, Recharts | `/analytics` |
| M26 | Agent System | `agents`, `agent_payments` | `/agents`, `/agent-portal` |
| M27 | Incentives & Rewards | `incentive_plans`, `bonus_payments` | `/incentives` |
| M28 | Loyalty System | `loyalty_config`, `loyalty_transactions` | `/loyalty`, `/settings/loyalty` |
| M29 | Inventory & Warehouse | `products`, `purchase_orders`, `stock_movements` | `/inventory` |
| M30 | Internal Referral | `internal_referral_orders`, `commission_ledger` | `/internal-orders` |

---

## Database Schema Reference

### Core
```sql
branches (id, name, city_id FK, phone, address, business_type, commercial_reg_no, tax_number,
  logo_path, vat_rate_override DECIMAL(5,2), is_active, timestamps, deleted_at)

cities (id, name_ar, name_en, is_active, timestamps)

settings (id, key, value, branch_id FK nullable, timestamps)

payment_methods (id, name, branch_id FK, is_active, timestamps, deleted_at)
```

### Users & Permissions (Laratrust)
```sql
users (id, username, name, phone, branch_id FK, salary DECIMAL(12,2),
  base_commission_pct DECIMAL(5,2), referral_commission_pct DECIMAL(5,2) DEFAULT 0,
  joined_date, cv_path, is_active, timestamps, deleted_at)

-- Laratrust tables (auto-created by laratrust:setup):
roles, permissions, role_user, permission_user, permission_role
```

### Service Catalogue
```sql
service_templates (id, name_ar, name_en, description, is_active, timestamps)

branch_services (id, branch_id FK, service_template_id FK, base_commission_pct DECIMAL(5,2),
  max_discount_pct DECIMAL(5,2), is_tahazir BOOL, is_active, timestamps)
  UNIQUE(branch_id, service_template_id)

commission_tiers (id, branch_service_id FK, user_id FK, tier_number TINYINT,
  threshold_amount DECIMAL(12,2), commission_pct DECIMAL(5,2), timestamps)

user_services (id, user_id FK, branch_service_id FK, commission_override_pct DECIMAL(5,2) nullable)
```

### Categories & Lookups
```sql
product_categories (id, name, branch_id FK, is_active, timestamps)
expense_categories (id, name, branch_id FK, is_active, timestamps)
product_units (id, name, timestamps)
catalog_categories (id, name_ar, image_path, sort_order INT, is_active, timestamps)
catalog_subcategories (id, name_ar, category_id FK, image_path, sort_order INT, is_active, timestamps)
catalog_prices (id, subcategory_id FK, name, min_price DECIMAL(12,2), max_price DECIMAL(12,2),
  base_price DECIMAL(12,2), sort_order INT, is_active, timestamps)
  UNIQUE(subcategory_id, name)
```

### Coupons
```sql
coupons (id, code VARCHAR(100), branch_id FK, discount_type ENUM('percentage','fixed'),
  discount_value DECIMAL(12,2), capacity INT nullable, used_count INT DEFAULT 0,
  expires_at TIMESTAMP nullable, is_active, timestamps, deleted_at)
  UNIQUE(code, branch_id)
```

### Customers
```sql
customers (id, full_name, phone, email nullable, branch_id FK,
  customer_type ENUM('individual','corporate'), company_name nullable,
  credit_limit DECIMAL(12,2) nullable, agent_id FK nullable,
  points_balance INT DEFAULT 0, cumulative_spend DECIMAL(12,2) DEFAULT 0,
  tier ENUM('none','bronze','silver','gold') DEFAULT 'none',
  notes TEXT nullable, is_active, timestamps, deleted_at)
  UNIQUE(phone, branch_id)
```

### Agents
```sql
agents (id, name, agent_type ENUM('individual','company'), phone, email nullable,
  commercial_reg_no nullable, branch_id FK,
  discount_mode ENUM('discount','rebate'), rate DECIMAL(5,2),
  is_active, notes TEXT nullable, timestamps, deleted_at)
  UNIQUE(phone, branch_id)

agent_payments (id, agent_id FK, branch_id FK, period_start DATE, period_end DATE,
  total_invoices INT, total_rebate DECIMAL(12,2), paid_by FK users, paid_at TIMESTAMP, timestamps)
  -- IMMUTABLE after insert
```

### Invoices (Class Table Inheritance)
```sql
product_invoices (id, invoice_number, branch_id FK, user_id FK, customer_id FK nullable,
  agent_id FK nullable, coupon_id FK nullable, payment_method_id FK nullable,
  subtotal DECIMAL(12,2), tier_discount_pct DECIMAL(5,2), tier_discount_amount DECIMAL(12,2),
  coupon_discount DECIMAL(12,2), points_redeemed INT DEFAULT 0,
  points_discount DECIMAL(12,2) DEFAULT 0,
  vat_pct DECIMAL(5,2), vat_amount DECIMAL(12,2), total_amount DECIMAL(12,2),
  status ENUM('paid','due','cancelled'), paid_at TIMESTAMP nullable,
  attachment_path nullable, timestamps, deleted_at)

product_invoice_lines (id, invoice_id FK, product_id FK nullable, product_name,
  sku nullable, qty INT, unit_price DECIMAL(12,2), discount_pct DECIMAL(5,2),
  subtotal DECIMAL(12,2), timestamps)

service_invoices (id, invoice_number, branch_id FK, user_id FK, customer_id FK nullable,
  agent_id FK nullable, coupon_id FK nullable, payment_method_id FK nullable,
  subtotal DECIMAL(12,2), tier_discount_pct DECIMAL(5,2), tier_discount_amount DECIMAL(12,2),
  coupon_discount DECIMAL(12,2), points_redeemed INT DEFAULT 0,
  points_discount DECIMAL(12,2) DEFAULT 0,
  vat_pct DECIMAL(5,2), vat_amount DECIMAL(12,2), total_amount DECIMAL(12,2),
  employee_commission DECIMAL(12,2),
  status ENUM('paid','due','cancelled'), paid_at TIMESTAMP nullable,
  attachment_path nullable, timestamps, deleted_at)

service_invoice_lines (id, invoice_id FK, branch_service_id FK, service_name,
  qty INT, unit_price DECIMAL(12,2), discount_pct DECIMAL(5,2), subtotal DECIMAL(12,2),
  commission_pct DECIMAL(5,2), commission_amount DECIMAL(12,2),
  tier_applied TINYINT nullable, timestamps)
```

### Commission
```sql
commission_ledger (id, user_id FK, branch_id FK, invoice_line_id INT, invoice_line_type,
  amount DECIMAL(12,2), is_tahazir BOOL DEFAULT FALSE, tier_applied TINYINT nullable,
  source_type ENUM('standard','referral_referrer','referral_executor') DEFAULT 'standard',
  referral_order_id FK nullable, earned_at TIMESTAMP, paid_at TIMESTAMP nullable)
  -- IMMUTABLE after insert

commission_payments (id, user_id FK, branch_id FK, period_start DATE, period_end DATE,
  total_amount DECIMAL(12,2), paid_by FK users, paid_at TIMESTAMP, notes TEXT nullable)
  -- IMMUTABLE after insert
```

### Refunds
```sql
refunds (id, branch_id FK, user_id FK, source_type ENUM('service','product'),
  invoice_id INT nullable, invoice_type VARCHAR nullable,
  amount DECIMAL(12,2), reason TEXT, stock_reversed BOOL DEFAULT FALSE,
  timestamps, deleted_at)
```

### Expenses
```sql
expenses (id, expense_category_id FK, branch_id FK, user_id FK,
  qty DECIMAL(12,2), unit_price DECIMAL(12,2), total DECIMAL(12,2),
  supplier_name nullable, receipt_reference nullable, receipt_path nullable,
  comment TEXT nullable, date DATE, timestamps, deleted_at)
```

### Incentives
```sql
incentive_plans (id, user_id FK, branch_id FK, period_month TINYINT, period_year SMALLINT,
  target_amount DECIMAL(12,2), bonus_type ENUM('fixed','percentage'),
  bonus_value DECIMAL(12,2), achieved_amount DECIMAL(12,2) DEFAULT 0,
  status ENUM('active','achieved','missed','paid') DEFAULT 'active',
  notes TEXT nullable, timestamps)
  UNIQUE(user_id, period_month, period_year)

bonus_payments (id, incentive_plan_id FK, paid_by FK users,
  amount DECIMAL(12,2), paid_at TIMESTAMP, notes TEXT nullable)
```

### Loyalty
```sql
loyalty_config (id, branch_id FK UNIQUE, earning_rate DECIMAL(8,4) DEFAULT 1,
  redemption_rate DECIMAL(8,4) DEFAULT 100, min_redemption_points INT DEFAULT 500,
  bronze_threshold DECIMAL(12,2) DEFAULT 500,
  silver_threshold DECIMAL(12,2) DEFAULT 2000,
  gold_threshold DECIMAL(12,2) DEFAULT 5000,
  bronze_discount_pct DECIMAL(5,2) DEFAULT 2,
  silver_discount_pct DECIMAL(5,2) DEFAULT 5,
  gold_discount_pct DECIMAL(5,2) DEFAULT 8,
  is_active BOOL DEFAULT TRUE, timestamps)

loyalty_transactions (id, customer_id FK, invoice_id INT nullable, invoice_type nullable,
  type ENUM('earn','redeem','manual_adjust','expire'),
  points INT, balance_after INT, notes TEXT nullable, created_at TIMESTAMP)
  -- IMMUTABLE after insert
```

### Inventory
```sql
products (id, sku VARCHAR(100), branch_id FK, name_ar, name_en nullable,
  category_id FK, unit_id FK, cost_price DECIMAL(12,2), selling_price DECIMAL(12,2),
  min_stock_level INT DEFAULT 0, current_stock INT DEFAULT 0,
  barcode nullable, is_active, timestamps, deleted_at)
  UNIQUE(sku, branch_id)
  -- current_stock is COMPUTED from stock_movements; never update directly

suppliers (id, branch_id FK, name, phone nullable, email nullable, notes TEXT nullable, timestamps)

purchase_orders (id, po_number, branch_id FK, supplier_id FK nullable, ordered_by FK users,
  order_date DATE, expected_delivery DATE nullable,
  status ENUM('draft','sent','partial','received','cancelled') DEFAULT 'draft',
  notes TEXT nullable, timestamps, deleted_at)

purchase_order_lines (id, po_id FK, product_id FK, ordered_qty INT,
  received_qty INT DEFAULT 0, unit_cost DECIMAL(12,2), subtotal DECIMAL(12,2))

stock_movements (id, product_id FK, branch_id FK,
  type ENUM('purchase_in','sale_out','return_in','adjustment_in','adjustment_out','opening_stock'),
  qty INT, unit_cost DECIMAL(12,2) nullable, reference_id INT nullable,
  reference_type VARCHAR nullable, notes TEXT nullable,
  created_by FK users, created_at TIMESTAMP)
  -- IMMUTABLE — no updates or deletes ever

stock_reconciliations (id, branch_id FK, initiated_by FK users,
  completed_at TIMESTAMP nullable, notes TEXT nullable, timestamps)

stock_reconciliation_lines (id, reconciliation_id FK, product_id FK,
  system_qty INT, physical_qty INT, variance INT, movement_id FK nullable)
```

### Internal Referrals (M30)
```sql
internal_referral_orders (id, invoice_id FK service_invoices, referrer_id FK users,
  executor_id FK users nullable, service_category_id FK catalog_categories,
  description TEXT nullable,
  status ENUM('open','accepted','delivered','cancelled') DEFAULT 'open',
  posted_at TIMESTAMP, accepted_at TIMESTAMP nullable,
  delivered_at TIMESTAMP nullable, cancellation_reason TEXT nullable, timestamps)
```

---

## File Structure Conventions

```
app/
  Actions/{Resource}/
    Create{Resource}Action.php
    Update{Resource}Action.php
    Delete{Resource}Action.php
  Http/
    Controllers/{Resource}Controller.php
    Requests/{Resource}/
      Store{Resource}Request.php
      Update{Resource}Request.php
    Resources/{Resource}/
      {Resource}Resource.php
  Models/{Resource}.php
  Policies/{Resource}Policy.php
  Enums/{Resource}{Field}Enum.php   ← backed string enums only

database/
  migrations/
  factories/
  seeders/

resources/js/
  pages/{module}/
    index.tsx
    create.tsx
    edit.tsx
  types/{resource}.ts               ← camelCase interface matching API Resource
  components/ui/                    ← shadcn/ui components

tests/Feature/{Module}/
  {Module}ManagementTest.php        ← Pest tests
```
