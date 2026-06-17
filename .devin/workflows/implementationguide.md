---
description: Simplified implementation guide with pattern levels based on complexity
---

# Laravel Implementation Guide - Simplified Patterns

Choose the pattern based on your feature's complexity. Don't over-engineer simple features.

## Pattern Levels

### Level 1: Simple CRUD (Controller → Model)
**Use for:** Basic CRUD operations with no business logic

**Files needed:**
- Controller
- Model (already exists)
- Routes

**Example:**

```php
// Controller
class UserController extends Controller
{
    public function index()
    {
        return User::paginate(15);
    }

    public function show($id)
    {
        return User::findOrFail($id);
    }
}
```

---

### Level 2: Business Logic (Controller → Service → Model)
**Use for:** Features with business rules, validation, or multiple operations

**Files needed:**
- Controller
- Service
- Model
- Routes

**Example:**

```php
// Service
class UserService
{
    public function createUser(array $data)
    {
        $data['password'] = Hash::make($data['password']);
        return User::create($data);
    }
}

// Controller
class UserController extends Controller
{
    public function __construct(UserService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        $user = $this->service->createUser($request->validated());
        return response()->json($user, 201);
    }
}
```

---

### Level 3: Complex Features (Controller → Service → Repository → Model)
**Use for:** Complex features needing data layer abstraction, testing, or multiple data sources

**Files needed:**
- Controller
- Service
- Repository Interface
- Repository Implementation
- Model
- Routes
- Service Provider binding

**Example:**

```php
// Interface
interface UserRepositoryInterface
{
    public function findById(int $id): ?User;
    public function create(array $data): User;
}

// Repository
class UserRepository implements UserRepositoryInterface
{
    public function findById(int $id): ?User
    {
        return User::find($id);
    }
}

// Service
class UserService
{
    public function __construct(UserRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    public function createUser(array $data)
    {
        return DB::transaction(function () use ($data) {
            $data['password'] = Hash::make($data['password']);
            return $this->repo->create($data);
        });
    }
}

// Controller
class UserController extends Controller
{
    public function store(Request $request, UserService $service)
    {
        $user = $service->createUser($request->validated());
        return response()->json($user, 201);
    }
}
```

---

## Quick Decision Guide

| Feature Complexity | Pattern | Files |
|-------------------|---------|-------|
| Simple CRUD (get, list) | Level 1 | 1 (Controller) |
| CRUD with validation | Level 1 + Form Request | 2 |
| Business logic (hashing, rules) | Level 2 | 2 (Controller + Service) |
| Transactions, multiple operations | Level 2 | 2 (Controller + Service) |
| Need to swap data source | Level 3 | 4+ |
| Complex testing requirements | Level 3 | 4+ |

---

## Level 1: Simple CRUD Example

**Controller:** `app/Http/Controllers/UserController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return User::paginate($request->get('per_page', 15));
    }

    public function show($id)
    {
        return User::findOrFail($id);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        return User::create($validated);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
        ]);

        $user->update($validated);
        return $user;
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
```

**Routes:** `routes/web.php`

```php
Route::apiResource('users', UserController::class);
```

---

## Level 2: Service Pattern Example

**Service:** `app/Services/UserService.php`

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function createUser(array $data): User
    {
        $data['password'] = Hash::make($data['password']);
        return User::create($data);
    }

    public function updateUser(int $id, array $data): User
    {
        $user = User::findOrFail($id);
        
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        
        $user->update($data);
        return $user;
    }

    public function deleteUser(int $id): bool
    {
        $user = User::findOrFail($id);
        return $user->delete();
    }

    public function getUsersWithFilters(array $filters)
    {
        $query = User::query();

        if (isset($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (isset($filters['email'])) {
            $query->where('email', 'like', '%' . $filters['email'] . '%');
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }
}
```

**Controller:** `app/Http/Controllers/UserController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(UserService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['name', 'email', 'per_page']);
        return $this->service->getUsersWithFilters($filters);
    }

    public function show($id)
    {
        return $this->service->getUserById($id);
    }

    public function store(Request $request)
    {
        $user = $this->service->createUser($request->validated());
        return response()->json($user, 201);
    }

    public function update(Request $request, $id)
    {
        $user = $this->service->updateUser($id, $request->validated());
        return response()->json($user);
    }

    public function destroy($id)
    {
        $this->service->deleteUser($id);
        return response()->json(['message' => 'Deleted']);
    }
}
```

---

## Level 3: Full Repository Pattern Example

Use this only when you need:
- Data source abstraction (swap MySQL for MongoDB later)
- Complex testing with mocked data layer
- Multiple data sources

**Interface:** `app/Contracts/UserRepositoryInterface.php`

```php
<?php

namespace App\Contracts;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;
    public function create(array $data): User;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}
```

**Repository:** `app/Repositories/UserRepository.php`

```php
<?php

namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Models\User;

class UserRepository implements UserRepositoryInterface
{
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $user = $this->findById($id);
        return $user ? $user->update($data) : false;
    }

    public function delete(int $id): bool
    {
        $user = $this->findById($id);
        return $user ? $user->delete() : false;
    }
}
```

**Service:** `app/Services/UserService.php`

```php
<?php

namespace App\Services;

use App\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(UserRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $data['password'] = Hash::make($data['password']);
            return $this->repo->create($data);
        });
    }
}
```

**Service Provider:** `app/Providers/AppServiceProvider.php`

```php
public function register(): void
{
    $this->app->bind(
        \App\Contracts\UserRepositoryInterface::class,
        \App\Repositories\UserRepository::class
    );
}
```

---

## Best Practices

1. **Start simple** - Use Level 1, upgrade only when needed
2. **Form Requests** - Use for validation (keeps controllers clean)
3. **Resource classes** - Use for API response formatting
4. **Policies** - Use for authorization, don't put in controllers
5. **Actions** - For single-use operations, consider Action classes instead of Services

---

## When to Use Each Pattern

**Level 1 (Controller → Model):**
- Simple admin panels
- Basic API endpoints
- Prototyping
- Small applications

**Level 2 (Controller → Service → Model):**
- User registration/login
- Order processing
- Payment handling
- Email sending
- File uploads with processing

**Level 3 (Full Repository):**
- Large enterprise applications
- Need to support multiple databases
- Complex testing requirements
- Data source might change
- Team needs strict abstraction layers
