# Inventory and Order Management System (IOMS)

A complete, production-ready web application for managing inventory and sales, built with Native PHP and MySQL.

## 🚀 Features

- **Secure Authentication** - Staff login system with password hashing
- **Dashboard** - Visual overview with key metrics (products, orders, sales, stock)
- **Product Management** - Full CRUD operations with image upload support
- **Order Processing** - Create orders with multiple items and automatic stock updates
- **Modern UI** - Responsive design with gradients, animations, and glassmorphism effects
- **Real-time Search** - Filter products dynamically
- **Stock Validation** - Prevents orders exceeding available inventory

## 📁 Project Structure

```
/New folder (5)
  ├── /assets
  │     ├── style.css       - Modern styling with gradients & animations
  │     └── script.js       - UI interactions & AJAX functionality
  ├── /includes
  │     ├── db.php          - MySQL connection handler
  │     └── functions.php   - Reusable PHP functions
  ├── /database
  │     └── schema.sql      - Database schema (4 tables)
  ├── /uploads              - Product images storage
  ├── index.php             - Dashboard
  ├── login.php             - Authentication
  ├── products.php          - Product CRUD
  ├── orders.php            - Order management
  └── get_order_details.php - AJAX endpoint
```

## 🗄️ Database Schema

The system uses exactly 4 tables:

1. **Staff** - User authentication (id, username, password, full_name, created_at)
2. **Product** - Inventory items (id, name, description, price, stock, image_path, timestamps)
3. **Order** - Order headers (id, order_date, total_amount, staff_id, created_at)
4. **OrderDetail** - Order line items (id, order_id, product_id, quantity, unit_price, subtotal)

## ⚙️ Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- XAMPP/WAMP (recommended for local development)

### Step 1: Clone/Copy Files
Copy all project files to your web server directory:
- XAMPP: `C:\xampp\htdocs\ioms`
- WAMP: `C:\wamp64\www\ioms`

### Step 2: Create Database
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Import the database schema:
   - Click "Import" tab
   - Choose `database/schema.sql`
   - Click "Go"

This will create:
- Database: `ioms_db`
- 4 tables with sample data
- Default admin user

### Step 3: Configure Database Connection
Edit `includes/db.php` if needed:
```php
$host = 'localhost';     // Usually localhost
$username = 'root';      // Your MySQL username
$password = '';          // Your MySQL password
$database = 'ioms_db';   // Database name
```

### Step 4: Create Uploads Directory
Create the `uploads` folder for product images:
```bash
mkdir uploads
chmod 777 uploads  # On Linux/Mac
```

On Windows, ensure the folder has write permissions.

### Step 5: Access the System
Open your browser and navigate to:
```
http://localhost/ioms/login.php
```

## 🔐 Default Credentials

**Username:** admin  
**Password:** admin123

Additional test accounts:
- john / admin123
- sarah / admin123

## 📖 Usage Guide

### Dashboard
- View key metrics: Total Products, Orders, Sales, Stock
- Quick access to Products and Orders sections

### Product Management
1. Click "Products" in navigation
2. **Add Product:** Click "+ Add Product" button
   - Fill in name, description, price, stock
   - Optionally upload an image
3. **Edit Product:** Click "Edit" button on any product
4. **Delete Product:** Click "Delete" with confirmation
5. **Search:** Use search box to filter products

### Order Management
1. Click "Orders" in navigation
2. **Create Order:** Click "+ Create Order"
   - Select product from dropdown
   - Enter quantity
   - Click "+ Add Another Product" for multiple items
   - View real-time total calculation
   - Click "Create Order"
3. **View Details:** Click "View Details" on any order
4. Stock automatically updates when order is created

## 🎨 Tech Stack

- **Backend:** Native PHP (no frameworks)
- **Database:** MySQL with prepared statements
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Security:** 
  - Password hashing with bcrypt
  - Prepared statements (SQL injection prevention)
  - XSS protection with `htmlspecialchars()`
  - Session management

## 🛡️ Security Features

- Passwords hashed using `password_hash()` with bcrypt
- All database queries use prepared statements
- Input sanitization on all user inputs
- Session-based authentication
- CSRF protection through session validation

## 🔧 Troubleshooting

### Issue: "Connection failed"
- Check MySQL service is running
- Verify database credentials in `includes/db.php`
- Ensure `ioms_db` database exists

### Issue: "Failed to upload image"
- Ensure `uploads/` folder exists
- Check folder has write permissions
- Verify file type is jpg, jpeg, png, or gif

### Issue: "Session errors"
- Clear browser cookies
- Check PHP session configuration
- Ensure `session_start()` is called

## 📝 Sample Data

The system comes with:
- 3 staff members (admin, john, sarah)
- 10 sample products (tech accessories)
- 3 sample orders with order details

## 🌐 Browser Compatibility

- Chrome (recommended)
- Firefox
- Edge
- Safari
- Opera

## 📱 Responsive Design

The UI is fully responsive and works on:
- Desktop (1200px+)
- Tablet (768px - 1199px)
- Mobile (< 768px)

## 📄 License

This project is open-source and available for educational and commercial use.

## 🆘 Support

For issues or questions, refer to the code comments or review the `functions.php` file for available helper functions.

---

**Built with ❤️ using Native PHP and MySQL**
