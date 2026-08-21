# MyShop architecture diagrams

These diagrams describe the current localhost-first architecture. They show ownership and trust boundaries rather than every individual function.

## 1. Request and service ownership

HTTP concerns stay at the public boundary. Business rules live in focused services. The compatibility facade is an adapter for existing callers; focused services do not depend on it.

```mermaid
flowchart LR
    B[Browser] --> P[public / page controllers and assets]
    P --> S[Focused PHP services]
    L[Legacy pages and CLI tools] --> F[includes/functions.php / compatibility facade]
    F --> S
    S --> C[config/db.php]
    C --> D[(MySQL)]
    P --> U[public/uploads / protected media boundary]
```

## 2. Transactional order and stock lifecycle

The server recalculates the order from current database state. A failed validation or write rolls the transaction back, so an order cannot leave stock and history out of sync.

```mermaid
flowchart TD
    A[POS submits order] --> B[Authenticate and authorize]
    B --> C[Validate CSRF, items, prices, and quantities]
    C --> D[Begin transaction]
    D --> E[Lock and re-read products]
    E --> F{Enough stock?}
    F -- No --> R[Rollback and return a generic error]
    F -- Yes --> G[Insert Order and OrderDetail]
    G --> H[Update Product stock]
    H --> I[Insert StockMovement]
    I --> J[Insert success AuditLog]
    J --> K[Commit]
    K --> L[Order history and invoice]
```

## 3. Authentication, authorization, and data boundaries

Authentication and authorization are separate decisions. The application account can read and change business rows, but it cannot change the schema or grant privileges.

```mermaid
flowchart TD
    R[HTTP request] --> S[Secure session]
    S --> Q[Account and IP rate limit]
    Q --> A{Authenticated?}
    A -- No --> X[Generic login response]
    A -- Yes --> Z{Role check}
    Z -- Admin --> AD[Admin routes / staff, settings, audit, backup]
    Z -- Cashier --> CA[Cashier routes / sales and own order history]
    AD --> DB[Runtime DB account / CRUD only]
    CA --> DB
    DB -. no DDL or GRANT .-> N[Schema boundary / controlled migration account]
```

The database, upload, and local environment boundaries are intentionally local. Docker host ports bind to 127.0.0.1 by default, and .env is never committed.
