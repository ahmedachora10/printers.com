---
trigger: always_on
---

# Architecture Patterns

## Request Lifecycle

```
Route → Controller → Action → Model → Inertia::render() / to_route()
```

**Never put business logic in controllers or Form Requests.**

---

## Controllers (Thin)

- Authorize with `Gate::authorize()`
- Call one Action per mutation
- Return `Inertia::render()` for pages, `to_route()` after mutations
- Use Form Requests for all input validation
- Use API Resources to shape Inertia props

```php
class ProductCategoryController extends Controller
{
    public function store(StoreProductCategoryRequest $request, CreateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', ProductCategory::class);
        $action->handle($request->validated());
        return to_route('product-categories.index');
    }

    public function index(): Response
    {
        Gate::authorize('viewAny', ProductCategory::class);
        $items = ProductCategory::query()->where('branch_id', auth()->user()->branch_id)->paginate(20);
        return Inertia::render('product-categories/index', [
            'items' => ProductCategoryResource::collection($items),
        ]);
    }
}
```

---

## Actions (Single-Responsibility)

- Location: `app/Actions/{Resource}/Create{Resource}Action.php`, `Update…`, `Delete…`
- One public `handle()` method, fully typed parameters and return type
- Wrap ALL DB writes in `DB::transaction()`
- Fire events / notifications from here

```php
class CreateProductCategoryAction
{
    public function handle(array $data): ProductCategory
    {
        return DB::transaction(fn () => ProductCategory::create([
            ...$data,
            'branch_id' => auth()->user()->branch_id,
        ]));
    }
}
```

---

## Form Requests

- Location: `app/Http/Requests/{Resource}/Store{Resource}Request.php`
- `authorize()` always returns `true` — Gate::authorize() in controller handles it
- All validation rules here, **never in controllers**
- Enum fields: `Rule::enum(MyEnum::class)`
- Update requests: unique rules use `->ignore($this->route('{resource}'))`

```php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'branch_id' => ['required', 'exists:branches,id'],
    ];
}
```

---

## API Resources

- Location: `app/Http/Resources/{Resource}/{Resource}Resource.php`
- **Always use camelCase keys** in `toArray()`
- Enum fields: `['value' => $this->status->value, 'label' => $this->status->label()]`
- Relationships: `new UserResource($this->whenLoaded('user'))`
- Dates: `$this->created_at?->toISOString()`
- Media: `$this->getFirstMediaUrl('images')`

---

## Policies

- Location: `app/Policies/{Resource}Policy.php`
- Registered in `AppServiceProvider::boot()` — **no separate AuthServiceProvider**
- Methods: `viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`
- Ownership check: `$user->id === $model->user_id` or `$user->branch_id === $model->branch_id`

```php
// AppServiceProvider.php
public function boot(): void
{
    Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
}
```

---

## Laratrust Authorization

```php
// In controller (via Policy)
Gate::authorize('create', ProductCategory::class);
Gate::authorize('update', $productCategory);

// Direct checks
$user->hasRole('super-admin');
$user->hasPermission('manage-inventory');
$user->hasRole(['branch-admin', 'accountant']); // OR logic

// Route middleware
Route::middleware(['auth', 'role:super-admin'])->group(...);
Route::middleware(['auth', 'permission:create-product-invoice'])->group(...);
```

---

## Inertia Pages

- Path: `resources/js/pages/{module}/index.tsx`, `create.tsx`, `edit.tsx`
- Module names: kebab-case matching route resource name
- Props typed with interfaces from `resources/js/types/{resource}.ts`

```tsx
// resources/js/pages/product-categories/index.tsx
import { type ProductCategory } from '@/types/product-category';

interface Props {
  items: { data: ProductCategory[]; links: unknown; meta: unknown };
}

export default function Index({ items }: Props) { ... }
```

---

## Excel Exports (Maatwebsite 3.x)

```php
// app/Exports/ProductsExport.php
class ProductsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private Collection $products) {}

    public function collection(): Collection { return $this->products; }

    public function headings(): array { return ['SKU', 'الاسم', 'المخزون']; }
}

// In controller
return Excel::download(new ProductsExport($products), 'products.xlsx');
```

---

## Activity Logging (Spatie v4)

```php
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Product extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->useLogName('inventory')
            ->setDescriptionForEvent(fn (string $eventName) => "Product {$eventName}");
    }
}
```

---

## Media Library (Spatie)

```php
class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->singleFile();
        $this->addMediaCollection('documents');
    }
}

// Upload in Action
$product->addMediaFromRequest('image')->toMediaCollection('images');

// In API Resource
'imageUrl' => $this->getFirstMediaUrl('images'),
```

---

## Notification Pattern (M21)

```php
// Use Laravel database notifications
$user->notify(new CommissionPaidNotification($payment));

// Notification class
class CommissionPaidNotification extends Notification
{
    public function via($notifiable): array { return ['database']; }

    public function toDatabase($notifiable): array
    {
        return ['message' => "تم دفع عمولتك ليوم {$this->payment->period_end}", 'url' => '/commissions'];
    }
}
```

---

## Queue (Long Exports)

```php
// For exports > 5s, queue them
class ProductsExport implements FromQuery, WithHeadings, ShouldQueue
{
    use Exportable;
}
// Dispatch
(new ProductsExport())->queue('products.xlsx')->chain([
    new NotifyUserOfCompletedExport(auth()->user()),
]);
```
