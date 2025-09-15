# WC One-Click Purge

**Version:** 1.0.0  
**Author:** Digova  
**License:** GPL-2.0+  

A minimal admin tool to **delete ALL WooCommerce orders** (HPOS-safe via WooCommerce CRUD) and **ALL buyer accounts** (`customer` and `subscriber` roles) **in batches** with a single click. Designed for resets, staging data scrubs, and pre-launch cleanups. **Always back up your database first.**

---

## 🚀 Features
- **HPOS-safe:** Uses WooCommerce CRUD (`wc_get_orders()` / `$order->delete(true)`), not direct SQL.
- **Batched deletion:** Defaults to **200** items per pass to avoid timeouts.
- **Buyer cleanup:** Removes users with roles `customer` and `subscriber`.
- **One click:** Runs from **Tools → One-Click WC Purge**.
- **Permissions:** Restricted to users with `manage_woocommerce`.
- **No UI dependencies:** No extra JS/CSS; works on locked-down admin themes.

---

## 📂 Installation
1. Create a folder:
