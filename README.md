# Anonymize Woocommerce
Adds WooCommerce tool to anonymize user and order data. 

⚠️ **Warning** 

This tool is highly destructive and entirely irreverisible without a full database backup. Do not use in production and proceed with extreme a caution. You _will_ wreck your site.

This tool will:

1. Run all the WooCommerce registered privacy erasers for every user.
2. Run the WooCommerce privacy eraser for every order.
3. Anonymize all your user emails, logins, names, etc.

This will _not_ anonymize other plugins' data.

### Requires
1. WooCommerce 9.0

### Usage

In your WordPress admin area, go to WooCommerce > Status > Tools. Click on "Start anonymizing".

![image](https://github.com/helgatheviking/anonymize-woocommerce/assets/507025/1e21bd01-1fbf-4c58-afb3-9acd79f8cdd6)
