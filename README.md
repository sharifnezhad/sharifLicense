# sharifLicense

A license management and verification system for WordPress.

This plugin lets you create licenses for any software, plugin, theme, or WHMCS module and validate them by **name, domain, IP address, and expiry date** through a secure endpoint. Sensitive data (license key, domain, and IP) is stored encrypted with **AES-256-CBC**.

---

## ✨ Features

- Create, edit, and delete licenses from the WordPress admin panel
- Each domain is **unique**; a license can have **multiple IPs**, and each IP can be registered only once across the whole system
- Expiry date is entered and displayed in the **Jalali (Shamsi)** calendar (year/month/day pickers) and stored as **Gregorian**

---

## 📦 Installation

1. Copy the `sharif-license` folder into `wp-content/plugins/`.
2. In the WordPress dashboard, go to **Plugins** and **activate** **sharifLicense**.
3. On activation, the database tables are created and the encryption key and Secret Key are generated automatically.
4. If the endpoint does not work, go to **Settings → Permalinks** and save once to flush the rewrite rules.

> If you previously activated the plugin and the database schema has changed, **deactivate and reactivate** the plugin once.

---

## 🖥 Admin Panel

From the **sharifLicense** menu in the dashboard:

- The **Verify URL** and **Secret Key** are shown at the top with copy buttons.
- The "Add new license" form includes the following fields:

| Field | Description |
|-------|-------------|
| Name | A friendly label to identify the license (e.g. customer name) |
| License Key | The license key (ASCII only, no spaces or Persian characters) |
| Domain | Domain without `http://` or `www` — must be unique |
| IP Addresses | One or more IP addresses (IPv4/IPv6) |
| Expiry Date | Jalali date (year/month/day) |

- The **Edit** button on each row opens a modal so editing happens on the same page.

---

## 🔌 Verification Endpoint

```
POST /api/validate
```

**Headers:**

```
Content-Type: application/json
X-Secret-Key: <SECRET_KEY>
```

**Request body (JSON):**

```json
{
  "license_key": "XXXX-XXXX-XXXX",
  "domain": "example.com",
  "ip": "1.2.3.4"
}
```

**Sample responses:**

```json
{ "valid": true,  "message": "" }
{ "valid": false, "message": "License has expired" }
{ "valid": false, "message": "License is invalid" }
{ "valid": false, "message": "Unauthorized" }
```

> Response messages are returned from the language file, so the exact text depends on the active language (`lang/fa.php` ships by default in Persian).

**Example with curl:**

```bash
curl -X POST https://yoursite.com/api/validate \
  -H "Content-Type: application/json" \
  -H "X-Secret-Key: YOUR_SECRET" \
  -d '{"license_key":"XXX","domain":"example.com","ip":"1.2.3.4"}'
```

### Validation logic
1. Verify the `X-Secret-Key` header
2. Find the license by `license_key`
3. Match `domain` against the registered domain
4. Check that `ip` is in the license's allowed IP list
5. Ensure `expired_date` has not passed

---

## 🗂 Project Structure

```
sharif-license/
├── sharif-license.php      # Main plugin file (bootstrap)
├── helper.php              # Helpers: language + Jalali date conversion
├── lang/
│   └── fa.php              # All strings (errors, messages, labels)
├── Classes/
│   ├── Database.php        # Database operations and AES encryption
│   ├── RestApi.php         # /api/validate verification endpoint
│   └── Admin.php           # Admin panel (form, table, modal, AJAX)
└── assets/
    └── admin.css           # Admin panel styles
```

---

## 🗄 Database Schema

**`wp_licenses`**

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| name | TEXT | License name/label (plaintext) |
| license_key | TEXT | License key (AES encrypted) |
| domain | TEXT | Domain (AES encrypted) |
| domain_hash | VARCHAR(64) | SHA-256 hash of the domain for lookup & uniqueness |
| expired_date | DATE | Expiry date (Gregorian) |
| created_at | DATETIME | Creation timestamp |

**`wp_license_ips`**

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| license_id | INT | Related license ID |
| ip | TEXT | IP address (AES encrypted) |

---

## 🌐 Adding a New Language

Copy `lang/fa.php`, translate the strings (e.g. `lang/en.php`), and adjust the load path in `helper.php`.

---

## 👤 Author

**Amir Hossein Sharifnezhad** — [sharifdev.ir](https://sharifdev.ir)
