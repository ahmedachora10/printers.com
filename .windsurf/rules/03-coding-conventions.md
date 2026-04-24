---
trigger: always_on
---

# Coding Conventions

## PHP

- **Style:** PSR-12 strictly. Run `./vendor/bin/pint` to format.
- **Version:** PHP 8.3. Use typed properties, constructor promotion, match expressions, named arguments, readonly where applicable.
- **Enums:** Backed string enums only (`enum Status: string`). Always add `label(): string` method.
- **No docblocks for simple methods** — only add `@return` / `@param` when type cannot be inferred (e.g., generic collections, relationships).
- **Relationship docblocks:** Always document Eloquent relations:
  ```php
  /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Branch, self> */
  public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
  ```

## Naming

| Thing | Convention | Example |
|---|---|---|
| Tables | `snake_case`, plural | `product_categories` |
| Model | `PascalCase`, singular | `ProductCategory` |
| Controller | `{Resource}Controller` | `ProductCategoryController` |
| Action | `{Verb}{Resource}Action` | `CreateProductCategoryAction` |
| Form Request | `{Verb}{Resource}Request` | `StoreProductCategoryRequest` |
| API Resource | `{Resource}Resource` | `ProductCategoryResource` |
| Policy | `{Resource}Policy` | `ProductCategoryPolicy` |
| Migration | `create_{table}_table` | `create_product_categories_table` |
| Route name | `kebab-case.action` | `product-categories.index` |
| Inertia page | `kebab-case/action` | `product-categories/index` |
| TS type file | `kebab-case.ts` | `product-category.ts` |
| TS interface | `PascalCase` | `ProductCategory` |

## Database

- **Money/percentage:** `DECIMAL(12,2)` for amounts, `DECIMAL(5,2)` for percentages. **No floats.**
- **Soft deletes:** All primary entities use `SoftDeletes`. Cannot hard-delete records with invoice associations.
- **Immutable tables** — never UPDATE or DELETE rows in:
  - `stock_movements`
  - `commission_ledger`
  - `loyalty_transactions`
  - `commission_payments`
  - `agent_payments`
  - `bonus_payments`
- **Timestamps:** Only add `$table->timestamps()` when both `created_at` AND `updated_at` are in the schema. If only `created_at` → use `$table->timestamp('created_at')->useCurrent()`.
- **Fillable:** Always explicit `$fillable` on models. Never use `$guarded = []`.
- **Indexes:** Explicitly define indexes for all FK columns and commonly filtered columns.

## Transactions

Every operation that touches multiple tables or writes money data must use `DB::transaction()`:

```php
// ✅ Correct
DB::transaction(function () use ($data) {
    $invoice = ServiceInvoice::create($data['invoice']);
    foreach ($data['lines'] as $line) {
        $invoice->lines()->create($line);
        CommissionLedger::create([...]);
    }
    LoyaltyTransaction::create([...]);
});

// ❌ Wrong — no transaction wrapping
$invoice = ServiceInvoice::create($data);
CommissionLedger::create([...]);
```

## Frontend (TypeScript + React)

- **Component style:** Functional components with typed props. No class components.
- **Imports:** Always import from `@/` alias (`@/components/ui/button`, `@/types/product`).
- **Types:** All Inertia page props typed. Create interface in `resources/js/types/{resource}.ts`.
- **camelCase JSON keys:** API Resources must use camelCase. TypeScript interfaces must match.
- **RTL:** Use `dir="rtl"` at layout level. Use logical CSS properties (`ms-`, `me-`, `ps-`, `pe-`). Don't hardcode `left`/`right` in Tailwind when RTL-sensitive — use `ltr:ml-2 rtl:mr-2` or logical variants.
- **Amount formatting:**
  ```tsx
  const formatSAR = (amount: number) =>
    new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' }).format(amount);
  ```
- **shadcn/ui:** Always use existing components from `resources/js/components/ui/`. Don't build from scratch what shadcn/ui provides.

## Validation Rules Derivation (from schema)

| Schema constraint | Validation rule |
|---|---|
| `NOT NULL` | `required` |
| `nullable` | `nullable` |
| `VARCHAR(N)` | `string\|max:N` |
| `UNIQUE(col, branch_id)` | `unique:table,col,{id},id,branch_id,{branch_id}` |
| `FK → table.id` | `exists:table,id` |
| `ENUM(...)` | `Rule::enum(MyEnum::class)` |
| `DECIMAL(12,2)` | `numeric\|min:0` |

## Route Structure

```php
// web.php — inside auth middleware group
Route::middleware(['auth', 'verified'])->group(function () {
    // Resource routes
    Route::resource('product-categories', ProductCategoryController::class);

    // SuperAdmin-only
    Route::middleware('role:super-admin')->group(function () {
        Route::resource('branches', BranchController::class);
    });

    // Agent portal — isolated
    Route::middleware('role:agent')->prefix('agent-portal')->group(function () {
        Route::get('/', [AgentPortalController::class, 'index'])->name('agent-portal.index');
    });
});

// Public — no auth
Route::get('/catalogue', [CatalogueController::class, 'index'])->name('catalogue.index');
```

## Testing (Pest 3)

```php
// tests/Feature/{Module}/{Module}ManagementTest.php
uses(RefreshDatabase::class);

describe('ProductCategory Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->user->attachRole('branch-admin');
        $this->actingAs($this->user);
    });

    it('lists product categories', function () {
        ProductCategory::factory()->count(3)->create(['branch_id' => $this->user->branch_id]);
        $this->get(route('product-categories.index'))->assertOk();
    });
});
```

- Use `attachRole('role-slug')` / `attachPermission('permission-slug')` for Laratrust in tests.
- Always test: happy path, validation failures, authorization (forbidden for wrong role/owner).
- Immutable tables: assert that no UPDATE/DELETE queries are executed.
