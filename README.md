# Elgentos - Remove Duplicate Product images in Magento 2

This Extension allows you to find duplicate product images from your product list and from this list you can easily remove them by running a command.

## Installation

1) Go to your Magento root folder
2) Download the extension using composer:
    ```
    composer require elgentos/magento2-product-duplicate-images-remove
    ```
3) Run setup commands:

    ```
    php bin/magento setup:upgrade
    ```
   
4) Run the command:

   ```
   php bin/magento duplicate:remove
   ```
Eg:
```sh
# Use unlink
php bin/magento duplicate:remove -u1

# Turn off dry run
php bin/magento duplicate:remove -d0

# Combine unlink and turn off dry run
php bin/magento duplicate:remove -u1 -d0

# Specific on some sku
php bin/magento duplicate:remove -u1 -d0 SKU1 SKU2 SKU3
