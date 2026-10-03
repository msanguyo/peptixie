# PEPTIXIE v4 — InfinityFree Deployment

## 1. Create the free hosting account
Create an InfinityFree account and a free subdomain.

## 2. Create the MySQL database
In the hosting Control Panel, create a MySQL database and note:
- MySQL Host
- Database Name
- Database Username
- Database Password

## 3. Import the database
Open phpMyAdmin, select the database you created, then import:

`database/sissies_shop_infinityfree.sql`

Use this file for InfinityFree because it does not contain `CREATE DATABASE` or `USE` statements.

## 4. Upload the website
Upload the contents of the `sissies_shop` folder into the site's `htdocs` directory using FileZilla or the hosting File Manager.

## 5. Configure MySQL
Edit `config/database.php` and replace the local XAMPP values with your InfinityFree MySQL host, database name, username, and password.

Example only:

```php
$host = 'sqlXXX.infinityfree.com';
$db   = 'epiz_12345678_sissies_shop';
$user = 'epiz_12345678';
$pass = 'YOUR_DATABASE_PASSWORD';
```

Do not use these example values.

## 6. Test
Open the HTTPS free subdomain and test:
- registration/login
- payment approval
- Order List/My Cart
- checkout
- Edit Order for Pending orders
- admin status changes
- 1-second customer status synchronization
- delivery history
- Excel exports

## Edit Order behavior in v4
A Pending order can be edited from My Purchase. The existing order items are loaded into My Cart. The customer can add/remove products or change quantities, then Checkout updates the same order ID/order number. No duplicate order is created. An `Order edited by customer` delivery-history row is recorded.

If an admin changes the order status while the customer is editing, the browser checks the status every second and stops the edit. The server also locks and rechecks the order status inside the update transaction.
