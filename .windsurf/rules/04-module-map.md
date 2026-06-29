---
trigger: always_on
---

# Module Map (M01–M30)

Full scope reference for all 30 modules. Use this to understand which tables, routes, and business rules apply to any feature.

---

## M01 — Authentication
- Login: username + password only (no email login). Generic error message (no enumeration).
- Session timeout: configurable, default 120 min.
- Post-login redirects: `super-admin` → all-branches dashboard; `agent` → `/agent-portal`; others → branch dashboard.
- Password change requires current password. Logout invalidates server session immediately.

## M02 — Dashboard
- **SuperAdmin:** one row per branch + totals. All-branch aggregates.
- **BranchAdmin/Accountant:** KPI cards (today's service sales, product sales, returns, expenses, net revenue). Mini 7-day line chart.
- **Employee:** pending commission card, incentive progress widget (M27), payment notification badge.
- Low-stock alert banner for BranchAdmin (links to M29).

## M03 — Branch Management
- Tables: `branches`, `cities`
- Fields: Name, City FK, Phone, Address, Business Type, Commercial Reg No., Tax Number, Logo (Spatie Media), VAT Rate override %, Is Active.
- Soft delete. Cannot delete if has associated invoices.
- Status toggle from list (no form).

## M04 — City Management
- Table: `cities`
- Fields: Name AR, Name EN, Is Active.
- Cannot delete if assigned to a branch.

## M05 — User Management
- Tables: `users`, `user_services`
- Fields: Username, Password (create only), Full Name, Phone, Branch FK (SA only), Role (Laratrust), Salary, Base Commission %, Joined Date, CV (Spatie Media), Is Active.
- Service assignment (Employee only): checkbox of branch services + per-service commission override %.
- Soft delete. Cannot delete if has associated invoices.
- WhatsApp button: `https://wa.me/{phone}`.
- **Impersonation:** admins may "sign in as" a user from the list (`POST users/{user}/impersonate`). `super-admin` targets any non-admin in any branch; `branch-admin` targets only non-admin staff in their own branch. Never targets another admin or self (enforced by `UserPolicy::impersonate`). Original admin id is stashed in `session('impersonator_id')`; a persistent amber banner (shared via `auth.impersonating`) offers return via `DELETE impersonate/leave` (route kept outside role gates so the impersonated user can exit). Start/stop logged to activity log `security`.

## M06 — Settings
- Tables: `settings`, `payment_methods`, `loyalty_config`
- Tabs: General | Payment Methods | Loyalty Program (M28) | Inventory Alerts.
- Payment methods: CRUD, cannot delete if referenced by invoices.
- VAT changes not retroactive.

## M07 — Service Templates (Master Catalogue)
- Tables: `service_templates`, `branch_services`, `commission_tiers`
- **Global templates** (SuperAdmin): Name AR, Name EN, Description, Is Active.
- **Branch services**: Template FK + branch-specific commission %, max discount %, is_tahazir flag.
- UNIQUE(branch_id, service_template_id).
- "Manage Tiers" button → M15 tiered commission config per (branch_service, user) pair.

## M08 — Product Categories
- Table: `product_categories`
- Fields: Name, Branch FK, Is Active.
- Cannot delete if referenced by invoice lines or inventory products.

## M09 — Expense Categories
- Table: `expense_categories`
- Fields: Name, Branch FK, Is Active.
- Cannot delete if referenced by expense records.

## M10 — Coupon Management
- Table: `coupons` — UNIQUE(code, branch_id)
- Fields: Code (case-insensitive), Discount Type (percentage/fixed), Discount Value, Capacity (nullable = unlimited), Expires At, Is Active.
- Validation endpoint: `GET /coupons/validate?code=X` → `{valid, type, value, remaining_capacity}`.
- `used_count` incremented atomically in DB transaction on invoice save.
- Cannot delete if applied to any invoice.

## M11 — Accountant POS (Product Invoice)
- Tables: `product_invoices`, `product_invoice_lines`, `stock_movements`
- Invoice number: `INV-{BRANCH_CODE}-{SEQ}`
- Line items: Product (SKU/name search from M29, or free-text), Qty, Unit Price (auto-fill from product), Discount %.
- Totals chain: Subtotal → Tier Discount → Coupon Discount → Points Redemption → VAT → Total.
- Agent selector (optional): discount or rebate per M26 mode.
- Loyalty tier discount auto-applied (M28). Points redemption toggle.
- Due invoice: credit limit check. `credit_limit IS NULL` → cash-only (block Due).
- **On save with SKU lines:** `stock_movements` type=`sale_out` inserted in same DB transaction.

## M12 — Employee POS (Service Invoice)
- Tables: `service_invoices`, `service_invoice_lines`, `commission_ledger`
- Invoice number: `SINV-{BRANCH_CODE}-{SEQ}`
- Service selector: employee's assigned services only. Commission % badge. Tahazir lines show violet badge.
- Discount max enforced: `max_discount_pct` on branch_service (client + server).
- Tiered commission (M15): active tier label in preview.
- Real-time commission preview: "عمولتك المتوقعة" updates as lines change.
- Employee: saves Paid only. BranchAdmin: can save Due (with credit check).
- **On save:** `service_invoice_lines` + `commission_ledger` entries in one DB transaction.
- **On Paid for individual customer:** loyalty points earned (M28).
- "Change discount" post-save: BranchAdmin only; recalculates commission ledger in same transaction.

## M13 — Invoice Viewing & Printing
- Detail page: branch header, invoice no., date, customer (+ company if corporate), agent, lines, totals, payment method, status badge.
- A4 print: logo, tax no., VAT breakdown, ZATCA QR placeholder (80×80), footer.
- Thermal 80mm: condensed, no logo, `window.print()`.
- Attachment: BranchAdmin uploads PDF/image → private storage → signed URL.

## M14 — Refunds
- Table: `refunds`
- Fields: Amount, Source Type (Service/Product), Invoice FK (optional), Reason, Refunded By (auto), Branch (auto).
- If product invoice with SKU lines: prompt to reverse stock → `stock_movements` type=`return_in`.
- Refunds appear as negative values in sales report.

## M15 — Commission System
- Tables: `commission_ledger`, `commission_payments`, `commission_tiers`
- **Tiered rates:** up to 3 tiers per (branch_service, user). Threshold + pct. Flat rate when no tiers.
- **Ledger:** one row per service invoice line (immutable after insert).
- **Payment:** "Pay now" → `commission_payments` record + sets `paid_at` on ledger entries atomically.
- **Reversal:** new negative adjustment row with mandatory reason (NOT edit/delete).
- Dashboard (`/commissions/overview`): 3 tabs — Employees | Tahazir | Agents.
- Summary cards: إجمالي المستحقة هذا الشهر / المدفوع / المتبقي.

## M16 — Expense Purchases
- Table: `expenses`
- Non-inventory expenses (utilities, rent, marketing). Inventory purchases use M29 POs.
- Fields: Category, Qty, Unit Price, Total (computed), Supplier (free text), Receipt Reference/file, Comment, Branch (auto), Date.

## M17 — Sales Report
- Columns per day: Date, Service Sales, Product Sales, Total, Employee Commission, Agent Rebates, Loyalty Points Value Redeemed, Service Returns, Product Returns, Expenses, Net Revenue.
- Totals row. Date range picker. Excel export (Maatwebsite).
- SuperAdmin: Branch column + sub-totals.

## M18 — Employee Commission Report
- Grouped by employee → date. Drill-down to individual ledger entries.
- Referral sub-tab: `source_type IN ('referral_referrer', 'referral_executor')` (M30).
- Columns: Employee, Date, Total Sales, Net Amount, Commission, Tier Applied, Status.

## M19 — Public Service Catalogue (No Auth)
- Tables: `catalog_categories`, `catalog_subcategories`, `catalog_prices`
- Three-level nav: Category tabs → Sub-category tabs (AJAX) → Price list.
- Price row: Name, min–max SAR range. Base price on hover.
- SSR initial load (Inertia SSR). Real-time search. Mobile-responsive (375px+).
- Fixed header + floating WhatsApp button (bottom-left RTL: bottom-right visually).

## M20 — Catalogue CRUD (Admin)
- Category CRUD: Name, Image (Spatie Media), Sort (drag-and-drop), Is Active.
- Sub-category: Name, Category FK, Image, Sort, Is Active.
- Price: Name, Sub-cat FK, Min/Max/Base Price, Sort, Is Active. UNIQUE(subcategory_id, name).
- Import Excel (upsert on sub_category + name). Export Excel.

## M21 — Notifications
- Table: Laravel `notifications` (polymorphic, database driver).
- Bell icon: unread badge. Dropdown: 10 most recent, "Mark all as read".
- Click navigates to relevant resource. Polling: 60 seconds.
- Types: commission paid, bonus paid, low stock (BranchAdmin), tier upgrade (BranchAdmin).

## M22 — Customer System
- Table: `customers` — UNIQUE(phone, branch_id)
- Types: `individual` / `corporate`. Corporate has `company_name`.
- Credit limit: `NULL` = cash-only (block Due invoices). Non-null = enforce limit.
- Credit check: `(existing unpaid Due total) + (new invoice total) ≤ credit_limit`.
- Customer profile: financial panel + loyalty panel (M28) + invoice history + notes.
- Merge: re-link all secondary invoices → soft-delete secondary customer.
- Tier: computed from cumulative spend; never downgrades automatically.

## M23 — CRM & Customer Analytics
- Activity log (read-only): auto-entries for invoice, payment, refund, tier change, points.
- WhatsApp bulk action: filtered selection → WA links per customer.

## M24 — Commission Payment Ledger
- Table: `commission_payments` (immutable).
- Immutable audit trail. No edit/delete. Reversals = new negative row with reason.
- Employee self-view: own payment records only.

## M25 — Advanced Analytics
- Recharts: daily revenue line, top 10 services bar, employee performance bar, sales donut.
- SuperAdmin: branch comparison bar chart.
- Date range picker applies to all charts. Export each chart to PNG.
- Loyalty analytics tab: tier distribution donut, monthly points earned vs redeemed line.

## M26 — Agent System
- Tables: `agents`, `agent_payments` — UNIQUE(phone, branch_id)
- Mode `discount`: invoice-level discount pre-filled with agent rate % (BranchAdmin can override).
- Mode `rebate`: no auto-discount; rebate stored on invoice = `total_amount × rate/100`.
- Agent portal (`/agent-portal`): isolated route group, `agent` role only, read-only.
- Rebate payment: creates immutable `agent_payments` record.

## M27 — Incentives & Rewards
- Table: `incentive_plans` — UNIQUE(user_id, period_month, period_year)
- Monthly target + bonus (fixed or % of sales above target).
- Scheduled job or on-demand "احتساب الحوافز" → compares target vs SUM(service invoices).
- Payment: `bonus_payments` record created; M21 notification fired.
- Employee widget: Target / Current / Progress bar / Expected bonus.
- Leaderboard (`/incentives/leaderboard`): top 3 gold/silver/bronze rows.

## M28 — Loyalty System
- Tables: `loyalty_config`, `loyalty_transactions`
- Config: earning rate (pts/SAR), redemption rate (pts = 1 SAR), min redemption threshold.
- Tiers (configurable): None → Bronze (500 SAR, 2%) → Silver (2000 SAR, 5%) → Gold (5000 SAR, 8%).
- Points earned: `FLOOR(total_amount × earning_rate)` — only on Paid invoices for individual customers.
- Due invoices: points credited only when marked Paid.
- Tiers: computed from cumulative all-time spend; **never downgrade** (BranchAdmin manual override only).
- Redemption: toggle at POS, slider input, cannot exceed invoice total.
- Redemption applies to individual customers only (not corporate, not agent-linked).
- `loyalty_transactions` is **immutable**.

## M29 — Inventory & Warehouse
- Tables: `products`, `suppliers`, `purchase_orders`, `purchase_order_lines`, `stock_movements`, `stock_reconciliations`, `stock_reconciliation_lines`
- `products.current_stock` is **computed** from `SUM(stock_movements.qty)` — never update directly.
- `stock_movements` is **immutable**. Corrections via new adjustment row.
- PO flow: Draft → Sent → Partial → Received → auto-inserts `purchase_in` movements.
- M11 POS: SKU sale → `sale_out` movement in same DB transaction.
- Negative stock: configurable warn-only vs block.
- Reconciliation: generates variance sheet → adjustment movements per variance ≠ 0.

## M30 — Internal Referral & Commission
- Table: `internal_referral_orders`
- Statuses: `open` → `accepted` → `delivered` (or `cancelled`).
- "Post Internally" button on saved service invoice (only when invoice service category ≠ employee's own category).
- Board (`/internal-orders`): Open | My Accepted | Posted by Me. Client masked (first name only).
- Accept: atomic DB transaction prevents double-accept.
- Delivery → single DB transaction:
  1. Set status=`delivered`.
  2. `referrer_commission = pre_tax_base × referrer.referral_commission_pct / 100`
  3. `executor_commission = pre_tax_base × (1 − referrer_pct/100) × executor_commission_pct / 100`
  4. Insert two `commission_ledger` rows with `source_type = 'referral_referrer'` / `'referral_executor'`.
  5. Fire M21 notification to referrer.
- Any failure → full rollback.
