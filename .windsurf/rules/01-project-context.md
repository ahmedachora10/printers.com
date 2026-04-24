---
trigger: always_on
---

# Project: Alnaasik Print Center

## Identity

- **Product:** مركز النعائس للطباعة — Internal Management System
- **Business:** Multi-branch print center, Saudi Arabia
- **Language:** Arabic (RTL primary). All UI strings in `lang/ar/*.php`. `dir="rtl"` on `<html>`.
- **Currency:** SAR — `1,234.50 ر.س`. Always `DECIMAL(12,2)`, **never float**.
- **VAT:** 15% default, configurable per branch.
- **Date display:** `DD/MM/YYYY`. DB storage: `YYYY-MM-DD`.
- **Scope:** 30 modules — auth, POS, commissions, inventory, loyalty, B2B agents, incentives.

---

## Confirmed Tech Stack

### Backend (all installed)
| Package | Constraint | Key usage |
|---|---|---|
| Laravel | ^12.0 | PHP 8.3 |
| `inertiajs/inertia-laravel` | ^2.0 | SSR + SPA bridge |
| `santigarcor/laratrust` | `*` | Roles & permissions — `HasRolesAndPermissions` trait, `hasRole()`, `hasPermission()` |
| `spatie/laravel-medialibrary` | `*` | `HasMedia` + `InteractsWithMedia` |
| `spatie/laravel-activitylog` | `4.12.3` | `LogsActivity` trait + `getActivitylogOptions()` (v5 needs PHP 8.4) |
| `maatwebsite/excel` | `3.1.68` | `Excel::download()`, implement `FromCollection` + `WithHeadings` |
| `tightenco/ziggy` | ^2.4 | `route()` in JS |
| Pest | ^3.8 | Testing |

### Frontend (all installed)
| Package | Notes |
|---|---|
| React 19 + TypeScript | Pages in `resources/js/pages/` |
| Inertia React v2 | `useForm`, `router`, `<Link>` |
| Tailwind CSS v4 | RTL configured |
| shadcn/ui (Radix UI) | Components in `resources/js/components/ui/` |
| Lucide React | Icons |
| Recharts | **Not yet installed** — run `npm install recharts` when building charts |

### Infrastructure
- **Database:** MySQL 8.0+ (production). SQLite for local dev.
- **Queue:** Laravel Queue, database driver.
- **Storage:** Private storage only — never `public/`. Use signed URLs for downloads.

---

## Roles (Laratrust)

| Slug | Scope | Description |
|---|---|---|
| `super-admin` | All branches | Full access |
| `branch-admin` | Own branch | Full access within branch |
| `accountant` | Own branch | Product invoices, refunds, expenses, reports |
| `employee` | Own branch | Service invoices, own commission, incentive |
| `agent` | Own data | Read-only agent portal |

Permissions use kebab-case: `manage-branches`, `create-service-invoice`, `create-product-invoice`, `process-refund`, `pay-commission`, `manage-inventory`, `configure-loyalty`, etc.

---

## Installed Artisan Commands to Publish

```bash
# After fresh clone:
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config

# Start dev (Laravel + queue + Vite):
composer dev
```
