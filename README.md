# Inventory and Order Management System (IOMS)

A complete, production-ready web application for managing inventory and sales, built with Native PHP and MySQL. The system offers a streamlined interface for tracking products, processing orders, and visualizing sales data.

---

## 🚀 Features

* **Secure Authentication**: Robust staff login system utilizing password hashing for enhanced security.
* **Interactive Dashboard**: Provides a visual overview with key metrics including total products, orders, sales volume, and stock levels.
* **Comprehensive Product Management**: Full CRUD (Create, Read, Update, Delete) operations, including support for product image uploads.
* **Efficient Order Processing**: Create orders containing multiple items with automatic stock level adjustments upon completion.
* **Modern User Interface**: A responsive design featuring gradients, smooth animations, and glassmorphism effects for a premium feel.
* **Real-time Search**: Dynamic filtering capability to instantly locate products within the inventory.
* **Stock Validation Logic**: Intelligent system that prevents order creation if quantities exceed available inventory.

---

## 🎨 Tech Stack

* **Backend**: Native PHP (No frameworks used, demonstrating core PHP capabilities).
* **Database**: MySQL with prepared statements for security and performance.
* **Frontend**: HTML5, CSS3, and Vanilla JavaScript for a lightweight and fast user experience.
* **Security Measures**:
    * Password hashing using `bcrypt`.
    * Prepared statements to prevent SQL injection.
    * XSS protection via `htmlspecialchars()`.
    * Secure session management.

---

## 📁 Project Structure

```text
/ioms
├── /assets
│   ├── style.css           # Modern styling with gradients & animations
│   └── script.js           # UI interactions & AJAX functionality
├── /includes
│   ├── db.php              # MySQL connection handler configuration
│   └── functions.php       # Reusable helper PHP functions
├── /database
│   └── schema.sql          # Database schema definition (4 tables)
├── /uploads                # Directory for storing product images
├── index.php               # Dashboard / Home page
├── login.php               # User Authentication page
├── products.php            # Product management (CRUD) page
├── orders.php              # Order processing and management page
└── get_order_details.php   # AJAX endpoint for fetching order data
```
| Dashboard | POS Terminal |
|:---:|:---:|
| ![Dashboard](screenshots/Dashboard.png) | ![POS](screenshots/orders.png) |

## 🗄️ Database Schema

The system relies on a strictly normalized database consisting of 4 tables:

* **Staff**: Manages user authentication details (`id`, `username`, `password`, `full_name`, `created_at`).
* **Product**: Stores inventory item information (`id`, `name`, `description`, `price`, `stock`, `image_path`, `timestamps`).
* **Order**: Records general order information (`id`, `order_date`, `total_amount`, `staff_id`, `created_at`).
* **OrderDetail**: Links products to orders with specific quantities (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`).

## ⚙️ Installation Guide

### Prerequisites
* PHP 7.4 or higher.
* MySQL 5.7 or higher.
* Web Server (Apache/Nginx).
* XAMPP or WAMP (Recommended for local development).

### Step 1: Clone/Copy Files
Place the project files into your web server's root directory:
* **XAMPP**: `C:\xampp\htdocs\ioms`
* **WAMP**: `C:\wamp64\www\ioms`

### Step 2: Database Setup
1. Open **phpMyAdmin** (usually at `http://localhost/phpmyadmin`).
2. Navigate to the **"Import"** tab.
3. Select the `database/schema.sql` file from the project folder.
4. Click **"Go"** to execute.
   * This creates the `ioms_db` database, tables, and populates them with sample data.

### Step 3: Configure Connection
Open `includes/db.php` and update the credentials if necessary:
```php
$host = 'localhost';
$username = 'root';      // Default XAMPP/WAMP username
$password = '';          // Default XAMPP/WAMP password (leave empty)
$database = 'ioms_db';
```

### Step 4: Create Uploads Directory
Ensure a folder named `uploads` exists in the root directory for images.
* **Windows**: Create the folder and ensure write permissions are enabled.
* **Linux/Mac**: Run `mkdir uploads` and `chmod 777 uploads`.

### Step 5: Launch
Open your browser and go to: `http://localhost/ioms/login.php`

## 🔐 Default Credentials

Use the following account to log in as an administrator:
* **Username**: `admin`
* **Password**: `admin123`

Additional test accounts:
* `john` / `admin123`
* `sarah` / `admin123`

## 📖 Usage Guide

### 📊 Dashboard
* Provides an immediate snapshot of business performance.
* Displays cards for **Total Products**, **Total Orders**, **Total Sales**, and **Current Stock**.

### 📦 Product Management
* Navigate to the **Products** page.
* **Add**: Use the "+ Add Product" button to input details and upload an image.
* **Edit/Delete**: Use the action buttons next to each product in the list.
* **Search**: Use the real-time search bar to filter the product list instantly.

### 🛒 Order Management
* Navigate to the **Orders** page.
* **Create Order**:
    1. Click "+ Create Order".
    2. Select a product and enter the quantity.
    3. Use "Add Another Product" for bulk orders.
    4. The total is calculated automatically.
    5. Submit to save the order and update stock.
* **View Details**: Inspect specific items within any past order.

## 🛡️ Security Features
* **Password Hashing**: Utilizes `password_hash()` with bcrypt algorithms.
* **SQL Injection Prevention**: All database queries use prepared statements.
* **XSS Protection**: Inputs are sanitized using `htmlspecialchars()`.
* **CSRF Protection**: Implemented via session validation.

## 🔧 Troubleshooting

| Issue | Solution |
|-------|----------|
| **Connection Failed** | Verify MySQL is running and credentials in `db.php` are correct. |
| **Image Upload Failed** | Check if `uploads/` folder exists and has write permissions. Ensure file is an image. |
| **Session Errors** | Clear browser cookies and ensure `session_start()` is active in PHP config. |

## 📄 License

This project is open-source and available for educational and commercial use.

---

**Built with ❤️ using Native PHP and MySQL**