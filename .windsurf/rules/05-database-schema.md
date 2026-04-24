---
trigger: always_on
---

# Database Schema Reference

MySQL 8.0+. All monetary columns `DECIMAL(12,2)`. All percentage columns `DECIMAL(5,2)`. All primary keys `BIGINT UNSIGNED` (Laravel `id()`). All FKs `unsignedBigInteger` with `constrained()`.

**Immutable tables** (no UPDATE/DELETE ever): `stock_movements`, `commission_ledger`, `loyalty_transactions`, `commission_payments`, `agent_payments`, `bonus_payments`.

---

## Core

```sql
cities
  id, name_ar VARCHAR(255), name_en VARCHAR(255), is_active BOOL DEFAULT TRUE, timestamps

branches
  id, name VARCHAR(255), city_id FK→cities, phone VARCHAR(20), address TEXT,
  business_type VARCHAR(255), commercial_reg_no VARCHAR(100), tax_number VARCHAR(100),
  vat_rate_override DECIMAL(5,2) DEFAULT 15.00, is_active BOOL DEFAULT TRUE,
  timestamps, deleted_at
  -- Logo via Spatie Media Library (collection: 'logo')

settings
  id, key VARCHAR(255), value TEXT, branch_id FK→branches nullable,
  timestamps
  UNIQUE(key, branch_id)

payment_methods
  id, name VARCHAR(255), branch_id FK→branches, is_active BOOL DEFAULT TRUE,
  timestamps, deleted_at
```

---

## Users & Permissions

```sql
users
  id, username VARCHAR(255) UNIQUE, name VARCHAR(255), email VARCHAR(255) UNIQUE,
  password VARCHAR(255), phone VARCHAR(20), branch_id FK→branches nullable,
  salary DECIMAL(12,2) DEFAULT 0, base_commission_pct DECIMAL(5,2) DEFAULT 0,
  referral_commission_pct DECIMAL(5,2) DEFAULT 0,
  joined_date DATE nullable, is_active BOOL DEFAULT TRUE,
  remember_token, email_verified_at, timestamps, deleted_at
  -- CV via Spatie Media Library (collection: 'cv')

-- Laratrust tables (created by laratrust:setup):
roles (id, name, display_name, description, timestamps)
permissions (id, name, display_name, description, timestamps)
role_user (role_id FK, user_id FK, user_type VARCHAR)
permission_user (permission_id FK, user_id FK, user_type VARCHAR)
permission_role (permission_id FK, role_id FK)
```

---

## Service Catalogue

```sql
service_templates
  id, name_ar VARCHAR(255), name_en VARCHAR(255), description TEXT nullable,
  is_active BOOL DEFAULT TRUE, timestamps

branch_services
  id, branch_id FK→branches, service_template_id FK→service_templates,
  base_commission_pct DECIMAL(5,2) DEFAULT 0,
  max_discount_pct DECIMAL(5,2) DEFAULT 0,
  is_tahazir BOOL DEFAULT FALSE, is_active BOOL DEFAULT TRUE, timestamps
  UNIQUE(branch_id, service_template_id)

commission_tiers
  id, branch_service_id FK→branch_services, user_id FK→users,
  tier_number TINYINT UNSIGNED,  -- 1, 2, or 3
  threshold_amount DECIMAL(12,2), commission_pct DECIMAL(5,2), timestamps

user_services
  id, user_id FK→users, branch_service_id FK→branch_services,
  commission_override_pct DECIMAL(5,2) nullable, timestamps
  UNIQUE(user_id, branch_service_id)
```

---

## Catalogue (Public Price List)

```sql
catalog_categories
  id, name_ar VARCHAR(255), image_path nullable, sort_order INT DEFAULT 0,
  is_active BOOL DEFAULT TRUE, timestamps
  -- Image via Spatie Media Library (collection: 'image')

catalog_subcategories
  id, name_ar VARCHAR(255), category_id FK→catalog_categories,
  image_path nullable, sort_order INT DEFAULT 0, is_active BOOL DEFAULT TRUE, timestamps

catalog_prices
  id, subcategory_id FK→catalog_subcategories, name VARCHAR(255),
  min_price DECIMAL(12,2), max_price DECIMAL(12,2), base_price DECIMAL(12,2),
  sort_order INT DEFAULT 0, is_active BOOL DEFAULT TRUE, timestamps
  UNIQUE(subcategory_id, name)
```

---

## Lookups

```sql
product_categories
  id, name VARCHAR(255), branch_id FK→branches, is_active BOOL DEFAULT TRUE, timestamps

expense_categories
  id, name VARCHAR(255), branch_id FK→branches, is_active BOOL DEFAULT TRUE, timestamps

product_units
  id, name VARCHAR(100), timestamps
```

---

## Coupons

```sql
coupons
  id, code VARCHAR(100), branch_id FK→branches,
  discount_type ENUM('percentage','fixed'), discount_value DECIMAL(12,2),
  capacity INT nullable,  -- NULL = unlimited
  used_count INT DEFAULT 0, expires_at TIMESTAMP nullable,
  is_active BOOL DEFAULT TRUE, timestamps, deleted_at
  UNIQUE(code, branch_id)
```

---

## Customers

```sql
customers
  id, full_name VARCHAR(255), phone VARCHAR(20), email VARCHAR(255) nullable,
  branch_id FK→branches,
  customer_type ENUM('individual','corporate') DEFAULT 'individual',
  company_name VARCHAR(255) nullable,  -- only when corporate
  credit_limit DECIMAL(12,2) nullable,  -- NULL = cash-only
  agent_id FK→agents nullable,
  points_balance INT DEFAULT 0,
  cumulative_spend DECIMAL(12,2) DEFAULT 0,
  tier ENUM('none','bronze','silver','gold') DEFAULT 'none',
  notes TEXT nullable, is_active BOOL DEFAULT TRUE, timestamps, deleted_at
  UNIQUE(phone, branch_id)
```

---

## Agents

```sql
agents
  id, name VARCHAR(255), agent_type ENUM('individual','company'),
  phone VARCHAR(20), email VARCHAR(255) nullable,
  commercial_reg_no VARCHAR(100) nullable,
  branch_id FK→branches,
  discount_mode ENUM('discount','rebate'),
  rate DECIMAL(5,2) DEFAULT 0,
  is_active BOOL DEFAULT TRUE, notes TEXT nullable, timestamps, deleted_at
  UNIQUE(phone, branch_id)

agent_payments  -- IMMUTABLE
  id, agent_id FK→agents, branch_id FK→branches,
  period_start DATE, period_end DATE,
  total_invoices INT DEFAULT 0, total_rebate DECIMAL(12,2),
  paid_by FK→users, paid_at TIMESTAMP, timestamps
```

---

## Invoices

```sql
product_invoices
  id, invoice_number VARCHAR(50), branch_id FK→branches, user_id FK→users,
  customer_id FK→customers nullable, agent_id FK→agents nullable,
  coupon_id FK→coupons nullable, payment_method_id FK→payment_methods nullable,
  subtotal DECIMAL(12,2),
  tier_discount_pct DECIMAL(5,2) DEFAULT 0,
  tier_discount_amount DECIMAL(12,2) DEFAULT 0,
  coupon_discount DECIMAL(12,2) DEFAULT 0,
  points_redeemed INT DEFAULT 0,
  points_discount DECIMAL(12,2) DEFAULT 0,
  vat_pct DECIMAL(5,2), vat_amount DECIMAL(12,2),
  total_amount DECIMAL(12,2),
  status ENUM('paid','due','cancelled') DEFAULT 'paid',
  paid_at TIMESTAMP nullable, attachment_path nullable,
  timestamps, deleted_at

product_invoice_lines
  id, invoice_id FK→product_invoices, product_id FK→products nullable,
  product_name VARCHAR(255), sku VARCHAR(100) nullable,
  qty INT, unit_price DECIMAL(12,2), discount_pct DECIMAL(5,2) DEFAULT 0,
  subtotal DECIMAL(12,2), timestamps

service_invoices
  id, invoice_number VARCHAR(50), branch_id FK→branches, user_id FK→users,
  customer_id FK→customers nullable, agent_id FK→agents nullable,
  coupon_id FK→coupons nullable, payment_method_id FK→payment_methods nullable,
  subtotal DECIMAL(12,2),
  tier_discount_pct DECIMAL(5,2) DEFAULT 0,
  tier_discount_amount DECIMAL(12,2) DEFAULT 0,
  coupon_discount DECIMAL(12,2) DEFAULT 0,
  points_redeemed INT DEFAULT 0,
  points_discount DECIMAL(12,2) DEFAULT 0,
  vat_pct DECIMAL(5,2), vat_amount DECIMAL(12,2), total_amount DECIMAL(12,2),
  employee_commission DECIMAL(12,2) DEFAULT 0,
  status ENUM('paid','due','cancelled') DEFAULT 'paid',
  paid_at TIMESTAMP nullable, attachment_path nullable,
  timestamps, deleted_at

service_invoice_lines
  id, invoice_id FK→service_invoices, branch_service_id FK→branch_services,
  service_name VARCHAR(255), qty INT,
  unit_price DECIMAL(12,2), discount_pct DECIMAL(5,2) DEFAULT 0,
  subtotal DECIMAL(12,2), commission_pct DECIMAL(5,2),
  commission_amount DECIMAL(12,2), tier_applied TINYINT nullable,
  timestamps
```

---

## Commission

```sql
commission_ledger  -- IMMUTABLE
  id, user_id FK→users, branch_id FK→branches,
  invoice_line_id BIGINT UNSIGNED, invoice_line_type VARCHAR(50),  -- polymorphic
  amount DECIMAL(12,2), is_tahazir BOOL DEFAULT FALSE,
  tier_applied TINYINT nullable,
  source_type ENUM('standard','referral_referrer','referral_executor') DEFAULT 'standard',
  referral_order_id FK→internal_referral_orders nullable,
  earned_at TIMESTAMP, paid_at TIMESTAMP nullable

commission_payments  -- IMMUTABLE
  id, user_id FK→users, branch_id FK→branches,
  period_start DATE, period_end DATE, total_amount DECIMAL(12,2),
  paid_by FK→users, paid_at TIMESTAMP, notes TEXT nullable
```

---

## Refunds & Expenses

```sql
refunds
  id, branch_id FK→branches, user_id FK→users,
  source_type ENUM('service','product'),
  invoice_id BIGINT UNSIGNED nullable, invoice_type VARCHAR(50) nullable,
  amount DECIMAL(12,2), reason TEXT,
  stock_reversed BOOL DEFAULT FALSE, timestamps, deleted_at

expenses
  id, expense_category_id FK→expense_categories, branch_id FK→branches,
  user_id FK→users, qty DECIMAL(12,2), unit_price DECIMAL(12,2),
  total DECIMAL(12,2), supplier_name VARCHAR(255) nullable,
  receipt_reference VARCHAR(255) nullable, receipt_path nullable,
  comment TEXT nullable, date DATE, timestamps, deleted_at
```

---

## Incentives

```sql
incentive_plans
  id, user_id FK→users, branch_id FK→branches,
  period_month TINYINT UNSIGNED, period_year SMALLINT UNSIGNED,
  target_amount DECIMAL(12,2),
  bonus_type ENUM('fixed','percentage'),
  bonus_value DECIMAL(12,2), achieved_amount DECIMAL(12,2) DEFAULT 0,
  status ENUM('active','achieved','missed','paid') DEFAULT 'active',
  notes TEXT nullable, timestamps
  UNIQUE(user_id, period_month, period_year)

bonus_payments  -- IMMUTABLE
  id, incentive_plan_id FK→incentive_plans, paid_by FK→users,
  amount DECIMAL(12,2), paid_at TIMESTAMP, notes TEXT nullable
```

---

## Loyalty

```sql
loyalty_config
  id, branch_id FK→branches UNIQUE,
  earning_rate DECIMAL(8,4) DEFAULT 1.0000,   -- pts earned per SAR
  redemption_rate DECIMAL(8,4) DEFAULT 100.0000,  -- pts needed per 1 SAR
  min_redemption_points INT DEFAULT 500,
  bronze_threshold DECIMAL(12,2) DEFAULT 500.00,
  silver_threshold DECIMAL(12,2) DEFAULT 2000.00,
  gold_threshold DECIMAL(12,2) DEFAULT 5000.00,
  bronze_discount_pct DECIMAL(5,2) DEFAULT 2.00,
  silver_discount_pct DECIMAL(5,2) DEFAULT 5.00,
  gold_discount_pct DECIMAL(5,2) DEFAULT 8.00,
  is_active BOOL DEFAULT TRUE, timestamps

loyalty_transactions  -- IMMUTABLE
  id, customer_id FK→customers,
  invoice_id BIGINT UNSIGNED nullable, invoice_type VARCHAR(50) nullable,
  type ENUM('earn','redeem','manual_adjust','expire'),
  points INT,           -- positive for earn, negative for redeem
  balance_after INT,
  notes TEXT nullable, created_at TIMESTAMP
```

---

## Inventory

```sql
products
  id, sku VARCHAR(100), branch_id FK→branches,
  name_ar VARCHAR(255), name_en VARCHAR(255) nullable,
  category_id FK→product_categories, unit_id FK→product_units,
  cost_price DECIMAL(12,2), selling_price DECIMAL(12,2),
  min_stock_level INT DEFAULT 0,
  current_stock INT DEFAULT 0,  -- READ-ONLY: computed from stock_movements
  barcode VARCHAR(100) nullable, is_active BOOL DEFAULT TRUE, timestamps, deleted_at
  UNIQUE(sku, branch_id)

suppliers
  id, branch_id FK→branches, name VARCHAR(255), phone VARCHAR(20) nullable,
  email VARCHAR(255) nullable, notes TEXT nullable, timestamps

purchase_orders
  id, po_number VARCHAR(50), branch_id FK→branches,
  supplier_id FK→suppliers nullable, ordered_by FK→users,
  order_date DATE, expected_delivery DATE nullable,
  status ENUM('draft','sent','partial','received','cancelled') DEFAULT 'draft',
  notes TEXT nullable, timestamps, deleted_at

purchase_order_lines
  id, po_id FK→purchase_orders, product_id FK→products,
  ordered_qty INT, received_qty INT DEFAULT 0,
  unit_cost DECIMAL(12,2), subtotal DECIMAL(12,2)

stock_movements  -- IMMUTABLE
  id, product_id FK→products, branch_id FK→branches,
  type ENUM('purchase_in','sale_out','return_in','adjustment_in','adjustment_out','opening_stock'),
  qty INT,  -- positive = stock in, negative = stock out
  unit_cost DECIMAL(12,2) nullable,
  reference_id BIGINT UNSIGNED nullable, reference_type VARCHAR(50) nullable,
  notes TEXT nullable, created_by FK→users, created_at TIMESTAMP

stock_reconciliations
  id, branch_id FK→branches, initiated_by FK→users,
  completed_at TIMESTAMP nullable, notes TEXT nullable, timestamps

stock_reconciliation_lines
  id, reconciliation_id FK→stock_reconciliations, product_id FK→products,
  system_qty INT, physical_qty INT, variance INT,
  movement_id FK→stock_movements nullable
```

---

## Internal Referrals (M30)

```sql
internal_referral_orders
  id, invoice_id FK→service_invoices, referrer_id FK→users,
  executor_id FK→users nullable,
  service_category_id FK→catalog_categories,
  description TEXT nullable,
  status ENUM('open','accepted','delivered','cancelled') DEFAULT 'open',
  posted_at TIMESTAMP, accepted_at TIMESTAMP nullable,
  delivered_at TIMESTAMP nullable,
  cancellation_reason TEXT nullable, timestamps
```

---

## Critical Unique Constraints Summary

| Table | Unique constraint |
|---|---|
| `coupons` | `(code, branch_id)` |
| `catalog_prices` | `(subcategory_id, name)` |
| `branch_services` | `(branch_id, service_template_id)` |
| `products` | `(sku, branch_id)` |
| `incentive_plans` | `(user_id, period_month, period_year)` |
| `agents` | `(phone, branch_id)` |
| `customers` | `(phone, branch_id)` |
| `user_services` | `(user_id, branch_service_id)` |
| `loyalty_config` | `(branch_id)` |
| `settings` | `(key, branch_id)` |
| `users` | `(username)`, `(email)` |
