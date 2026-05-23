# 🛡️ MyShop Enterprise POS & Inventory System

![Dashboard Preview](docs/preview.png)

A high-performance, enterprise-grade Point of Sale (POS) and Inventory Management System engineered with native PHP and MySQL. Designed to deliver unbreakable stability, absolute data integrity, and blazing-fast performance for modern retail environments.

---

## 🏗️ Architectural Excellence

Unlike bloated SaaS applications or heavy framework-dependent systems, **MyShop** is built from the ground up using **Native PHP 8+**. This deliberate architectural choice guarantees:

- **Zero Overhead:** No unnecessary dependencies, resulting in lightning-fast page loads and immediate response times at the POS terminal.
- **Hardware Agnostic:** Runs flawlessly on any standard XAMPP, LAMP, or WAMP stack. It requires minimal server resources, making it perfect for both local deployment on low-end cash register PCs and cloud hosting.
- **ACID Compliant Transactions:** Every critical database operation (e.g., confirming a sale, adjusting stock) is wrapped in strict MySQL Transactions (`begin_transaction`, `commit`, `rollback`). If a network drop occurs mid-sale, the system instantly rolls back to prevent inventory corruption.

---

## 🔒 Zero-Trust Security Model

In retail systems, a single vulnerability can lead to financial ruin. MyShop implements a defense-in-depth security strategy:

- **Impenetrable SQL Protection:** 100% of database queries utilize **Prepared Statements** (`$stmt->bind_param`). Raw SQL concatenation is strictly forbidden, rendering SQL Injection impossible.
- **CSRF Eradication:** Every state-changing request (POST updates, GET deletions) is authenticated via cryptographically secure Anti-CSRF tokens.
- **Cross-Site Scripting (XSS) Armor:** All user inputs are sanitized upon entry (`sanitize_input`), and aggressively escaped (`htmlspecialchars`) upon rendering.
- **Role-Based Access Control (RBAC):** Backend endpoints verify permissions at the core level (`require_admin()`). A cashier attempting to forge a request to delete a product will be instantly rejected by the server, regardless of UI manipulation.
- **Native SQL Backups:** Built-in, secure database dumper accessible only to Administrators.

---

## 📠 Hardware Integration

### Native Barcode Scanner Support (HID)
The POS Terminal (`orders.php`) is engineered for high-volume checkouts. It natively intercepts Human Interface Device (HID) inputs from physical Barcode Scanners. 
- **Auto-Detection:** Scanners trigger the `Enter` key automatically. The JavaScript engine captures this, instantly searches the database, adds the product to the cart, plays an audio confirmation beep, and clears the input—all in under 50 milliseconds without requiring the cashier to touch the mouse.

---

## ⚙️ Core Modules

1. **POS Terminal:** Real-time cart calculation, dual-mode (Sales/Purchases), and hardware-accelerated barcode scanning.
2. **Analytical Dashboard:** Live metrics, 7-day revenue visualization (Chart.js), and dynamic low-stock alerts.
3. **Inventory & Stock Ledger:** Complete CRUD capabilities with a strict, immutable Stock Ledger. Every unit added, sold, or manually adjusted is permanently logged with timestamps and user IDs.
4. **Stakeholder Management:** Maintain comprehensive databases for both Customers and Suppliers, linking them directly to corresponding financial transactions.
5. **Invoice Generation:** Instant PDF invoice rendering via `html2pdf.js`, providing professional receipts for customers.

---

## 🚀 Deployment Guide

Deploying MyShop takes less than 60 seconds.

```bash
# 1. Clone the repository
git clone https://github.com/mohammad-emad-dev/myshop-PHP-System.git

# 2. Move to your Apache/Nginx web root (e.g., htdocs or /var/www/html)
cp -r myshop-PHP-System /path/to/htdocs/myshop

# 3. Import the database schema
mysql -u root -p < database/schema.sql

# 4. Access the system
http://localhost/myshop/public/login.php
```

**Default Administrator Credentials:**
- **Username:** `admin`
- **Password:** `admin123`

*(Note: Ensure you change this password immediately upon first login via the Settings panel).*

---

## 💻 Technology Stack

| Component | Technology |
|-----------|------------|
| **Core Engine** | PHP 8+ (Native / Framework-less) |
| **Database** | MySQL / MariaDB (InnoDB for ACID compliance) |
| **Frontend Framework** | Bootstrap 5, Vanilla CSS for custom micro-animations |
| **Data Visualization** | Chart.js |
| **User Experience** | SweetAlert2 (Non-blocking alerts) |
| **Export/Reporting** | html2pdf.js, Native CSV generators |

---

## 📄 License
Released under the MIT License. Built for scalability, speed, and security.

---
**Architected by Mohammad Emad**
[![LinkedIn](https://img.shields.io/badge/LinkedIn-Connect-blue?style=for-the-badge&logo=linkedin)](https://www.linkedin.com/in/%E2%80%AAmohammad-emad%E2%80%AC%E2%80%8F-61532b160)