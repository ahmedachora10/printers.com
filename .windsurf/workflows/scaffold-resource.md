---
description: Scaffold a complete Laravel + Inertia resource (migration, model, factory, seeder, controller, form requests, actions, API resource, policy, Pest tests, TypeScript types, and routes) from a dbdiagram schema.
---

# Scaffold New Resource

Generate all files for a new Laravel + Inertia resource. The user will provide a **resource name** and a **dbdiagram schema block**. Follow every step below in order.

---

## Project Stack (always apply)

- Laravel 12.x, PHP 8.3, PSR-12
- Frontend: React 19.x, Inertia.js v2, Tailwind CSS
- Testing: Pest 3
- Policies registered inside `App\Providers\AppServiceProvider` (no separate `AuthServiceProvider`)
- No `updated_at` unless schema contains it; never add columns not in the schema

---

## Step 0 — Parse the Schema

Before generating any file, extract and state:

1. **Tables** — list each table name
2. **Columns** — for each column, note: name, type, nullable, default, unique, enum values
3. **Indexes** — list all defined indexes
4. **Foreign keys / refs** — list each `ref:` and the inferred relationship direction
5. **Enums** — list any `[note: 'enum: ...']` fields and their values
6. **Media fields** — detect by name: `image`, `images`, `photo`, `photos`, `picture`, `avatar`, `thumbnail`, `cover`, `banner`, `logo`, `file`, `files`, `document`, `documents`, `attachment`, `attachments`, or any field ending in `_image`, `_photo`, `_file`, `_document`, `_attachment`, or annotated `[note: 'media']` / `[note: 'file']`

### Type mapping rules

| dbdiagram type | Laravel migration |
|---|---|
| `[pk, increment]` | `$table->id()` |
| `varchar(N)` | `$table->string('field', N)` |
| `varchar` | `$table->string('field')` |
| `text` | `$table->text('field')` |
| `decimal(M,D)` | `$table->decimal('field', M, D)` |
| `bigint` (FK via ref) | `$table->foreignId('field')->constrained()->cascadeOnDelete()` |
| `bigint` (non-FK) | `$table->bigInteger('field')` |
| `integer` | `$table->integer('field')` |
| `boolean` | `$table->boolean('field')` |
| `date` | `$table->date('field')` |
| `datetime` | `$table->dateTime('field')` |
| `timestamp` | `$table->timestamp('field')` |
| `json` | `$table->json('field')` |
| `[not null]` | no `->nullable()` |
| `[null]` | `->nullable()` |
| `[default: 'value']` | `->default('value')` |
| `[default: \`now()\`]` | `->useCurrent()` |
| `[unique]` | `->unique()` |
| `created_at` + `updated_at` together | `$table->timestamps()` (do not add separately) |
| only `created_at` in schema | `$table->timestamp('created_at')->useCurrent()` (do NOT use `$table->timestamps()`) |
| `deleted_at` | `$table->softDeletes()` |

### Relationship inference from refs

| ref pattern | Model A method | Model B method |
|---|---|---|
| `ref: > users.id` | `belongsTo(User::class)` | `hasMany(ResourceClass::class)` |
| `ref: > other_table.id` | `belongsTo(OtherModel::class)` | `hasMany(ResourceClass::class)` |
| junction table (two FKs, no other cols) | `belongsToMany` on both sides | — |

---

## Step 1 — Check Existing Files

Before creating ANY file, check if it already exists using the available tools. If a file exists, **ask the user** whether to skip or overwrite. Never silently overwrite.

---

## Step 2 — Enums (only if detected)

**Path:** `app/Enums/{Resource}{Field}Enum.php`

```php
<?php

namespace App\Enums;

enum {Resource}{Field}Enum: string
{
    case CaseOne = 'case_one';
    // ...

    public function label(): string
    {
        return match($this) {
            self::CaseOne => 'Case One',
            // ...
        };
    }
}
```

---

## Step 3 — Migration

**Path:** `database/migrations/YYYY_MM_DD_HHMMSS_create_{resources}_table.php`

Use `php artisan make:migration` timestamp format. Apply all type mappings from Step 0.

Rules:
- Add `$table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()` for nullable user FKs
- Add `$table->foreignId('user_id')->constrained()->cascadeOnDelete()` for non-nullable user FKs
- Add indexes defined in the schema's `indexes {}` block as `$table->index(['col'])` or `$table->index(['col1', 'col2'])`
- Media fields: **omit entirely** from migration (Spatie handles them)
- Place foreign key columns before indexes block

---

## Step 4 — Model

**Path:** `app/Models/{Resource}.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
// SoftDeletes if deleted_at present
// HasMedia + InteractsWithMedia if media fields detected

class {Resource} extends Model
{
    use HasFactory;

    protected $fillable = [
        // all columns except id, timestamps, deleted_at
    ];

    protected $casts = [
        // enums: 'field' => {Resource}{Field}Enum::class
        // json: 'field' => 'array'
        // dates: 'field' => 'date'
    ];

    // Relationships with docblocks
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, self> */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

If media fields detected, also add:
```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('{field_name}')->singleFile(); // singleFile() for singular names
}
```

---

## Step 5 — Factory

**Path:** `database/factories/{Resource}Factory.php`

- Use `fake()->...` helpers matching each column type
- For enums: `{Resource}{Field}Enum::cases()[array_rand({Resource}{Field}Enum::cases())]`
- For FK user_id: `User::factory()`
- For media fields: omit (handled separately)
- For string with max N: `fake()->text(N)` or appropriate faker method

---

## Step 6 — Seeder

**Path:** `database/seeders/{Resource}Seeder.php`

```php
{Resource}::factory()->count(10)->create();
```

---

## Step 7 — Controller

**Path:** `app/Http/Controllers/{Resource}Controller.php`

Rules:
- Thin controller — no business logic
- Use `Gate::authorize()` with policy methods
- Return `Inertia::render('{resource}/index', [...])` for index/create/edit
- Use `{Resource}Resource` for shaping props
- Redirect with `to_route()` after mutations

```php
<?php

namespace App\Http\Controllers;

use App\Actions\{Resource}\Create{Resource}Action;
use App\Actions\{Resource}\Update{Resource}Action;
use App\Actions\{Resource}\Delete{Resource}Action;
use App\Http\Requests\{Resource}\Store{Resource}Request;
use App\Http\Requests\{Resource}\Update{Resource}Request;
use App\Http\Resources\{Resource}\{Resource}Resource;
use App\Models\{Resource};
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class {Resource}Controller extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', {Resource}::class);

        $items = {Resource}::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(15);

        return Inertia::render('{resource}/index', [
            'items' => {Resource}Resource::collection($items),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', {Resource}::class);

        return Inertia::render('{resource}/create');
    }

    public function store(Store{Resource}Request $request, Create{Resource}Action $action): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('create', {Resource}::class);

        $action->handle($request->validated());

        return to_route('{resource}.index');
    }

    public function edit({Resource} ${resource}): Response
    {
        Gate::authorize('update', ${resource});

        return Inertia::render('{resource}/edit', [
            'item' => new {Resource}Resource(${resource}),
        ]);
    }

    public function update(Update{Resource}Request $request, {Resource} ${resource}, Update{Resource}Action $action): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('update', ${resource});

        $action->handle(${resource}, $request->validated());

        return to_route('{resource}.index');
    }

    public function destroy({Resource} ${resource}, Delete{Resource}Action $action): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('delete', ${resource});

        $action->handle(${resource});

        return to_route('{resource}.index');
    }
}
```

---

## Step 8 — Form Requests

### Store Request — `app/Http/Requests/{Resource}/Store{Resource}Request.php`

```php
<?php

namespace App\Http\Requests\{Resource};

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class Store{Resource}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize() handles it in controller
    }

    public function rules(): array
    {
        return [
            // Derive from schema:
            // required string: 'field' => ['required', 'string', 'max:255']
            // nullable: 'field' => ['nullable', 'string']
            // unique: 'field' => ['required', 'string', 'unique:{table}']
            // enum: 'field' => ['required', Rule::enum({Resource}{Field}Enum::class)]
        ];
    }
}
```

### Update Request — `app/Http/Requests/{Resource}/Update{Resource}Request.php`

Same structure but unique rules use `->ignore($this->route('{resource}'))`.

---

## Step 9 — Actions

### Create — `app/Actions/{Resource}/Create{Resource}Action.php`

```php
<?php

namespace App\Actions\{Resource};

use App\Models\{Resource};
use Illuminate\Support\Facades\DB;

class Create{Resource}Action
{
    public function handle(array $data): {Resource}
    {
        return DB::transaction(function () use ($data): {Resource} {
            return {Resource}::create([
                ...$data,
                'user_id' => auth()->id(),
            ]);
        });
    }
}
```

### Update — `app/Actions/{Resource}/Update{Resource}Action.php`

```php
public function handle({Resource} ${resource}, array $data): {Resource}
{
    return DB::transaction(function () use (${resource}, $data): {Resource} {
        ${resource}->update($data);
        return ${resource}->fresh();
    });
}
```

### Delete — `app/Actions/{Resource}/Delete{Resource}Action.php`

```php
public function handle({Resource} ${resource}): void
{
    DB::transaction(function () use (${resource}): void {
        ${resource}->delete();
    });
}
```

---

## Step 10 — API Resource

**Path:** `app/Http/Resources/{Resource}/{Resource}Resource.php`

```php
<?php

namespace App\Http\Resources\{Resource};

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class {Resource}Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // camelCase keys
            // enums: 'status' => ['value' => $this->status->value, 'label' => $this->status->label()]
            // dates: 'createdAt' => $this->created_at?->toISOString()
            // relationships: 'user' => new UserResource($this->whenLoaded('user'))
        ];
    }
}
```

---

## Step 11 — Policy

**Path:** `app/Policies/{Resource}Policy.php`

```php
<?php

namespace App\Policies;

use App\Models\{Resource};
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class {Resource}Policy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, {Resource} ${resource}): bool
    {
        return $user->id === ${resource}->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, {Resource} ${resource}): bool
    {
        return $user->id === ${resource}->user_id;
    }

    public function delete(User $user, {Resource} ${resource}): bool
    {
        return $user->id === ${resource}->user_id;
    }

    public function restore(User $user, {Resource} ${resource}): bool
    {
        return $user->id === ${resource}->user_id;
    }

    public function forceDelete(User $user, {Resource} ${resource}): bool
    {
        return $user->id === ${resource}->user_id;
    }
}
```

Then register the policy inside `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::policy({Resource}::class, {Resource}Policy::class);
```

---

## Step 12 — Routes

Add to `routes/web.php` inside the `auth` middleware group:

```php
use App\Http\Controllers\{Resource}Controller;

Route::resource('{resources}', {Resource}Controller::class)
    ->middleware(['auth', 'verified']);
```

Or explicit routes if the resource does not need all seven methods.

---

## Step 13 — TypeScript Types

**Path:** `resources/js/types/{resource}.ts`

```typescript
export interface {Resource} {
  id: number;
  // camelCase field names matching the API Resource output
  // enums: status: { value: string; label: string }
  // nullable: field?: string | null
  createdAt: string;
}

export interface Paginated{Resource} {
  data: {Resource}[];
  links: Record<string, string | null>;
  meta: Record<string, unknown>;
}
```

---

## Step 14 — Pest Tests

**Path:** `tests/Feature/{Resource}/{Resource}ManagementTest.php`

```php
<?php

use App\Models\{Resource};
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('{Resource} Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    // INDEX
    it('allows authenticated users to view {resource} list', function () {
        {Resource}::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->get(route('{resource}.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->component('{resource}/index'));
    });

    // STORE — happy path
    it('creates a {resource} with valid data', function () {
        $data = [/* valid factory data */];

        $response = $this->post(route('{resource}.store'), $data);

        $response->assertRedirect(route('{resource}.index'));
        $this->assertDatabaseHas('{resources}', $data);
    });

    // STORE — validation
    it('fails to create a {resource} with missing required fields', function () {
        $response = $this->post(route('{resource}.store'), []);

        $response->assertSessionHasErrors(['name']); // adjust per schema
    });

    // UPDATE
    it('updates own {resource}', function () {
        ${resource} = {Resource}::factory()->create(['user_id' => $this->user->id]);

        $response = $this->put(route('{resource}.update', ${resource}), ['name' => 'Updated']);

        $response->assertRedirect(route('{resource}.index'));
        $this->assertDatabaseHas('{resources}', ['id' => ${resource}->id, 'name' => 'Updated']);
    });

    // AUTHORIZATION
    it('prevents updating another user\'s {resource}', function () {
        $other = User::factory()->create();
        ${resource} = {Resource}::factory()->create(['user_id' => $other->id]);

        $response = $this->put(route('{resource}.update', ${resource}), ['name' => 'Hacked']);

        $response->assertForbidden();
    });

    // DELETE
    it('deletes own {resource}', function () {
        ${resource} = {Resource}::factory()->create(['user_id' => $this->user->id]);

        $this->delete(route('{resource}.destroy', ${resource}))->assertRedirect();
        $this->assertDatabaseMissing('{resources}', ['id' => ${resource}->id]);
    });

    it('prevents deleting another user\'s {resource}', function () {
        $other = User::factory()->create();
        ${resource} = {Resource}::factory()->create(['user_id' => $other->id]);

        $this->delete(route('{resource}.destroy', ${resource}))->assertForbidden();
    });
});
```

---

## Step 15 — Media Handling (only if media fields detected)

When a media field is detected:

1. **Migration:** Do NOT add the field column.
2. **Model:** Implement `HasMedia` interface, use `InteractsWithMedia` trait, add `registerMediaCollections()`.
3. **Controller store/update:** After the action, call `${resource}->addMediaFromRequest('field')->toMediaCollection('collection_name')`.
4. **API Resource:** Add `'fieldUrl' => $this->getFirstMediaUrl('collection_name')`.
5. **TypeScript:** Add `fieldUrl: string | null`.

---

## Step 16 — Output Summary

After generating all files, output:

1. **Detected structure** (tables, enums, relationships)
2. **File tree** of all created files
3. **Commands to run:**

```bash
composer dump-autoload
php artisan migrate
php artisan db:seed --class={Resource}Seeder
```

4. **Registration reminder:** Policy registration in `AppServiceProvider`
5. **Test command:**

```bash
php artisan test --filter={Resource}
```

6. **Next steps:** Create Inertia page components at `resources/js/pages/{resource}/index.tsx`, `create.tsx`, `edit.tsx`.
