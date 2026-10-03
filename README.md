# PEPTIXIE PHP + MySQL Ordering System

A complete starter e-commerce/food-ordering system inspired by marketplace patterns, with original PEPTIXIE branding.

## Requirements
- PHP 8.0+
- MySQL 5.7+/8.x or MariaDB
- Apache (XAMPP/WAMP/LAMP)
- PDO MySQL enabled

## Installation in XAMPP
1. Copy the `sissies_shop` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL from XAMPP.
3. Open phpMyAdmin.
4. Import `database/sissies_shop.sql`.
5. If your MySQL username/password differs from XAMPP defaults, edit `config/database.php`.
6. Open:
   `http://localhost/sissies_shop/`

## Demo admin
Email: admin@sissies.local
Phone: 09000000000
Password: Admin@12345

After importing the SQL, change the admin password and credentials before using this online.

## Customer flow
Register → Login → Order List → Add to Cart → Checkout → My Purchase → Admin changes status → To Receive → customer clicks TO RECEIVE → Purchase History.

## Admin flow
Login → Admin → Manage Orders → change status → Save.
The customer sees the status after refreshing the My Purchase page.

## Security notes
This is a complete starter application, not a finished production deployment. Before publishing:
- use HTTPS;
- change database credentials;
- change/delete the demo admin account;
- add rate limiting and login lockout;
- validate upload/payment files if payment uploads are added;
- add stock deduction/restoration in a transaction;
- configure email/SMS notifications as needed;
- add audit logs for admin changes.


## v3 Excel and delivery-history features
The v3 package includes a dependency-free real `.xlsx` generator. It uses PHP's ZipArchive extension to create Office Open XML workbooks.

Admin export buttons:
- Orders → `Sissies_Orders_YYYY-MM-DD_HH-MM-SS.xlsx`
- Delivery History → `Sissies_Delivery_History_YYYY-MM-DD_HH-MM-SS.xlsx`
- Payments → `Sissies_Payments_YYYY-MM-DD_HH-MM-SS.xlsx`
- Purchase History → `Sissies_Purchase_History_YYYY-MM-DD_HH-MM-SS.xlsx`

Automatic delivery history:
- Order placed → Pending history row
- Admin changes status → history row
- Customer cancels → Cancelled history row
- Customer confirms TO RECEIVE → Received history row

The database remains the authoritative permanent record; Excel is an export/reporting format.
Make sure PHP's `zip`/`ZipArchive` extension is enabled. In XAMPP this is normally enabled; if not, enable `extension=zip` in php.ini and restart Apache.


## v4 Edit Order feature
- A customer can edit a checked-out order only while its status is `Pending`.
- `Edit Order` loads the existing order items into My Cart.
- The customer can add/remove products and change quantities, then return to Checkout.
- Checkout updates the existing order row and order items using the same Order ID/order number; it does not create a duplicate order.
- Courier and delivery details can be changed during the edit. Lalamove disables the J&T-only delivery fields; J&T requires them.
- Current product stock is revalidated before an edited order is saved.
- An `Order edited by customer` row is recorded in `delivery_history`.
- If an admin changes the order status while the customer is editing, the edit screen checks the order every second and stops the edit. The server also re-checks the status inside a transaction before saving.
- Edit Order is unavailable once the status is Approved, To Ship, To Receive, Delivered, Received, Cancelled, or Pending Cancel.


### Telegram username update
New registrations require a Telegram username. For an existing database, run: `ALTER TABLE users ADD COLUMN telegram_name VARCHAR(100) NOT NULL DEFAULT '' AFTER phone;`
