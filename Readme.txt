
//module list
| Module | Includes |
|---|---|
| **Auth** | Staff/customer login, logout, password reset |
| **Users** | Store staff accounts and permissions |
| **Catalog** | Products, variants, product images, categories, tags, and inventory |
| **Customers** | Customer profiles and delivery addresses |
| **Orders** | Carts, cart items, checkout, order items, delivery details, order status, cancellation |
| **Payments** | COD records, payment status, and future online payments |



app/
    ├── Auth/
    │   ├── Domain/
    │   │   ├── Services/
    │   │   ├── Policies/
    │   │   └── Contracts/
    │   ├── Controllers/
    │   ├── Requests/
    │   ├── Repositories/
    │   └── routes.php
    │
    ├── Users/
    │   ├── Domain/
    │   │   ├── Services/
    │   │   ├── Policies/
    │   │   └── Contracts/
    │   ├── Models/
    │   │   ├── User.php
    │   │   ├── EmailVerificationToken.php
    │   │   └── PasswordResetToken.php
    │   ├── Controllers/
    │   ├── Requests/
    │   ├── Resources/
    │   ├── Repositories/
    │   └── routes.php
    │
    ├── Catalog/
    │   ├── Domain/
    │   │   ├── Services/
    │   │   ├── Policies/
    │   │   └── Contracts/
    │   ├── Models/
    │   │   ├── Product.php
    │   │   ├── ProductVariant.php
    │   │   ├── ProductImage.php
    │   │   ├── Category.php
    │   │   ├── Tag.php
    │   │   ├── Coupon.php
    │   │   └── InventoryTransaction.php
    │   ├── Controllers/
    │   ├── Requests/
    │   ├── Resources/
    │   ├── Repositories/
    │   └── routes.php
    │
    ├── Customers/
    │   ├── Domain/
    │   │   ├── Services/
    │   │   ├── Policies/
    │   │   └── Contracts/
    │   ├── Models/
    │   │   ├── Customer.php
    │   │   └── Address.php
    │   ├── Controllers/
    │   ├── Requests/
    │   ├── Resources/
    │   ├── Repositories/
    │   └── routes.php
    │
    ├── Orders/
    │   ├── Domain/
    │   │   ├── Services/
    │   │   ├── Policies/
    │   │   └── Contracts/
    │   ├── Models/
    │   │   ├── Cart.php
    │   │   ├── CartItem.php
    │   │   ├── Order.php
    │   │   └── OrderItem.php
    │   ├── Controllers/
    │   ├── Requests/
    │   ├── Resources/
    │   ├── Repositories/
    │   └── routes.php
    │
    └── Payments/
        ├── Domain/
        │   ├── Services/
        │   ├── Policies/
        │   └── Contracts/
        ├── Models/
        │   └── Payment.php
        ├── Controllers/
        ├── Requests/
        ├── Resources/
        ├── Repositories/
        └── routes.php

Cart behavior and models are part of the Orders module.

database/
├── migrations/
├── factories/
└── seeders/

tests/
├── Feature/
│   ├── Auth/
│   ├── Users/
│   ├── Catalog/
│   ├── Customers/
│   ├── Orders/
│   └── Payments/
└── Unit/
    ├── Auth/
    ├── Users/
    ├── Catalog/
    ├── Customers/
    ├── Orders/
    └── Payments/




Common requirements — include with every module
1. Use the existing database schema and models. Inspect them before implementation; propose any necessary database changes instead of silently replacing existing structures.
2. Each module owns its routes, controllers, request validation, services, and relevant models.
3. Controllers handle HTTP requests and responses. Services contain business rules and coordinate database operations.
4. Enforce authorization on the backend for every protected operation.
5. Keep staff and customer authentication separate. A customer account must never grant staff access.
6. Validate all incoming data. Allow only explicitly permitted fields to be written.
7. Use consistent JSON responses, HTTP status codes, validation errors, pagination, filtering, and sorting.
8. Use database transactions for operations that must succeed or fail together.
9. Use database constraints alongside application validation to maintain data integrity.
10. Store money using fixed decimal values or integer minor units. Never use floating-point arithmetic for prices or payment calculations.
11. Keep sensitive data out of API responses and logs.
12. Record timestamps and the responsible staff member for important administrative changes.
13. Preserve historical orders and payments when related products, customers, or staff accounts change.
14. Return useful errors without exposing SQL, stack traces, credentials, or server internals.
15. Add meaningful automated tests for authorization, important business rules, validation, and failure cases.
16. Do not add unrelated features, unnecessary tables, or extra architectural layers.

1. Auth module
=================
Purpose: Authenticate staff and customers and manage access to their accounts.
Owns: Login, logout, customer registration, password reset, password changes, and authentication credentials or sessions.
Functional requirements
Customer registration
- Allow a customer to register using the chosen login identifier: email, phone number, or both.
- Clearly define which identifier is required and which must be unique.
- Validate identifier format and password confirmation.
- Hash passwords securely.
- Create the customer account and required profile records atomically.
- Never accept staff roles, permissions, or administrative fields during registration.
- Public staff registration must be disabled.
Login
- Provide separate staff and customer login flows.
- Validate credentials and return the appropriate session or access token.
- Reject disabled accounts.
- Use generic authentication errors that do not reveal whether an account exists.
- Apply rate limits to login attempts.
- Return only safe account information after login.
Authenticated account
- Provide an endpoint to retrieve the currently authenticated account.
- Identify whether the authenticated principal is staff or customer.
- Make authenticated identity available to other modules.
- Protect routes using the correct authentication guard.
Logout and access revocation
- Allow logout from the current session or device.
- Allow logout from all sessions or devices.
- Invalidate the relevant session or token.
- Revoke access when an account is disabled.
- Define session or token expiry and enforce it.
Password management
- Allow authenticated users to change their password after confirming their current password.
- Provide password reset through a configured, verified recovery channel.
- Reset tokens or codes must expire, be single-use, and be securely stored.
- Rate-limit reset requests and verification attempts.
- Return a generic response to password reset requests.
- Revoke existing sessions after a successful password reset.
- Never allow password changes by accepting an account ID without verifying ownership.
Verification
- If email or phone verification is enabled, provide verification and resend flows.
- Verification codes or links must expire and be single-use.
- Changing a verified identifier must require verification of the new value.
- Do not mark an identifier as verified merely because a customer submitted it.
Non-functional requirements
- Use Laravel’s supported authentication, hashing, and reset facilities where appropriate.
- Never store plaintext passwords, reset tokens, or authentication secrets.
- For cookie authentication, enforce secure cookie settings and CSRF protection.
- For token authentication, protect token storage and support revocation.
- Use HTTPS in production.
- Log significant authentication events without logging credentials.
Completion checks
- Staff credentials cannot authenticate through the customer flow, or vice versa.
- Disabled accounts cannot access protected endpoints.
- Expired and reused reset tokens fail.
- Logout invalidates access.
- Customers cannot obtain staff privileges through registration or profile updates.



2. Users module
===================
Purpose: Manage store staff accounts and their administrative permissions.
Owns: Staff profiles, staff account status, roles, and permissions.
Functional requirements
Staff account management
- Allow authorized staff to create other staff accounts.
- Store staff name, login identifier, contact information, and account status.
- Enforce unique staff login identifiers.
- Allow authorized staff to update staff profiles.
- Allow staff to update permitted fields on their own profile.
- List staff with pagination, search, and filters by role or status.
- View an individual staff account.
- Disable and reactivate staff accounts.
- Prefer deactivation over deletion when a staff account is linked to historical activity.
Roles and permissions
- Support a simple role and permission system.
- Define permissions by action, such as:
  - View or manage catalog.
  - Adjust inventory.
  - View or manage customers.
  - View or process orders.
  - Record or reconcile payments.
  - Manage staff.
  - Assign roles and permissions.
- Enforce permissions on every relevant backend endpoint.
- Restrict role assignment to authorized administrators.
- Prevent staff from granting permissions beyond their authority.
- Prevent staff from changing their own role or permissions without explicit authorization.
- Protect the last active highest-privilege administrator from deletion, deactivation, or demotion.
- Keep customer accounts outside this module.
Account access coordination
- Use Auth for password hashing, password changes, and password reset behavior.
- Revoke active access when a staff account is disabled.
- Never return password hashes or authentication secrets.
Activity history
- Record who created, updated, disabled, or reactivated staff accounts.
- Record role and permission changes.
- Preserve references to former staff in order and payment history.
Non-functional requirements
- Deny access by default unless permission is explicitly granted.
- Apply permissions immediately or invalidate permission caches after changes.
- Protect against privilege escalation through request payloads.
- Maintain an auditable history of security-sensitive changes.
Completion checks
- A staff member without permission cannot manage other staff.
- Unauthorized role changes fail.
- The final active administrator remains protected.
- Disabling staff removes their ability to use protected APIs.
- Historical activity remains readable after staff deactivation.



3. Catalog module
==================
Purpose: Manage everything the store sells and determine what is available to purchase.
Owns: Products, variants, product media, categories, tags, prices, and inventory.
Functional requirements
Products
- Create, view, update, archive, and list products.
- Support name, unique slug, descriptions, publication status, and existing schema fields.
- Support draft, published, and archived states.
- Expose only published products through public endpoints.
- Allow authorized staff to access unpublished products.
- Validate prices, identifiers, and required product information.
- Prevent destructive deletion of products referenced by orders.
- Support product search, pagination, filtering, and sorting.
Variants
- Support simple products and products with multiple purchasable variants.
- Use one consistent representation for purchasable items; avoid conflicting product-level and variant-level prices or stock.
- Support combinations such as color, size, shape, and capacity.
- Store each purchasable combination as a distinct variant.
- Require a unique SKU for each purchasable item.
- Prevent duplicate option combinations within the same product.
- Support variant-specific price, stock, active status, and image.
- Allow optional barcode and weight where supported by the schema.
- Prevent inactive variants from being purchased.
- Prevent removal of variants referenced by orders; archive or disable them instead.
Pricing
- Support regular price and an optional sale price.
- Validate that sale price is non-negative and below regular price.
- If sale dates exist, enforce their start and end times.
- Return the effective selling price consistently.
- Keep cost price and internal margins hidden from public APIs.
- Maintain one explicit store currency initially, such as BDT.
Categories and tags
- Support hierarchical categories using parent-child relationships.
- Support tags without requiring a hierarchy.
- Allow products to belong to multiple categories and tags.
- Prevent a category from becoming its own parent or descendant.
- Enforce the chosen slug uniqueness rules.
- Define how deleting a category affects its children and product associations.
- Removing a category or tag must not delete its products.
Product images
- Support multiple images per product.
- Support variant-specific images.
- Allow image ordering and primary image selection.
- Support alt text.
- Use product images as a fallback when a variant has no image.
- Validate upload size, file type, and actual image content.
- Restrict uploads to supported image formats.
- Prevent removing a file still referenced elsewhere.
- Clean up failed uploads and orphaned files safely.
Inventory
- Track stock for each purchasable item.
- Define:
  - On-hand stock.
  - Reserved stock.
  - Available stock: on-hand minus reserved.
- Support authorized stock additions, corrections, and reductions.
- Require a reason for manual stock adjustments.
- Record quantity changes, actor, reason, timestamp, and related order where relevant.
- Prevent negative available stock unless backorders are explicitly enabled.
- Disable backorders by default.
- Provide low-stock and out-of-stock filters.
- Support stock reservation, release, and final deduction for Orders.
- Prevent concurrent purchases from reserving the same stock.
- Make repeated reservation, deduction, or release requests safe against duplicate application.
Public catalog
- List published products and display individual product details.
- Filter by category, tag, price, and availability.
- Sort using an allowed list of fields.
- Return variant options, effective prices, images, and availability.
- Exclude internal supplier information, cost prices, and unpublished data.
Non-functional requirements
- Use transactions and appropriate database locking or atomic updates for stock changes.
- Index frequently searched identifiers and relationships.
- Avoid excessive database queries when loading images, variants, and categories.
- Bound pagination sizes.
- Keep catalog caching consistent after product and stock updates.
Completion checks
- A bottle can have separate red/500 ml, red/1 litre, and green/500 ml variants.
- Duplicate variant combinations fail.
- Archived products cannot enter checkout.
- Two concurrent checkouts cannot both reserve the last unit.
- Public responses never expose internal cost prices.



4. Customers module
=====================
Purpose: Manage customer information and reusable delivery addresses.
Owns: Customer profiles, saved addresses, customer account status, and permitted staff notes.
Functional requirements
Customer profiles
- Store customer name, contact details, account status, and existing profile fields.
- Allow customers to view and update their own permitted profile fields.
- Allow authorized staff to create, view, and update customer profiles.
- Support customer search by name, phone, email, and customer identifier.
- Provide paginated staff listings.
- Apply the defined identifier uniqueness rules consistently with Auth.
- Keep staff accounts separate from customers.
- Never accept role, permission, or payment-status fields through customer profile endpoints.
Addresses
- Allow customers to create, view, update, and delete their own saved addresses.
- Store recipient name, phone, address lines, and the delivery location fields needed for Bangladesh.
- Support an optional address label and delivery instructions.
- Allow one default delivery address per customer.
- Ensure the first address becomes the default, or define another consistent default policy.
- Handle default-address deletion consistently.
- Allow authorized staff to manage addresses when assisting a customer.
- Validate that an address belongs to the customer using it.
Guest customers
- Support guest checkout without requiring a customer password.
- Allow Orders to store guest contact and delivery details.
- Do not automatically link a guest order to an account solely because the submitted email or phone matches.
- If guest-order claiming is supported later, require verified ownership.
- Prevent guest checkout from creating duplicate registered accounts unnecessarily.
Account status
- Allow authorized staff to block and unblock customers.
- Prevent blocked customers from creating new orders.
- Record the reason and responsible staff member.
- Coordinate authentication restrictions with Auth.
History and privacy
- Provide access to customer order summaries through Orders.
- Provide payment summaries through Payments when authorized.
- Do not duplicate order or payment business logic here.
- Preserve order delivery snapshots when saved addresses change.
- Support account closure or anonymization while retaining transaction records required by the store’s retention policy.
- Restrict internal customer notes to authorized staff.
Non-functional requirements
- Enforce customer ownership on every profile and address operation.
- Expose only the minimum personal data needed.
- Keep personal information out of public endpoints and unnecessary logs.
- Make profile and address changes transactional where multiple records are affected.
Completion checks
- Customers cannot read or modify another customer’s addresses.
- A customer cannot have multiple default delivery addresses.
- Updating an address does not alter an existing order’s delivery details.
- Guest checkout does not grant access to an existing customer account.
- Blocked customers cannot place orders.



5. Orders module
=================
Purpose: Manage the purchase process from cart creation through delivery or cancellation.
Owns: Carts, checkout, orders, order items, delivery snapshots, fulfillment status, and order history.
Functional requirements
Carts
- Support registered-customer and guest carts.
- Use a secure, unguessable identifier for guest carts.
- Allow adding, updating, and removing items.
- Validate that the product and selected variant are purchasable.
- Require positive integer quantities and enforce configured limits.
- Merge duplicate variants into one cart line.
- Return item prices, quantities, subtotals, and estimated totals.
- Recalculate prices from Catalog; never trust client-submitted prices.
- Revalidate availability when viewing the cart and at checkout.
- Do not reserve stock merely because an item is in a cart.
- Define cart expiry and guest-cart merging after login.
- Merge only a guest cart legitimately associated with the current session.
Checkout
- Support customer and guest checkout.
- Collect recipient, phone, complete delivery address, and optional delivery instructions.
- Validate required fields and delivery coverage.
- Calculate delivery charges on the server using a simple configurable rule.
- Support COD initially.
- Reject unsupported payment methods.
- Recalculate product prices, discounts, delivery charges, and the final total on the server.
- Notify the client when a reviewed price has changed and require confirmation before placing the order.
- Reject unavailable, inactive, unpublished, or insufficient-stock items.
- Prevent blocked customers from placing orders.
- Use an idempotency key or equivalent mechanism to prevent duplicate orders on retries.
- Clear or mark the cart converted only after successful order creation.
Order creation
- Generate a unique, human-readable order number.
- Store customer linkage when applicable.
- Snapshot customer contact and delivery details.
- Snapshot item name, SKU, selected options, unit price, quantity, discount, and line total.
- Preserve snapshots after catalog changes.
- Store subtotal, discount total, delivery charge, applicable tax, grand total, and currency.
- Do not introduce a tax or coupon subsystem unless explicitly required.
- Create the order, items, stock reservation, and initial payment record as one coordinated transaction where they share the database.
- Roll back partial changes on failure.
Order statuses
Use a clear initial lifecycle:
pending → confirmed → processing → shipped → delivered
Also support cancelled, with explicit rules for which states can enter it.
- Define permitted transitions in one place.
- Reject invalid transitions.
- Record previous status, new status, actor, timestamp, and optional note.
- Keep fulfillment status separate from payment status.
- Do not mark an order paid merely because it was confirmed or shipped.
- Restrict administrative transitions by permission.
Stock coordination
- Reserve stock when an order is successfully placed.
- Release reservations when eligible orders are cancelled or expire.
- Convert reserved stock into an on-hand deduction when the order ships.
- Ensure every stock effect happens once.
- Do not automatically restock shipped goods on cancellation.
- Returned goods may increase stock only after their physical return and condition are confirmed.
Order management
- Allow customers to view only their own orders.
- Provide secure guest-order access using an unguessable access token or verified contact flow.
- Do not expose guest orders using order number alone.
- Allow authorized staff to search and filter by order number, customer, phone, dates, fulfillment status, and payment status.
- Provide paginated order lists and full order details.
- Allow internal staff notes.
- Allow delivery-detail corrections before shipment with an audit history.
- Keep placed-order items and prices immutable initially; cancel and recreate an incorrect order instead of adding complex order editing.
Delivery
- Store delivery method, courier name, tracking reference, and shipment timestamps where available.
- Allow manual courier updates initially.
- Track shipment and delivery confirmation.
- Keep delivery charges separate from product totals.
- Define how failed delivery is recorded and resolved.
- Preserve failed-delivery history.
Cancellation and expiry
- Allow customer cancellation only during explicitly permitted early states.
- Allow authorized staff cancellation according to the transition rules.
- Require a cancellation reason.
- Release reserved inventory exactly once.
- Notify Payments so unpaid COD obligations can be closed or prepaid orders can enter refund handling.
- Never mark money refunded merely because an order was cancelled.
- Define reservation expiry for abandoned online-payment orders when online payments are introduced.
- Do not expire COD orders using online-payment timeout rules.
Non-functional requirements
- Checkout must remain correct under concurrent requests.
- Repeated requests must not create duplicate orders or stock movements.
- Use fixed-precision calculations and consistent rounding.
- Keep notifications outside the critical transaction and send them only after commit.
- Preserve a complete order status history.
- Protect order access against guessing IDs and tokens.
Completion checks
- Modifying prices in the request does not change server-calculated totals.
- Retrying checkout returns the same order for the same idempotency key.
- Stock reservation failure leaves no partial order or payment record.
- Cancellation releases stock once.
- Product edits do not change historical order items.
- Delivery confirmation does not automatically prove COD money reached the store.

6. Payments module
=====================
Purpose: Track what is owed, what was collected, and what actually reached the store.
Owns: Payment records, payment attempts, payment status, COD collection and settlement, and future refunds and gateway transactions.
Functional requirements
Payment records
- Create an initial payment record for each placed order.
- Store order reference, method, amount due, currency, status, and relevant timestamps.
- Support COD initially.
- Keep payment methods extensible for future online gateways.
- Support multiple payment attempts for an order without treating every attempt as a separate successful payment.
- Calculate the outstanding amount from valid financial records.
- Prevent duplicate successful collection records.
- Do not accept payment amounts or successful statuses directly from customers.
- Default to full payment; do not introduce installments or split payments unless explicitly required.
Payment statuses
- Define explicit statuses such as pending, paid, failed, cancelled, partially refunded, and refunded.
- Distinguish an unsuccessful payment attempt from the overall unpaid order.
- Define valid transitions and restrict who or what may perform them.
- Keep payment status independent from order fulfillment status.
- Record status history and its source: staff action, courier reconciliation, or verified gateway event.
COD collection
- Record that payment is expected on delivery.
- Distinguish:
  - Not collected.
  - Collected by courier.
  - Remitted to the store.
- Record collected amount, collection date, collector or courier, and reference.
- Record courier remittance date and reference.
- Record courier deductions separately from the customer’s payment.
- Handle differences between expected collection and actual collection without silently changing the order total.
- Require authorized staff and a reason for corrections.
- Allow COD reconciliation by order, courier, date range, and settlement status.
- Close the unpaid COD obligation when an eligible order is cancelled.
- Preserve collection history if a collected order later enters return or refund handling.
Manual payment recording
- Permit only authorized staff to record or confirm manual collections.
- Record the actor, amount, date, method, reference, and note.
- Validate duplicate references where uniqueness is meaningful for that payment provider.
- Prevent over-collection unless a defined adjustment workflow permits it.
- Correct financial mistakes through traceable reversal or adjustment records.
- Never silently overwrite or delete confirmed financial history.
Future online payments
- Provide a payment-service boundary so a gateway can be added without rewriting Orders.
- Initiate transactions from the backend using the server-calculated order amount.
- Store provider references and payment attempt state.
- Treat browser redirects and customer screenshots as unverified.
- Confirm payment through authenticated provider notifications and/or server-to-server verification.
- Validate order reference, provider transaction ID, amount, currency, and verified success state.
- Process duplicate and out-of-order notifications safely.
- Handle timeout, failure, cancellation, and late success.
- Reconcile uncertain attempts by querying the provider.
- If money arrives after order cancellation, record it and flag it for resolution; do not silently reopen fulfillment.
- Keep network calls outside long-running database transactions.
Refunds
- Permit refunds only against recorded payments.
- Require authorization, amount, and reason.
- Prevent refunds exceeding the remaining refundable amount.
- Record requested, pending, successful, and failed refund outcomes.
- Support manual COD refunds with transfer method and reference.
- Change refund status only after confirmed completion.
- Keep refunds separate from stock returns and order cancellation.
Reporting and access
- Allow customers to see safe payment summaries for their own orders.
- Allow authorized staff to filter payments by date, method, status, order, and reference.
- Report expected COD, collected COD, unsettled COD, settled funds, and refunds separately.
- Keep failed attempts out of collected-payment totals.
- Do not label collected payments as profit.
- Restrict exports to authorized staff and include only necessary personal information.
Non-functional requirements
- Make collection, confirmation, settlement, and refund operations idempotent.
- Use transactions and locking where concurrent updates could produce duplicate money movements.
- Keep an auditable financial history.
- Store gateway credentials securely.
- Never store card security codes or raw card details.
- Log integration failures without exposing secrets or sensitive customer data.
Completion checks
- An unpaid COD order remains unpaid when shipped.
- Courier collection and store settlement are distinguishable.
- Recording the same collection twice does not double the balance.
- A forged browser success response cannot mark an order paid.
- Repeated gateway notifications do not duplicate payments.
- Refunds cannot exceed the amount actually paid.
Module boundaries
Module	Owns	Uses other modules for
Auth	Authentication and credential flows	Staff account status from Users; customer account status from Customers
Users	Staff accounts and permissions	Credential operations through Auth
Catalog	Products, prices, media, taxonomy, stock	Order references for inventory movements
Customers	Profiles and saved addresses	Authentication through Auth; history through Orders and Payments
Orders	Carts, checkout, fulfillment, delivery snapshots	Catalog availability and stock; Customers eligibility; Payments records
Payments	Collections, settlements, attempts, refunds	Order amount and order eligibility from Orders


Core ownership rule: Orders requests stock changes through Catalog, and payment changes through Payments. Each business rule should have one owning module, even though all six modules share the same database.

Local development test credentials
==================================
Database: ghorgoch (local)
Password for all accounts: DevTest@12345
These credentials are for local development and testing only.

